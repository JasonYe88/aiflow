<?php
declare(strict_types=1);

namespace AIFlow\Agent;

use AIFlow\Access\AccessService;
use AIFlow\Exception\AgentRoundExhaustedException;
use AIFlow\Exception\PermissionDeniedException;
use AIFlow\Gateway\ModelGateway;

/**
 * 单 Agent 运行时：工具注册 + function-calling 循环
 *
 * 循环契约（OpenAI 协议）：
 *   每轮把注册工具的 name/description/schema 以 tools= 喂给模型 →
 *   模型返回 tool_calls →（可选权限门 tool:{name}）执行 →
 *   assistant(含 tool_calls) 与逐个 role=tool 结果回填 messages → 再问模型 →
 *   直至模型给出纯文本回答（或抛 AgentRoundExhaustedException 防死循环）。
 *
 * 容错分级：
 *   - 工具不存在 / 工具执行抛异常 → 错误性 tool 结果回填（模型可自纠），不炸 run；
 *   - 工具被权限门拒绝（setGuard 注入后）→ PermissionDeniedException 直接终止 run（fail fast）；
 *   - 达到 max_rounds → AgentRoundExhaustedException。
 *
 * 计费：run() 返回 AgentResult（与 ModelResult 鸭子兼容），宿主可整体丢进 Billing::run 计费。
 *
 * @see ToolInterface 工具契约
 */
final class AgentRuntime
{
    /** 默认最大轮数；模型反复要工具时按此截断防死循环 */
    const DEFAULT_MAX_ROUNDS = 8;

    /** @var ModelGateway */
    private $gateway;

    /** @var ToolInterface[] name => tool */
    private $tools = [];

    /** @var AccessService|null 工具级权限门（null = 不校验） */
    private $guard;

    /** @var mixed 权限判定使用的用户 ID */
    private $userId = null;

    public function __construct(ModelGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** 注册工具（同名覆盖——插件热更新语义） */
    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** @return ToolInterface|null 已注册工具；未注册返回 null */
    public function tool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return string[] 已注册工具名 */
    public function toolNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * 注入工具级权限门：传入 null 可解除
     * 判定资源为 "tool:{name}"，宿主须先在 RBAC 里对工具角色授权（deny 优先同全局语义）
     *
     * @param AccessService|null $guard
     * @param mixed $userId
     */
    public function setGuard(AccessService $guard = null, $userId = null): void
    {
        $this->guard = $guard;
        $this->userId = $userId ?? $this->userId;
    }

    /**
     * 执行一次 Agent 对话
     *
     * @param string $channel  模型网关渠道名
     * @param array  $messages 初始对话（OpenAI 消息格式）
     * @param array  $options  {max_rounds?: int, tool_choice?: mixed 透传}
     * @return AgentResult
     * @throws PermissionDeniedException          工具被权限门拒绝（工具未执行）
     * @throws AgentRoundExhaustedException       达到最大轮数仍未得到最终回答
     * @throws \AIFlow\Exception\ModelCallException 模型调用失败（原样透传）
     */
    public function run(string $channel, array $messages, array $options = []): AgentResult
    {
        $maxRounds = max(1, (int)($options['max_rounds'] ?? self::DEFAULT_MAX_ROUNDS));
        $toolChoice = isset($options['tool_choice']) ? $options['tool_choice'] : null;
        $toolsPayload = $this->toolsPayload();

        $steps = [];
        $inTok = 0;
        $outTok = 0;
        $model = '';
        $toolCallsTotal = 0;
        $start = microtime(true);

        for ($round = 1; $round <= $maxRounds; $round++) {
            $chatOpts = [];
            if ($toolsPayload !== []) {
                $chatOpts['tools'] = $toolsPayload;
                if ($toolChoice !== null) {
                    $chatOpts['tool_choice'] = $toolChoice;
                }
            }

            $result = $this->gateway->chat($channel, $messages, $chatOpts);
            $inTok += (int)$result->inputTokens;
            $outTok += (int)$result->outputTokens;
            $model = (string)$result->model;

            $message = is_array($result->raw['choices'][0]['message'] ?? null)
                ? $result->raw['choices'][0]['message']
                : [];
            $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

            if ($toolCalls === []) {
                // 纯文本回答：循环到此为止
                return new AgentResult(
                    (string)$result->content,
                    $model,
                    $inTok,
                    $outTok,
                    (int)max(1, round((microtime(true) - $start) * 1000)),
                    $round,
                    $steps
                );
            }

            // assistant 轮（含 tool_calls）回填会话
            $messages[] = [
                'role' => 'assistant',
                'content' => (string)($message['content'] ?? ''),
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $call) {
                $toolCallsTotal++;
                $name = (string)($call['function']['name'] ?? '');
                $args = json_decode((string)($call['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = []; // 非法 JSON 容错：空参数交给工具自行分辨
                }
                $callId = (string)($call['id'] ?? '');

                $ok = false;
                try {
                    if ($this->guard !== null) {
                        $this->guard->guard($this->userId, 'tool:' . $name);
                    }
                    $tool = $this->tool($name);
                    if ($tool === null) {
                        throw new \RuntimeException("未注册的工具：{$name}");
                    }
                    $out = $tool->execute($args);
                    $outText = is_string($out) ? $out : json_encode($out, JSON_UNESCAPED_UNICODE);
                    if ($outText === false || $outText === null) {
                        throw new \RuntimeException('工具结果不可序列化');
                    }
                    $ok = true;
                } catch (PermissionDeniedException $e) {
                    throw $e; // 特权语义 fail fast：不喂给模型，直接终止
                } catch (\Throwable $e) {
                    // 工具失败/不存在：错误性结果回填，模型可自纠（hallucination 容错）
                    $outText = '工具执行失败：' . $e->getMessage();
                }

                $steps[] = ['round' => $round, 'tool' => $name, 'args' => $args, 'output' => $outText, 'ok' => $ok];
                $messages[] = ['role' => 'tool', 'tool_call_id' => $callId, 'content' => $outText];
            }
        }

        throw new AgentRoundExhaustedException(
            "Agent 已达最大轮数 {$maxRounds} 仍未得到最终回答（工具调用 {$toolCallsTotal} 次）",
            $maxRounds,
            $toolCallsTotal
        );
    }

    /** @return array OpenAI tools 载荷：注册工具的 name/description/schema */
    private function toolsPayload(): array
    {
        $payload = [];
        foreach ($this->tools as $name => $tool) {
            $payload[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $tool->description(),
                    'parameters' => $tool->schema(),
                ],
            ];
        }
        return $payload;
    }
}