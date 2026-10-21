<?php
declare(strict_types=1);

namespace AIFlow\Tests\Workflow;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Exception\NodeRetryExhaustedException;
use AIFlow\Exception\WorkflowDefinitionException;
use AIFlow\Gateway\ModelGateway;
use AIFlow\Support\Id;
use AIFlow\Support\Migrator;
use AIFlow\Support\ModelResult;
use AIFlow\Tests\Support\ScriptedProvider;
use AIFlow\Workflow\Node;
use AIFlow\Workflow\TaskInterface;
use AIFlow\Workflow\Workflow;
use AIFlow\Workflow\WorkflowEngine;
use PHPUnit\Framework\TestCase;

class WorkflowEngineTest extends TestCase
{
    /** @var WorkflowEngine */
    protected $engine;

    /** @var PdoStorage */
    protected $storage;

    /** @var ScriptedProvider */
    protected $provider;

    protected function setUp(): void
    {
        $pdo = $this->makePdo();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoStorage($pdo);
        (new Migrator($this->storage, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->provider = new ScriptedProvider();
        $gateway = new ModelGateway();
        $gateway->add('stub', $this->provider);
        $this->engine = new WorkflowEngine($this->storage, $gateway);

        // 静态状态跨用例隔离（FlakyTask 的失败脚本/调用计数）
        FlakyTask::$failTimes = 0;
        FlakyTask::$calls = 0;
    }

    /** 默认 SQLite 内存库 */
    protected function makePdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }

    /** 读 run 行 */
    protected function runRow(string $runId): array
    {
        $rows = $this->storage->query('SELECT * FROM ai_workflow_run WHERE id = ?', [$runId]);
        return $rows[0];
    }

    /** 该 run 的节点日志（按 id 排序即写入序，Id::gen 单调） */
    protected function logsOf(string $runId): array
    {
        return $this->storage->query('SELECT * FROM ai_workflow_run_log WHERE run_id = ? ORDER BY id ASC', [$runId]);
    }

    // ---------- 编译期校验 ----------

    public function testEmptyNodesRejected(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [];
            }
        };
        $this->expectException(WorkflowDefinitionException::class);
        $this->expectExceptionMessage('至少需要');
        $this->engine->run($wf);
    }

    public function testDuplicateNodeKeyRejected(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::task('a', PassTask::class),
                    Node::task('a', PassTask::class),
                ];
            }
        };
        $this->expectException(WorkflowDefinitionException::class);
        $this->expectExceptionMessage('重复');
        $this->engine->run($wf);
    }

    public function testDecisionJumpToUnknownKeyRejected(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::task('a', PassTask::class),
                    Node::decision('d', 'flag', ['true' => 'nowhere']),
                ];
            }
        };
        $this->expectException(WorkflowDefinitionException::class);
        $this->expectExceptionMessage('nowhere');
        $this->engine->run($wf);
    }

    public function testTaskClassNotImplementContractRejected(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [Node::task('bad', \stdClass::class)];
            }
        };
        $this->expectException(WorkflowDefinitionException::class);
        $this->expectExceptionMessage('TaskInterface');
        $this->engine->run($wf);
    }

    // ---------- task 流水线 + 全链路落库 ----------

    public function testTaskPipelineMergesContextAndPersists(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::task('first', FirstTask::class),
                    Node::task('second', SecondTask::class),
                ];
            }
        };

        $result = $this->engine->run($wf, ['seed' => 1], 'ref-100');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['seed' => 1, 'first' => 'F1', 'second' => 'S1'], $result->context);

        // run 行：status/ref_id/起止时间
        $row = $this->runRow($result->runId);
        $this->assertSame('success', $row['status']);
        $this->assertSame('ref-100', $row['ref_id']);
        $this->assertSame($wf->id(), $row['workflow_id']);
        $this->assertNotEmpty($row['finished_at']);

        // 每节点一条日志，input 快照逐步演化、output 为增量产物
        $logs = $this->logsOf($result->runId);
        $this->assertCount(2, $logs);
        $this->assertSame('first', $logs[0]['node_key']);
        $this->assertSame('success', $logs[0]['status']);
        $this->assertSame(['seed' => 1], json_decode((string)$logs[0]['input'], true));
        $this->assertSame(['first' => 'F1'], json_decode((string)$logs[0]['output'], true));
        $this->assertSame('second', $logs[1]['node_key']);
        $this->assertSame(['seed' => 1, 'first' => 'F1'], json_decode((string)$logs[1]['input'], true));
    }

    public function testTaskInputIsFullContextSnapshot(): void
    {
        $GLOBALS['wf_test_seen_inputs'] = [];
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::task('a', RecorderTask::class),
                    Node::task('b', RecorderTask::class),
                ];
            }
        };
        $this->engine->run($wf, ['x' => 'x0']);
        $this->assertSame(
            [['x' => 'x0'], ['x' => 'x0', 'a' => 'A']],
            $GLOBALS['wf_test_seen_inputs']
        );
        unset($GLOBALS['wf_test_seen_inputs']);
    }

    // ---------- 重试 ----------

    public function testRetriesRecoverOnThirdAttempt(): void
    {
        FlakyTask::$failTimes = 2;
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [Node::task('flaky', FlakyTask::class, ['retries' => 3])];
            }
        };
        $result = $this->engine->run($wf);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(3, FlakyTask::$calls); // 失败 2 次 + 成功 1 次

        $logs = $this->logsOf($result->runId);
        $this->assertSame('success', $logs[0]['status']);
        $out = json_decode((string)$logs[0]['output'], true);
        $this->assertSame(2, $out['_retries']); // 重试了 2 次才成功
        $this->assertSame(['flaky' => 'ok'], $result->context);
    }

    public function testRetryExhaustedMarksRunFailedAndThrows(): void
    {
        FlakyTask::$failTimes = 99;
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::task('flaky', FlakyTask::class, ['retries' => 1]),
                    Node::task('never', FirstTask::class),
                ];
            }
        };
        try {
            $this->engine->run($wf, [], 'ref-x');
            $this->fail('应抛 NodeRetryExhaustedException');
        } catch (NodeRetryExhaustedException $e) {
            $this->assertSame('flaky', $e->nodeKey);
            $this->assertSame(1, $e->retries);
            $this->assertMatchesRegularExpression('/boom/', $e->getMessage());
        }
        // run 行 failed + 执行过的节点日志带失败原因
        $rows = $this->storage->query("SELECT * FROM ai_workflow_run WHERE ref_id = ?", ['ref-x']);
        $this->assertSame('failed', $rows[0]['status']);
        $logs = $this->logsOf($rows[0]['id']);
        $this->assertCount(1, $logs); // never 节点不再执行
        $this->assertSame('failed', $logs[0]['status']);
        $this->assertMatchesRegularExpression('/boom/', (string)$logs[0]['output']);
        // 异常后上下文不再演化（second 未执行）
        $this->assertStringNotContainsString('F1', (string)$rows[0]['context']);
    }

    public function testNoRetryByDefault(): void
    {
        FlakyTask::$failTimes = 1;
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [Node::task('flaky', FlakyTask::class)];
            }
        };
        try {
            $this->engine->run($wf);
            $this->fail('默认不重试，一次失败即抛');
        } catch (NodeRetryExhaustedException $e) {
            $this->assertSame(0, $e->retries);
            $this->assertSame(1, FlakyTask::$calls);
        }
    }

    // ---------- decision ----------

    public function testDecisionJumpsByBoolContextValue(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::decision('route', 'need_enrich', ['true' => 'enrich', 'false' => Node::END]),
                    Node::task('enrich', SecondTask::class),
                ];
            }
        };

        // true → 跳去 enrich
        $r1 = $this->engine->run($wf, ['need_enrich' => true]);
        $this->assertSame(['need_enrich' => true, 'second' => 'S1'], $r1->context);
        // false → END，enrich 记 skipped
        $r2 = $this->engine->run($wf, ['need_enrich' => false]);
        $this->assertSame(['need_enrich' => false], $r2->context);
        $logs = $this->logsOf($r2->runId);
        $this->assertSame('success', $logs[0]['status']);
        $this->assertSame(['decision' => false, 'next' => Node::END], json_decode((string)$logs[0]['output'], true));
        $this->assertSame('skipped', $logs[1]['status']);
        $this->assertSame('enrich', $logs[1]['node_key']);
    }

    public function testDecisionUnmatchedFallsThroughToNext(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::decision('route', 'mode', ['fast' => Node::END]),
                    Node::task('slow_path', PassTask::class),
                ];
            }
        };
        // 未命中 'fast' → 顺序下一节点
        $r1 = $this->engine->run($wf, ['mode' => 'slow']);
        $this->assertSame(['mode' => 'slow', 'done' => true], $r1->context);
        $this->assertCount(2, $this->logsOf($r1->runId)); // decision + slow_path 各一条

        // 命中 'fast' → END，slow_path 记 skipped
        $r2 = $this->engine->run($wf, ['mode' => 'fast']);
        $this->assertSame(['mode' => 'fast'], $r2->context);
        $logs = $this->logsOf($r2->runId);
        $this->assertSame('skipped', $logs[1]['status']);
        $this->assertSame('slow_path', $logs[1]['node_key']);
        $this->assertSame(['decision' => 'fast', 'next' => Node::END], json_decode((string)$logs[0]['output'], true));
    }

    public function testDecisionMissingFieldActsAsNull(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::decision('d', 'absent', ['null' => 'sink']),
                    Node::task('pass', PassTask::class),
                    Node::task('sink', SecondTask::class),
                ];
            }
        };
        $result = $this->engine->run($wf); // 字段缺失 → null → 跳 sink
        $this->assertSame(['second' => 'S1'], $result->context);
    }

    // ---------- llm 节点 ----------

    public function testLlmNodeSendsPromptAndStoresContent(): void
    {
        $this->provider->script(new ModelResult('模型回复正文', 'stub-model', 10, 20, 5));
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::task('prep', FirstTask::class),
                    Node::llm('summary', 'stub', function (array $ctx) {
                        return '分析这些数据：' . $ctx['first'];
                    }),
                ];
            }
        };
        $result = $this->engine->run($wf, []);
        $this->assertSame('模型回复正文', $result->context['summary']);

        // prompt 由上下文构造为单条 user message
        $this->assertSame(
            [['role' => 'user', 'content' => '分析这些数据：F1']],
            $this->provider->calls[0]['messages']
        );
        // 日志记录用量
        $logs = $this->logsOf($result->runId);
        $out = json_decode((string)$logs[1]['output'], true);
        $this->assertSame('模型回复正文', $out['content']);
        $this->assertSame(10, $out['input_tokens']);
        $this->assertSame(20, $out['output_tokens']);
    }

    public function testLlmNodeAcceptsFullMessagesArray(): void
    {
        $this->provider->script(new ModelResult('ok', 'm', 1, 1, 1));
        $messages = [
            ['role' => 'system', 'content' => '你是财务助理'],
            ['role' => 'user', 'content' => 'hi'],
        ];
        $wf = new class extends Workflow {
            public $messages;

            public function nodes(): array
            {
                return [
                    Node::llm('call', 'stub', function (array $ctx) {
                        return $this->messages;
                    }),
                ];
            }
        };
        $wf->messages = $messages;
        $this->engine->run($wf);
        $this->assertSame($messages, $this->provider->calls[0]['messages']);
    }

    public function testLlmNodeWithoutGatewayFailsRun(): void
    {
        $engine = new WorkflowEngine($this->storage); // 未装配网关
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [Node::llm('call', 'stub', function () {
                    return 'x';
                })];
            }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('网关');
        try {
            $engine->run($wf);
        } catch (\RuntimeException $e) {
            $rows = $this->storage->query("SELECT * FROM ai_workflow_run WHERE workflow_id = ?", [$wf->id()]);
            $this->assertSame('failed', $rows[0]['status']); // run 行仍置 failed
            throw $e;
        }
    }

    // ---------- parallel 节点 ----------

    public function testParallelBranchesIsolatedAndAggregated(): void
    {
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::task('prep', FirstTask::class),
                    Node::parallel('fan_out', [
                        'left' => [PassTask::class, LeftAlphaTask::class],  // 分支内串行小流水
                        'right' => CountTask::class,
                    ]),
                ];
            }
        };
        $result = $this->engine->run($wf, ['count' => 5]);

        // 分支拿主上下文副本，互相不可见；只回传各自新增/变更的键
        $this->assertSame('F1', $result->context['first']);
        $this->assertSame('A', $result->context['fan_out']['left']['alpha']);
        $this->assertSame(6, $result->context['fan_out']['right']['count']);
        $this->assertArrayNotHasKey('count', $result->context['fan_out']['left']); // right 改的 count 不出现在 left
        $this->assertSame(5, $result->context['count']); // 分支改动不覆盖主上下文同键

        // 整节点一条 success 日志，output 为聚合产物
        $logs = $this->logsOf($result->runId);
        $this->assertCount(2, $logs);
        $out = json_decode((string)$logs[1]['output'], true);
        $this->assertSame('A', $out['left']['alpha']);
    }

    // ---------- 异步：dispatch / consume ----------

    public function testDispatchEnqueuesAndConsumeExecutes(): void
    {
        $queueId = $this->engine->dispatch(new DemoQueueWorkflow(), ['n' => 3], 'biz-42');
        $this->assertNotSame('', $queueId);

        $row = $this->storage->query('SELECT * FROM ai_workflow_queue WHERE id = ?', [$queueId])[0];
        $this->assertSame('queued', $row['status']);
        $this->assertSame(DemoQueueWorkflow::class, $row['workflow_class']);
        $this->assertSame(['n' => 3], json_decode((string)$row['input'], true));

        $processed = $this->engine->consume(10);
        $this->assertSame(1, $processed);

        $row = $this->storage->query('SELECT * FROM ai_workflow_queue WHERE id = ?', [$queueId])[0];
        $this->assertSame('done', $row['status']);
        $this->assertSame(1, (int)$row['attempts']);
        $this->assertNotEmpty($row['run_id']);
        $this->assertSame('success', $this->runRow($row['run_id'])['status']);
        $this->assertSame(['n' => 3, 'double' => 6, 'label' => 'n=3'], json_decode((string)$this->runRow($row['run_id'])['context'], true));
    }

    public function testConsumeMarksFailedAndKeepsQueueRow(): void
    {
        FlakyTask::$failTimes = 99;
        $this->engine->dispatch(new class extends Workflow {
            public function nodes(): array
            {
                return [Node::task('doomed', FlakyTask::class)];
            }
        });

        $this->engine->consume(1);

        $rows = $this->storage->query('SELECT * FROM ai_workflow_queue');
        $this->assertSame('failed', $rows[0]['status']);
        $this->assertNotEmpty($rows[0]['run_id']);
        $this->assertMatchesRegularExpression('/boom/', (string)$rows[0]['error']);
        $this->assertSame('failed', $this->runRow($rows[0]['run_id'])['status']);
    }

    public function testConsumeSkipsMissingClass(): void
    {
        $this->storage->execute(
            'INSERT INTO ai_workflow_queue (id, workflow_class, status, input, created_at) VALUES (?,?,?,?,?)',
            [Id::gen(), 'NoSuchWorkflowClass', 'queued', '{}', date('Y-m-d H:i:s')]
        );
        $processed = $this->engine->consume(5);
        $this->assertSame(1, $processed); // 处理了（记 failed），不因坏行中断循环
        $row = $this->storage->query('SELECT * FROM ai_workflow_queue')[0];
        $this->assertSame('failed', $row['status']);
        $this->assertMatchesRegularExpression('/NoSuchWorkflowClass/', (string)$row['error']);
    }

    public function testConsumeClaimsAtomically(): void
    {
        $this->engine->dispatch(new DemoQueueWorkflow());
        // 模拟另一进程已抢占：status=queued 的行抢不到
        $this->storage->execute("UPDATE ai_workflow_queue SET status = 'running' WHERE status = 'queued'");
        $gap = $this->engine->consume(5);
        $this->assertSame(0, $gap); // 非队列行不消费
        $this->assertSame('running', $this->storage->query('SELECT status FROM ai_workflow_queue')[0]['status']);
    }
}

// ---------- 测试用节点类 ----------

class PassTask implements TaskInterface
{
    public function execute(array $input): array
    {
        return ['done' => true];
    }
}

class FirstTask implements TaskInterface
{
    public function execute(array $input): array
    {
        return ['first' => 'F1'];
    }
}

class SecondTask implements TaskInterface
{
    public function execute(array $input): array
    {
        return ['second' => 'S1'];
    }
}

class RecorderTask implements TaskInterface
{
    public function execute(array $input): array
    {
        $GLOBALS['wf_test_seen_inputs'][] = $input;
        return ['a' => 'A'];
    }
}

class FlakyTask implements TaskInterface
{
    /** @var int 连续失败次数（每次 execute 前 -1，归零后成功） */
    public static $failTimes = 0;

    /** @var int execute 调用计数（断言重试次数用） */
    public static $calls = 0;

    public function execute(array $input): array
    {
        self::$calls++;
        if (self::$failTimes > 0) {
            self::$failTimes--;
            throw new \RuntimeException('boom #' . self::$calls);
        }
        return ['flaky' => 'ok'];
    }
}

class LeftAlphaTask implements TaskInterface
{
    public function execute(array $input): array
    {
        return ['alpha' => 'A'];
    }
}

class CountTask implements TaskInterface
{
    public function execute(array $input): array
    {
        return ['count' => ((int)($input['count'] ?? 0)) + 1];
    }
}

class DoubleTask implements TaskInterface
{
    public function execute(array $input): array
    {
        return ['double' => ((int)$input['n']) * 2];
    }
}

class LabelTask implements TaskInterface
{
    public function execute(array $input): array
    {
        return ['label' => 'n=' . $input['n']];
    }
}

class DemoQueueWorkflow extends Workflow
{
    public function nodes(): array
    {
        return [
            Node::task('double', DoubleTask::class),
            Node::task('label', LabelTask::class),
        ];
    }
}