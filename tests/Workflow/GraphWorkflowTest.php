<?php
declare(strict_types=1);

namespace AIFlow\Tests\Workflow;

use AIFlow\Adapter\PdoStorage;
use AIFlow\AIKernel;
use AIFlow\Exception\WorkflowDefinitionException;
use AIFlow\Exception\WorkflowRuntimeException;
use AIFlow\Support\Migrator;
use AIFlow\Workflow\GraphWorkflow;
use AIFlow\Workflow\NodeHandler;
use AIFlow\Workflow\NodeOutcome;
use PHPUnit\Framework\TestCase;

class GraphWorkflowTest extends TestCase
{
    /** @var GraphWorkflow */
    private $graph;

    /** @var PdoStorage */
    private $storage;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoStorage($pdo);
        (new Migrator($this->storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->graph = new GraphWorkflow($this->storage, [new GateHandler()]);
    }

    public function testStartToEndPassesInputAndLogs(): void
    {
        $result = $this->graph->run($this->line('s', 'e'), ['year' => '2026'], 'profit');
        $this->assertTrue($result->isSuccess());
        $this->assertSame('2026', $result->context['year']);
        $logs = $this->storage->query(
            'SELECT node_key, status FROM ai_workflow_run_log WHERE run_id = ? ORDER BY node_index ASC',
            [$result->runId]
        );
        $this->assertSame('s', $logs[0]['node_key']);
        $this->assertSame('e', $logs[1]['node_key']);
        $this->assertSame('success', $logs[1]['status']);
    }

    public function testUnknownTypeRejectedBeforeRunRow(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'title' => '开始'],
                ['id' => 'h', 'type' => 'http', 'title' => 'HTTP'],
            ],
            'edges' => [
                ['source' => 's', 'target' => 'h'],
            ],
        ];
        $this->expectException(WorkflowDefinitionException::class);
        $this->expectExceptionMessage('未注册的节点类型');
        try {
            $this->graph->run($graph);
        } finally {
            $rows = $this->storage->query('SELECT id FROM ai_workflow_run');
            $this->assertSame([], $rows);
        }
    }

    public function testTwoStartsRejected(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'a', 'type' => 'start', 'title' => '开始'],
                ['id' => 'b', 'type' => 'start', 'title' => '另一个'],
            ],
            'edges' => [],
        ];
        $this->expectException(WorkflowDefinitionException::class);
        $this->expectExceptionMessage('开始节点只能有一个');
        $this->graph->saveDraft('wf-two', $graph);
    }

    public function testPublishThenRunKeepsDraftWhenRepublishPending(): void
    {
        $id = 'wf-profit';
        $this->graph->saveDraft($id, $this->line('s', 'e'));
        $this->assertNull($this->graph->published($id));
        try {
            $this->graph->runPublished($id);
            $this->fail('未发布不应运行');
        } catch (WorkflowDefinitionException $e) {
            $this->assertStringContainsString('尚未发布', $e->getMessage());
        }
        $this->graph->publish($id);
        $result = $this->graph->runPublished($id, ['year' => '2025']);
        $this->assertSame('2025', $result->context['year']);

        $this->graph->saveDraft($id, $this->line('s2', 'e2'));
        $published = $this->graph->published($id);
        $this->assertSame('s', $published['nodes'][0]['id']);
    }

    public function testDraftKeepsPosition(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'title' => '开始', 'position' => ['x' => 80, 'y' => 282]],
            ],
            'edges' => [],
        ];
        $this->graph->saveDraft('wf-pos', $graph);
        $loaded = $this->graph->draft('wf-pos');
        $this->assertSame(80, $loaded['nodes'][0]['position']['x']);
    }

    public function testGateChoosesHandle(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'title' => '开始'],
                ['id' => 'g', 'type' => 'gate', 'title' => '门'],
                ['id' => 'y', 'type' => 'end', 'title' => '是'],
                ['id' => 'n', 'type' => 'end', 'title' => '否'],
            ],
            'edges' => [
                ['source' => 's', 'target' => 'g'],
                ['source' => 'g', 'target' => 'y', 'sourceHandle' => 'yes'],
                ['source' => 'g', 'target' => 'n', 'sourceHandle' => 'no'],
            ],
        ];
        $yes = $this->graph->run($graph, ['ok' => 1], 'gate-yes');
        $logs = $this->storage->query(
            'SELECT node_key FROM ai_workflow_run_log WHERE run_id = ? ORDER BY node_index ASC',
            [$yes->runId]
        );
        $this->assertSame(['s', 'g', 'y'], array_column($logs, 'node_key'));

        $no = $this->graph->run($graph, ['ok' => 0], 'gate-no');
        $logs = $this->storage->query(
            'SELECT node_key FROM ai_workflow_run_log WHERE run_id = ? ORDER BY node_index ASC',
            [$no->runId]
        );
        $this->assertSame(['s', 'g', 'n'], array_column($logs, 'node_key'));
    }

    public function testCycleMarksRunFailed(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'title' => '开始'],
                ['id' => 'loop', 'type' => 'gate', 'title' => '环'],
            ],
            'edges' => [
                ['source' => 's', 'target' => 'loop'],
                ['source' => 'loop', 'target' => 'loop', 'sourceHandle' => 'yes'],
            ],
        ];
        try {
            $this->graph->run($graph, ['ok' => 1], 'cycle');
            $this->fail('成环应失败');
        } catch (WorkflowRuntimeException $e) {
            $this->assertStringContainsString('成环', $e->getMessage());
        }
        $rows = $this->storage->query("SELECT status FROM ai_workflow_run WHERE workflow_id = 'cycle'");
        $this->assertSame('failed', $rows[0]['status']);
    }

    public function testKernelExposesGraph(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache' => ['driver' => 'array'],
            'migration' => ['auto' => true],
        ]);
        $this->assertInstanceOf(GraphWorkflow::class, $kernel->graph());
        $kernel->graph()->saveDraft('from-kernel', $this->line('s', 'e'));
        $this->assertSame('s', $kernel->graph()->draft('from-kernel')['nodes'][0]['id']);
    }

    private function line(string $start, string $end): array
    {
        return [
            'nodes' => [
                ['id' => $start, 'type' => 'start', 'title' => '开始'],
                ['id' => $end, 'type' => 'end', 'title' => '结束'],
            ],
            'edges' => [
                ['source' => $start, 'target' => $end],
            ],
        ];
    }
}

/**
 * 测试用分支插槽：ok 为真走 yes，否则走 no
 */
class GateHandler implements NodeHandler
{
    public function type(): string
    {
        return 'gate';
    }

    public function execute(array $config, array $inputs): NodeOutcome
    {
        $handle = !empty($inputs['ok']) ? 'yes' : 'no';
        return new NodeOutcome($inputs, $handle, false);
    }
}