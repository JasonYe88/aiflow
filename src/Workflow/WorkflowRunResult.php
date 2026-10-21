<?php
declare(strict_types=1);

namespace AIFlow\Workflow;

/**
 * 工作流运行结果值对象（同步 run() 的返回）：
 * 查询明细（各节点 input/output 快照）走 engine 的 logs()，此处只携带纵览
 */
final class WorkflowRunResult
{
    /** @var string ai_workflow_run.id */
    public $runId;

    /** @var string 工作流标识 */
    public $workflowId;

    /** @var string running | success | failed */
    public $status;

    /** @var array 运行结束时的完整上下文 */
    public $context;

    public function __construct(string $runId, string $workflowId, string $status, array $context)
    {
        $this->runId = $runId;
        $this->workflowId = $workflowId;
        $this->status = $status;
        $this->context = $context;
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    /** @return array 数据库行形态（同 ai_workflow_run 列名，供调试/断言） */
    public function toArray(): array
    {
        return [
            'id' => $this->runId,
            'workflow_id' => $this->workflowId,
            'status' => $this->status,
        ];
    }
}