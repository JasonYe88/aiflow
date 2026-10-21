<?php
declare(strict_types=1);

namespace AIFlow\Workflow;

/**
 * 工作流基类：宿主业务流程声明为纯 PHP 类，编译期可静态校验、IDE 全程可跳转
 *
 *   class ProfitSkuSyncWorkflow extends Workflow
 *   {
 *       public function nodes(): array
 *       {
 *           return [
 *               Node::task('extract', ExtractParamsTask::class),
 *               Node::decision('need_retry', 'retry_flag', ['true' => 'extract', 'false' => Node::END]),
 *               Node::llm('summarize', 'deepseek', fn(array $ctx) => '总结：' . $ctx['data']),
 *               Node::parallel('fan_out', ['report' => ReportTask::class, 'notify' => NotifyTask::class]),
 *           ];
 *       }
 *   }
 *
 * 运行：$kernel->workflow()->run(new ProfitSkuSyncWorkflow(), $params, '业务单号');
 */
abstract class Workflow
{
    /**
     * 节点声明（按缺省执行顺序）
     *
     * @return Node[]
     */
    abstract public function nodes(): array;

    /** 工作流标识（落 ai_workflow_run.workflow_id；缺省取类短名） */
    public function id(): string
    {
        $class = static::class;
        return substr($class, (int)strrpos($class, '\\') + 1);
    }
}