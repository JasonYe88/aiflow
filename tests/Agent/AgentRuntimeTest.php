<?php
declare(strict_types=1);

namespace AIFlow\Tests\Agent;

use AIFlow\Access\AccessService;
use AIFlow\Access\FixedUserProvider;
use AIFlow\Adapter\PdoStorage;
use AIFlow\Agent\AgentResult;
use AIFlow\Agent\ToolInterface;
use AIFlow\Exception\AgentRoundExhaustedException;
use AIFlow\Exception\PermissionDeniedException;
use AIFlow\Gateway\ModelGateway;
use AIFlow\Support\Migrator;
use AIFlow\Support\ModelResult;
use AIFlow\Tests\Support\ScriptedProvider;
use PHPUnit\Framework\TestCase;

/**
 * 测试用工具：echo 出 args，返回可控文本；
 * $executed 记录每次调用参数供断言，$boom 非空时抛异常
 */
class FakeTool implements ToolInterface
{
    public $executed = [];
    public $boom = '';
    public $reply = 'ok';

    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return '查询指定城市的实时天气';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string', 'description' => '城市名，如 北京']],
            'required' => ['city'],
        ];
    }

    public function execute(array $args)
    {
        $this->executed[] = $args;
        if ($this->boom !== '') {
            throw new \RuntimeException($this->boom);
        }
        return $this->reply;
    }
}

class AgentRuntimeTest extends TestCase
{
    /** @var ScriptedProvider */
    private $provider;

    /** @var \AIFlow\Agent\AgentRuntime */
    private $runtime;

    protected function setUp(): void
    {
        $gateway = new ModelGateway();
        $this->provider = new ScriptedProvider();
        $gateway->add('fake', $this->provider);
        $this->runtime = new \AIFlow\Agent\AgentRuntime($gateway);
    }

    /** 造一帧"模型要求调用工具"的结果 */
    private function toolCallResult(string $tool, string $argsJson, string $callId = 'call_x', int $in = 10, int $out = 5): ModelResult
    {
        return new ModelResult('', 'fake-model', $in, $out, 2, [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => $callId,
                        'type' => 'function',
                        'function' => ['name' => $tool, 'arguments' => $argsJson],
                    ]],
                ],
            ]],
        ]);
    }

    /** 造一帧最终回答 */
    private function finalResult(string $content, int $in = 20, int $out = 8): ModelResult
    {
        return new ModelResult($content, 'fake-model', $in, $out, 2, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ]);
    }

    /** 本轮 Guardian（真实 AccessService + SQLite，u1 挂 user 角色） */
    private function makeGuard(): AccessService
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        return new AccessService($storage, new FixedUserProvider(['u1' => ['user']]));
    }

    /** 无工具注册：单轮直答，options 不含 tools */
    public function testNoToolsSingleRoundAnswer(): void
    {
        $this->provider->script($this->finalResult('你好呀', 12, 6));

        $out = $this->runtime->run('fake', [['role' => 'user', 'content' => '你好']]);

        $this->assertSame('你好呀', $out->content);
        $this->assertSame(1, $out->rounds);
        $this->assertSame(12, $out->inputTokens);
        $this->assertSame(6, $out->outputTokens);
        $this->assertSame([], $out->steps);
        $this->assertCount(1, $this->provider->calls);
        $this->assertArrayNotHasKey('tools', $this->provider->calls[0]['options']);
    }

    /** 标准循环：模型要工具 → 执行 → 结果回填 → 模型给终答；token 与轨迹累计 */
    public function testToolLoopUntilFinalAnswer(): void
    {
        $tool = new FakeTool();
        $tool->reply = '晴，22 度';
        $this->runtime->register($tool);
        $this->provider->script($this->toolCallResult('get_weather', '{"city":"北京"}', 'call_1', 10, 5));
        $this->provider->script($this->finalResult('北京今天晴，22 度', 25, 8));

        $out = $this->runtime->run('fake', [['role' => 'user', 'content' => '北京天气如何']]);

        $this->assertSame('北京今天晴，22 度', $out->content);
        $this->assertSame(2, $out->rounds);
        $this->assertSame(35, $out->inputTokens);   // 10 + 25
        $this->assertSame(13, $out->outputTokens);  // 5 + 8
        $this->assertCount(1, $tool->executed);
        $this->assertSame(['city' => '北京'], $tool->executed[0]);

        // 轨迹：一步工具调用，ok=true
        $this->assertCount(1, $out->steps);
        $this->assertSame('get_weather', $out->steps[0]['tool']);
        $this->assertSame(['city' => '北京'], $out->steps[0]['args']);
        $this->assertSame('晴，22 度', $out->steps[0]['output']);
        $this->assertTrue($out->steps[0]['ok']);

        // 第二轮喂给模型的消息结构：assistant(含 tool_calls) + role=tool(带 tool_call_id 与结果)
        $second = $this->provider->calls[1]['messages'];
        $this->assertCount(3, $second);
        $this->assertSame('assistant', $second[1]['role']);
        $this->assertSame('call_1', $second[1]['tool_calls'][0]['id']);
        $this->assertSame('tool', $second[2]['role']);
        $this->assertSame('call_1', $second[2]['tool_call_id']);
        $this->assertSame('晴，22 度', $second[2]['content']);

        // 工具 schema 正确喂给模型（name/description/parameters 来自 ToolInterface）
        $fed = $this->provider->calls[0]['options']['tools'];
        $this->assertSame('function', $fed[0]['type']);
        $this->assertSame('get_weather', $fed[0]['function']['name']);
        $this->assertSame('查询指定城市的实时天气', $fed[0]['function']['description']);
        $this->assertSame($tool->schema(), $fed[0]['function']['parameters']);
    }

    /** 一轮内多个 tool_calls：全部执行、逐个回填、下轮一起带上 */
    public function testMultipleToolCallsInOneRound(): void
    {
        $tool = new FakeTool();
        $this->runtime->register($tool);
        $this->provider->script(new ModelResult('', 'fake-model', 5, 5, 2, [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}']],
                        ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"上海"}']],
                    ],
                ],
            ]],
        ]));
        $this->provider->script($this->finalResult('两城天气已查'));

        $out = $this->runtime->run('fake', [['role' => 'user', 'content' => '北京和上海天气']]);

        $this->assertSame('两城天气已查', $out->content);
        $this->assertCount(2, $tool->executed);
        $this->assertCount(2, $out->steps);
        $this->assertSame('北京', $out->steps[0]['args']['city']);
        $this->assertSame('上海', $out->steps[1]['args']['city']);

        $second = $this->provider->calls[1]['messages'];
        $this->assertCount(4, $second); // user + assistant + tool + tool
        $this->assertSame('c1', $second[2]['tool_call_id']);
        $this->assertSame('c2', $second[3]['tool_call_id']);
    }

    /** 模型 hallucination 调了未注册工具：错误性结果回填，不炸 run，模型自纠后终答 */
    public function testUnknownToolFeedsErrorBackAndRecovers(): void
    {
        $this->provider->script($this->toolCallResult('no_such_tool', '{}'));
        $this->provider->script($this->finalResult('我换个方式回答'));

        $out = $this->runtime->run('fake', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('我换个方式回答', $out->content);
        $this->assertCount(1, $out->steps);
        $this->assertFalse($out->steps[0]['ok']);
        $this->assertStringContainsString('no_such_tool', $out->steps[0]['output']);
        $this->assertStringContainsString('工具执行失败', $this->provider->calls[1]['messages'][2]['content']);
    }

    /** 工具自身抛异常：转为错误性 tool 结果回填（不炸 run），steps 记录 ok=false */
    public function testToolExceptionFeedsErrorBack(): void
    {
        $tool = new FakeTool();
        $tool->boom = '第三方接口超时';
        $this->runtime->register($tool);
        $this->provider->script($this->toolCallResult('get_weather', '{"city":"北京"}'));
        $this->provider->script($this->finalResult('天气服务暂时不可用，建议稍后再试'));

        $out = $this->runtime->run('fake', [['role' => 'user', 'content' => '北京天气']]);

        $this->assertSame('天气服务暂时不可用，建议稍后再试', $out->content);
        $this->assertFalse($out->steps[0]['ok']);
        $this->assertStringContainsString('第三方接口超时', $out->steps[0]['output']);
        // 异常未逃逸到 run 外层
        $toolResult = $this->provider->calls[1]['messages'][2]['content'];
        $this->assertStringContainsString('第三方接口超时', $toolResult);
    }

    /** arguments 非法 JSON：容错为空参数，不炸循环 */
    public function testInvalidArgumentsJsonTreatedAsEmpty(): void
    {
        $tool = new FakeTool();
        $this->runtime->register($tool);
        $this->provider->script($this->toolCallResult('get_weather', '{"city":"北京"'));
        $this->provider->script($this->finalResult('done'));

        $out = $this->runtime->run('fake', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('done', $out->content);
        $this->assertSame([], $tool->executed[0]);
    }

    /** 最大轮数保护：模型反复要工具时按 max_rounds 截断并抛 AgentRoundExhaustedException */
    public function testMaxRoundsExhausted(): void
    {
        $this->runtime->register(new FakeTool());
        // 脚本只有 tool_call，ScriptedProvider 会重复最后一帧 → 无限要工具
        $this->provider->script($this->toolCallResult('get_weather', '{"city":"北京"}'));

        try {
            $this->runtime->run('fake', [['role' => 'user', 'content' => 'hi']], ['max_rounds' => 3]);
            $this->fail('应抛 AgentRoundExhaustedException');
        } catch (AgentRoundExhaustedException $e) {
            $this->assertSame(3, $e->rounds);
            $this->assertSame(3, $e->toolCalls);
        }
        $this->assertCount(3, $this->provider->calls);
    }

    /** 权限门（deny）：工具被拒 → PermissionDeniedException 终止 run，工具未执行 */
    public function testGuardDeniesToolAndAborts(): void
    {
        $tool = new FakeTool();
        $this->runtime->register($tool);
        $guard = $this->makeGuard();
        $guard->grant('user', 'tool', 'get_weather', 'deny');
        $this->runtime->setGuard($guard, 'u1');

        $this->provider->script($this->toolCallResult('get_weather', '{"city":"北京"}'));
        $this->provider->script($this->finalResult('never'));

        try {
            $this->runtime->run('fake', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('应抛 PermissionDeniedException');
        } catch (PermissionDeniedException $e) {
            $this->assertSame('u1', $e->userId);
            $this->assertSame('tool:get_weather', $e->resource);
        }
        $this->assertSame([], $tool->executed); // 工具零执行
    }

    /** 权限门（allow）：放行并正常完成循环 */
    public function testGuardAllowsTool(): void
    {
        $tool = new FakeTool();
        $this->runtime->register($tool);
        $guard = $this->makeGuard();
        $guard->grant('user', 'tool', 'get_weather', 'allow');
        $this->runtime->setGuard($guard, 'u1');

        $this->provider->script($this->toolCallResult('get_weather', '{"city":"北京"}'));
        $this->provider->script($this->finalResult('ok'));

        $out = $this->runtime->run('fake', [['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('ok', $out->content);
        $this->assertCount(1, $tool->executed);
    }
}