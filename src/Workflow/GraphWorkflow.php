<?php
declare(strict_types=1);

namespace AIFlow\Workflow;

use AIFlow\Contract\StorageInterface;
use AIFlow\Exception\WorkflowDefinitionException;
use AIFlow\Exception\WorkflowRuntimeException;
use AIFlow\Support\Id;
use AIFlow\Workflow\Handler\EndHandler;
use AIFlow\Workflow\Handler\StartHandler;

/**
 * 图工作流外壳：草稿、发布、按连线运行
 *
 * 和 WorkflowEngine 分工：那边用 PHP 类写死一条业务流程；这边把图存成 JSON，
 * 节点类型通过 NodeHandler 注册。现在只带 start / end，HTTP、模型等以后再注册。
 * 运行只认已发布图。config 原样交给节点类，引擎不解释里面的字段。
 */
final class GraphWorkflow
{
    /** @var StorageInterface */
    private $storage;

    /** @var array<string, NodeHandler> */
    private $handlers = [];

    /**
     * @param NodeHandler[] $handlers 额外节点类型，按 type() 覆盖默认同名插槽
     */
    public function __construct(StorageInterface $storage, array $handlers = [])
    {
        $this->storage = $storage;
        $this->register(new StartHandler());
        $this->register(new EndHandler());
        foreach ($handlers as $handler) {
            if (!$handler instanceof NodeHandler) {
                throw new \InvalidArgumentException('节点插槽必须实现 NodeHandler');
            }
            $this->register($handler);
        }
    }

    /** 注册或替换一种节点。后注册的同 type 覆盖先注册的 */
    public function register(NodeHandler $handler): void
    {
        $type = $handler->type();
        if ($type === '') {
            throw new \InvalidArgumentException('节点类型不能为空');
        }
        $this->handlers[$type] = $handler;
    }

    /**
     * 校验并保存草稿。已发布图不动。
     * 图上多出来的字段（如坐标）会原样保留。
     */
    public function saveDraft(string $workflowId, array $graph): void
    {
        $this->assertId($workflowId, '工作流');
        $this->compile($graph);
        $json = $this->encode($graph);
        $now = $this->now();
        $rows = $this->storage->query(
            'SELECT workflow_id FROM ai_workflow_graph WHERE workflow_id = ?',
            [$workflowId]
        );
        if ($rows === []) {
            $this->storage->execute(
                'INSERT INTO ai_workflow_graph (workflow_id, draft_json, published_json, updated_at) VALUES (?,?,?,?)',
                [$workflowId, $json, '', $now]
            );
            return;
        }
        $this->storage->execute(
            'UPDATE ai_workflow_graph SET draft_json = ?, updated_at = ? WHERE workflow_id = ?',
            [$json, $now, $workflowId]
        );
    }

    /**
     * 把当前草稿复制为已发布图
     *
     * @throws WorkflowDefinitionException 没有草稿
     */
    public function publish(string $workflowId): void
    {
        $this->assertId($workflowId, '工作流');
        $rows = $this->storage->query(
            'SELECT draft_json FROM ai_workflow_graph WHERE workflow_id = ?',
            [$workflowId]
        );
        if (!isset($rows[0]) || (string)$rows[0]['draft_json'] === '') {
            throw new WorkflowDefinitionException('没有可发布的草稿');
        }
        $this->storage->execute(
            'UPDATE ai_workflow_graph SET published_json = draft_json, updated_at = ? WHERE workflow_id = ?',
            [$this->now(), $workflowId]
        );
    }

    /** 草稿图；没有记录时返回 null */
    public function draft(string $workflowId): ?array
    {
        $rows = $this->storage->query(
            'SELECT draft_json FROM ai_workflow_graph WHERE workflow_id = ?',
            [$workflowId]
        );
        if (!isset($rows[0]) || (string)$rows[0]['draft_json'] === '') {
            return null;
        }
        return $this->decode((string)$rows[0]['draft_json']);
    }

    /** 已发布图；未发布时返回 null */
    public function published(string $workflowId): ?array
    {
        $rows = $this->storage->query(
            'SELECT published_json FROM ai_workflow_graph WHERE workflow_id = ?',
            [$workflowId]
        );
        if (!isset($rows[0]) || (string)$rows[0]['published_json'] === '') {
            return null;
        }
        return $this->decode((string)$rows[0]['published_json']);
    }

    /**
     * 运行已发布图。未发布直接拒绝，不落运行记录。
     *
     * @param array $input 开始节点收到的上下文
     * @param string|null $refId 宿主业务单号
     */
    public function runPublished(string $workflowId, array $input = [], ?string $refId = null): WorkflowRunResult
    {
        $graph = $this->published($workflowId);
        if ($graph === null) {
            throw new WorkflowDefinitionException('工作流尚未发布');
        }
        return $this->run($graph, $input, $workflowId, $refId);
    }

    /**
     * 按图运行一次，写入 ai_workflow_run 与 ai_workflow_run_log
     *
     * 从唯一的 start 出发，沿 nextHandle 对应的出边往下走。
     * 没有出边，或走到 stop 的节点（end）时正常结束。
     * 同一出口有多条边、步数超过节点数，记失败后抛出。
     */
    public function run(array $graph, array $input = [], string $workflowId = 'graph', ?string $refId = null): WorkflowRunResult
    {
        $this->assertId($workflowId, '工作流');
        $compiled = $this->compile($graph);
        $runId = Id::gen();
        $context = $input;
        $this->storage->execute(
            'INSERT INTO ai_workflow_run (id, workflow_id, status, context, ref_id, started_at) VALUES (?,?,?,?,?,?)',
            [$runId, $workflowId, 'running', $this->encode($context), $refId, $this->now()]
        );
        try {
            $context = $this->walk($compiled, $context, $runId);
        } catch (\Throwable $e) {
            $this->storage->execute(
                'UPDATE ai_workflow_run SET status = ?, context = ?, finished_at = ? WHERE id = ?',
                ['failed', $this->encode($context), $this->now(), $runId]
            );
            throw $e;
        }
        $this->storage->execute(
            'UPDATE ai_workflow_run SET status = ?, context = ?, finished_at = ? WHERE id = ?',
            ['success', $this->encode($context), $this->now(), $runId]
        );
        return new WorkflowRunResult($runId, $workflowId, 'success', $context);
    }

    /**
     * @param array $compiled compile() 的结果
     * @return array 结束时的上下文
     */
    private function walk(array $compiled, array $context, string $runId): array
    {
        $nodes = $compiled['nodes'];
        $edges = $compiled['edges'];
        $current = $compiled['start'];
        $guard = count($nodes) + 1;
        $index = 0;
        while (true) {
            if ($index >= $guard) {
                throw new WorkflowRuntimeException('工作流步数超过节点数，疑似成环');
            }
            $node = $nodes[$current];
            $handler = $this->handlers[$node['type']];
            $before = $context;
            $outcome = $handler->execute($node['config'], $context);
            $this->log($runId, $node['id'], $index, 'success', $before, $outcome->outputs);
            $context = array_merge($context, $outcome->outputs);
            $index++;
            if ($outcome->stop) {
                break;
            }
            $next = $this->matchEdges($edges, $current, $outcome->nextHandle);
            if ($next === []) {
                break;
            }
            if (count($next) > 1) {
                throw new WorkflowDefinitionException('同一出口有多条边：' . $current . '/' . $outcome->nextHandle);
            }
            $current = $next[0]['target'];
        }
        return $context;
    }

    /**
     * 编译期校验。通过后返回按 id 索引的节点、边列表、开始节点 id。
     *
     * @return array
     */
    private function compile(array $graph): array
    {
        if (!isset($graph['nodes']) || !is_array($graph['nodes']) || $graph['nodes'] === []) {
            throw new WorkflowDefinitionException('工作流至少需要一个节点');
        }
        if (isset($graph['edges']) && !is_array($graph['edges'])) {
            throw new WorkflowDefinitionException('edges 必须是数组');
        }
        $nodes = [];
        $start = '';
        foreach ($graph['nodes'] as $node) {
            if (!is_array($node)) {
                throw new WorkflowDefinitionException('节点必须是对象');
            }
            $id = isset($node['id']) ? (string)$node['id'] : '';
            $this->assertId($id, '节点');
            if (isset($nodes[$id])) {
                throw new WorkflowDefinitionException('节点 id 重复：' . $id);
            }
            $type = isset($node['type']) ? (string)$node['type'] : '';
            if ($type === '' || !isset($this->handlers[$type])) {
                throw new WorkflowDefinitionException('未注册的节点类型：' . $type);
            }
            if ($type === 'start') {
                if ($start !== '') {
                    throw new WorkflowDefinitionException('开始节点只能有一个');
                }
                $start = $id;
            }
            $config = [];
            if (isset($node['config'])) {
                if (!is_array($node['config'])) {
                    throw new WorkflowDefinitionException('节点 config 必须是对象：' . $id);
                }
                $config = $node['config'];
            }
            $nodes[$id] = [
                'id' => $id,
                'type' => $type,
                'title' => isset($node['title']) ? (string)$node['title'] : '',
                'config' => $config,
            ];
        }
        if ($start === '') {
            throw new WorkflowDefinitionException('工作流需要一个开始节点');
        }
        $edges = [];
        $handles = [];
        $rawEdges = isset($graph['edges']) ? $graph['edges'] : [];
        foreach ($rawEdges as $edge) {
            if (!is_array($edge)) {
                throw new WorkflowDefinitionException('边必须是对象');
            }
            $source = isset($edge['source']) ? (string)$edge['source'] : '';
            $target = isset($edge['target']) ? (string)$edge['target'] : '';
            if (!isset($nodes[$source]) || !isset($nodes[$target])) {
                throw new WorkflowDefinitionException('出边指向不存在的节点');
            }
            $sourceHandle = isset($edge['sourceHandle']) && (string)$edge['sourceHandle'] !== ''
                ? (string)$edge['sourceHandle']
                : 'source';
            $slot = $source . "\0" . $sourceHandle;
            if (isset($handles[$slot])) {
                throw new WorkflowDefinitionException('同一出口有多条边：' . $source . '/' . $sourceHandle);
            }
            $handles[$slot] = true;
            $targetHandle = isset($edge['targetHandle']) && (string)$edge['targetHandle'] !== ''
                ? (string)$edge['targetHandle']
                : 'target';
            $edges[] = [
                'source' => $source,
                'target' => $target,
                'sourceHandle' => $sourceHandle,
                'targetHandle' => $targetHandle,
            ];
        }
        return ['nodes' => $nodes, 'edges' => $edges, 'start' => $start];
    }

    /**
     * @param array $edges
     * @return array
     */
    private function matchEdges(array $edges, string $source, string $handle): array
    {
        $matched = [];
        foreach ($edges as $edge) {
            if ($edge['source'] === $source && $edge['sourceHandle'] === $handle) {
                $matched[] = $edge;
            }
        }
        return $matched;
    }

    private function log(string $runId, string $nodeKey, int $index, string $status, array $input, array $output): void
    {
        $this->storage->execute(
            'INSERT INTO ai_workflow_run_log (id, run_id, node_key, node_index, status, input, output, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [
                Id::gen(),
                $runId,
                $nodeKey,
                $index,
                $status,
                $this->encode($input),
                $this->encode($output),
                $this->now(),
            ]
        );
    }

    private function assertId(string $id, string $what): void
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $id)) {
            throw new WorkflowDefinitionException($what . ' id 非法：' . $id);
        }
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new WorkflowDefinitionException('图无法序列化');
        }
        return $json;
    }

    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new WorkflowDefinitionException('图 JSON 损坏');
        }
        return $data;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}