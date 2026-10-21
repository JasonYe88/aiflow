<?php
declare(strict_types=1);

namespace AIFlow\Workflow;

use AIFlow\Contract\StorageInterface;
use AIFlow\Exception\NodeRetryExhaustedException;
use AIFlow\Exception\WorkflowDefinitionException;
use AIFlow\Gateway\ModelGateway;
use AIFlow\Support\Id;

/**
 * 工作流引擎：调度四种内置节点，全链路落库（ai_workflow_run + ai_workflow_run_log）
 *
 * 同步：$engine->run(new ProfitSkuSyncWorkflow(), $params, '业务单号');
 * 异步：$engine->dispatch(...) 入队（ai_workflow_queue 存储表队列），
 *       宿主 cron / 请求尾巴调 $engine->consume() 消费（原子抢占，多进程安全）；
 *       Redis 队列后续以 QueueInterface 可插拔替换（设计决策 2026-10-21）
 *
 * 数据流：context 为 key-value 数组贯穿全程；task 返回增量产物 array_merge 合入；
 *         decision 按 context 字段值跳转（含 skipped 日记）；llm 回复写入 context[节点key]；
 *         parallel 各分支拿主上下文副本隔离执行，产出（新增/变更键）聚合到 context[节点key][分支名]
 */
final class WorkflowEngine
{
    /** @var StorageInterface */
    private $storage;

    /** @var ModelGateway|null llm 节点用；无网关时 run() 遇 llm 节点失败落库后抛错 */
    private $gateway;

    /** @var string|null 最近一次 run() 的 run_id（run 行插入即记；consume 失败路径借它回填队列行） */
    private $lastRunId;

    public function __construct(StorageInterface $storage, ?ModelGateway $gateway = null)
    {
        $this->storage = $storage;
        $this->gateway = $gateway;
    }

    // ================= 同步运行 =================

    /**
     * 同步执行工作流，返回运行结果（行/日志均已落库）
     *
     * @param Workflow $wf 工作流实例（nodes() 启动时编译校验，fail fast）
     * @param array $input 初始上下文
     * @param string|null $refId 宿主业务单号（幂等/对账锚点，透传落库）
     * @throws WorkflowDefinitionException 定义非法
     * @throws NodeRetryExhaustedException task 重试耗尽（run 行已置 failed）
     * @throws \Throwable 其他运行期异常（gateway 不可用等，run 行已置 failed 后透传）
     */
    public function run(Workflow $wf, array $input = [], ?string $refId = null): WorkflowRunResult
    {
        $nodes = $this->compile($wf->nodes());
        $runId = Id::gen();
        $workflowId = $wf->id();

        $this->storage->execute(
            'INSERT INTO ai_workflow_run (id, workflow_id, status, context, ref_id, started_at) VALUES (?,?,?,?,?,?)',
            [$runId, $workflowId, 'running', $this->json($input), $refId, $this->now()]
        );
        $this->lastRunId = $runId;

        $context = $input;
        try {
            $i = 0;
            $total = count($nodes);
            while ($i < $total) {
                $node = $nodes[$i];
                switch ($node->type()) {
                    case Node::TYPE_TASK:
                        $context = $this->execTask($runId, $node, $i, $context);
                        $i++;
                        break;
                    case Node::TYPE_DECISION:
                        $target = $this->execDecision($runId, $node, $i, $context, $nodes);
                        if ($target === -1) { // 跳 END：剩余节点全部记 skipped
                            $this->markSkipped($runId, $nodes, $i + 1, $total);
                            $i = $total;
                            break;
                        }
                        $this->markSkipped($runId, $nodes, $i + 1, $target);
                        $i = $target;
                        break;
                    case Node::TYPE_LLM:
                        $context = $this->execLlm($runId, $node, $i, $context);
                        $i++;
                        break;
                    case Node::TYPE_PARALLEL:
                        $context = $this->execParallel($runId, $node, $i, $context);
                        $i++;
                        break;
                    default:
                        throw new \LogicException('未知节点类型：' . $node->type());
                }
            }
        } catch (\Throwable $e) {
            $this->storage->execute(
                'UPDATE ai_workflow_run SET status = ?, context = ?, finished_at = ? WHERE id = ?',
                ['failed', $this->json($context), $this->now(), $runId]
            );
            throw $e;
        }

        $this->storage->execute(
            'UPDATE ai_workflow_run SET status = ?, context = ?, finished_at = ? WHERE id = ?',
            ['success', $this->json($context), $this->now(), $runId]
        );
        return new WorkflowRunResult($runId, $workflowId, 'success', $context);
    }

    // ================= 异步：存储表队列 =================

    /**
     * 异步分发：仅入队返回队列 id，不执行；由 consume() 驱动
     * 注意：入队的是工作流类名，consume 时须能 autoload 到该类
     *
     * @return string ai_workflow_queue.id
     */
    public function dispatch(Workflow $wf, array $input = [], ?string $refId = null): string
    {
        $id = Id::gen();
        $this->storage->execute(
            'INSERT INTO ai_workflow_queue (id, workflow_class, status, input, ref_id, created_at) VALUES (?,?,?,?,?,?)',
            [$id, get_class($wf), 'queued', $this->json($input), $refId, $this->now()]
        );
        return $id;
    }

    /**
     * 消费队列：逐条原子抢占（status=queued → running，多进程安全）后同步执行，
     * 结束置 done / failed 并回写 run_id 与 error；坏行（类不存在）记 failed 不中断循环
     *
     * @param int $limit 最多处理条数
     * @return int 实际处理条数
     */
    public function consume(int $limit = 1): int
    {
        $rows = $this->storage->query(
            'SELECT id, workflow_class, input, ref_id FROM ai_workflow_queue WHERE status = ? ORDER BY id ASC LIMIT ?',
            ['queued', $limit]
        );
        $processed = 0;
        foreach ($rows as $row) {
            if (!$this->claim($row['id'])) {
                continue; // 已被其他进程抢占
            }
            $processed++;
            $this->lastRunId = null; // compile 失败（无 run 行）时保持 null
            try {
                $class = (string)$row['workflow_class'];
                if (!class_exists($class) || !is_subclass_of($class, Workflow::class)) {
                    throw new \RuntimeException("队列行 {$row['id']} 的工作流类不可用：{$class}");
                }
                $wf = new $class();
                $result = $this->run($wf, (array)json_decode((string)$row['input'], true), $row['ref_id']);
                $this->finishQueued($row['id'], 'done', (string)$result->runId, null);
            } catch (\Throwable $e) {
                // lastRunId：run() 已建行（运行期失败）则回填链路；编译期失败则留空
                $this->finishQueued($row['id'], 'failed', $this->lastRunId, $e->getMessage());
            }
        }
        return $processed;
    }

    /** 查询单次运行行 */
    public function runRow(string $runId): ?array
    {
        $rows = $this->storage->query('SELECT * FROM ai_workflow_run WHERE id = ?', [$runId]);
        return $rows[0] ?? null;
    }

    /** 查询某次运行的节点日志（Id::gen 单调，id ASC 即写入序） */
    public function logs(string $runId): array
    {
        return $this->storage->query('SELECT * FROM ai_workflow_run_log WHERE run_id = ? ORDER BY id ASC', [$runId]);
    }

    // ================= 内部：编译与节点执行 =================

    /**
     * 编译期校验（fail fast，落库前）：非空、key 唯一、决策目标存在、task 类实现契约
     *
     * @param Node[] $nodes
     * @return Node[] 原样返回（通过校验）
     */
    private function compile(array $nodes): array
    {
        if ($nodes === []) {
            throw new WorkflowDefinitionException('工作流至少需要一个节点');
        }
        $keys = [];
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                throw new WorkflowDefinitionException('nodes() 必须返回 Node 对象数组');
            }
            if (isset($keys[$node->key()])) {
                throw new WorkflowDefinitionException("节点 key 重复：{$node->key()}");
            }
            $keys[$node->key()] = true;
        }
        foreach ($nodes as $node) {
            if ($node->type() === Node::TYPE_DECISION) {
                foreach ($node->branchMap() as $target) {
                    if ($target !== Node::END && !isset($keys[$target])) {
                        throw new WorkflowDefinitionException(
                            "decision 节点 {$node->key()} 的跳转目标不存在：{$target}"
                        );
                    }
                }
            }
            if ($node->type() === Node::TYPE_TASK) {
                $class = $node->taskClass();
                if (!class_exists($class) || !is_a($class, TaskInterface::class, true)) {
                    throw new WorkflowDefinitionException(
                        "task 节点 {$node->key()} 的类未实现 TaskInterface：{$class}"
                    );
                }
            }
            if ($node->type() === Node::TYPE_PARALLEL && $node->branches() === []) {
                throw new WorkflowDefinitionException("parallel 节点 {$node->key()} 分支为空");
            }
        }
        return $nodes;
    }

    /** task 节点：retries 次内重试；耗尽记 failed 日志后抛 NodeRetryExhaustedException */
    private function execTask(string $runId, Node $node, int $index, array $context): array
    {
        $class = $node->taskClass();
        $attempt = 0;
        $last = null;
        while ($attempt <= $node->retries()) {
            try {
                /** @var TaskInterface $task */
                $task = new $class();
                $out = (array)$task->execute($context);
                $logOut = $out;
                if ($attempt > 0) {
                    $logOut['_retries'] = $attempt; // 仅入日志，绝不污染业务上下文
                }
                $this->logNode($runId, $node, $index, 'success', $context, $logOut);
                return array_merge($context, $out);
            } catch (\Throwable $e) {
                $last = $e;
                $attempt++;
            }
        }
        $this->logNode($runId, $node, $index, 'failed', $context, ['error' => $last ? $last->getMessage() : '']);
        throw new NodeRetryExhaustedException(
            sprintf('task 节点 %s 重试 %d 次后仍失败：%s', $node->key(), $node->retries(), $last ? $last->getMessage() : ''),
            $node->key(),
            $node->retries(),
            $last
        );
    }

    /** @return int 目标节点下标；END 返回 -1 */
    private function execDecision(string $runId, Node $node, int $index, array $context, array $nodes): int
    {
        $raw = $context[$node->field()] ?? null;
        $normalized = $this->normalizeDecisionValue($raw);
        $target = $node->branchMap()[$normalized] ?? null;
        if ($target === null) {
            $nextNode = $nodes[$index + 1] ?? null;
            $this->logNode($runId, $node, $index, 'success', $context, [
                'decision' => $raw,
                'next' => $nextNode === null ? Node::END : 'next:' . $nextNode->key(),
            ]);
            return $index + 1; // 未命中 → 顺序下一节点
        }
        $this->logNode($runId, $node, $index, 'success', $context, [
            'decision' => $raw,
            'next' => $target,
        ]);
        if ($target === Node::END) {
            return -1;
        }
        foreach ($nodes as $i => $n) {
            if ($n->key() === $target) {
                return $i;
            }
        }
        throw new \LogicException("决策目标已通过编译校验却未找到：{$target}"); // 不可达
    }

    /** llm 节点：prompt 构造器支持 string（单条 user message）或完整 OpenAI messages 数组 */
    private function execLlm(string $runId, Node $node, int $index, array $context): array
    {
        if ($this->gateway === null) {
            throw new \RuntimeException("llm 节点 {$node->key()} 需要模型网关，WorkflowEngine 构造时未装配（AIKernel::workflow() 已默认装配）");
        }
        $prompt = call_user_func($node->prompt(), $context);
        $messages = is_array($prompt)
            ? $prompt
            : [['role' => 'user', 'content' => (string)$prompt]];
        $result = $this->gateway->chat($node->channel(), $messages);
        $this->logNode($runId, $node, $index, 'success', $context, [
            'content' => $result->content,
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'elapsed_ms' => $result->elapsedMs,
        ]);
        return array_merge($context, [$node->key() => $result->content]);
    }

    /** parallel 节点：分支隔离执行，聚合各分支产物（新增/变更键）到 context[节点key] */
    private function execParallel(string $runId, Node $node, int $index, array $context): array
    {
        $aggregate = [];
        foreach ($node->branches() as $name => $spec) {
            $branchCtx = $context; // 隔离副本：分支间互不可见
            $classes = is_array($spec) ? $spec : [$spec];
            foreach ($classes as $class) {
                if (!is_string($class) || !is_a($class, TaskInterface::class, true)) {
                    throw new WorkflowDefinitionException("parallel 节点 {$node->key()} 分支 {$name} 含未实现 TaskInterface 的类");
                }
                /** @var TaskInterface $task */
                $task = new $class();
                $branchCtx = array_merge($branchCtx, (array)$task->execute($branchCtx));
            }
            // 只回传相对主上下文新增/变更的键（避免整份拷贝重复膨胀）
            $produced = [];
            foreach ($branchCtx as $k => $v) {
                if (!array_key_exists($k, $context) || $context[$k] !== $v) {
                    $produced[$k] = $v;
                }
            }
            $aggregate[$name] = $produced;
        }
        $this->logNode($runId, $node, $index, 'success', $context, $aggregate);
        return array_merge($context, [$node->key() => $aggregate]);
    }

    /** decision 跳过后被略过的节点区间落 skipped 日志（可观测优先） */
    private function markSkipped(string $runId, array $nodes, int $from, int $to): void
    {
        for ($i = $from; $i < $to; $i++) {
            $this->logNode($runId, $nodes[$i], $i, 'skipped', [], null);
        }
    }

    /** 写一条节点日志（input/output 快照 JSON 化） */
    private function logNode(string $runId, Node $node, int $index, string $status, array $input, ?array $output): void
    {
        $this->storage->execute(
            'INSERT INTO ai_workflow_run_log (id, run_id, node_key, node_index, status, input, output, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [
                Id::gen(),
                $runId,
                $node->key(),
                $index,
                $status,
                $this->json($input),
                $output === null ? null : $this->json($output),
                $this->now(),
            ]
        );
    }

    /** decision 字段值规范化：bool → 'true'/'false'，null → 'null'，标量 → 字符串本体 */
    private function normalizeDecisionValue($raw): string
    {
        if (is_bool($raw)) {
            return $raw ? 'true' : 'false';
        }
        if ($raw === null) {
            return 'null';
        }
        if (is_scalar($raw)) {
            return (string)$raw;
        }
        return 'unmatchable:' . gettype($raw); // 数组/对象无对应分支 → 未命中顺序走
    }

    /** 原子抢占一条队列行（同刻多进程只有一个能改成功） */
    private function claim(string $queueId): bool
    {
        return $this->storage->execute(
            'UPDATE ai_workflow_queue SET status = ?, started_at = ?, attempts = attempts + 1 WHERE id = ? AND status = ?',
            ['running', $this->now(), $queueId, 'queued']
        ) === 1;
    }

    private function finishQueued(string $queueId, string $status, ?string $runId, ?string $error): void
    {
        $this->storage->execute(
            'UPDATE ai_workflow_queue SET status = ?, run_id = ?, error = ?, finished_at = ? WHERE id = ?',
            [$status, $runId, $error, $this->now(), $queueId]
        );
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}