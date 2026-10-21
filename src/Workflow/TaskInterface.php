<?php
declare(strict_types=1);

namespace AIFlow\Workflow;

/**
 * 工作流 task 节点的业务类契约
 *
 * 引擎以 `new $class()` 实例化并调用 execute()，返回数组作为"增量产物"
 * 合入运行上下文（array_merge），不会覆盖未返回的既有键。
 * 依赖模型/存储等服务的任务类，请在构造器外通过 AIKernel::boot() 单例或
 * 宿主容器自行获取（M4 不侵入 DI）。
 */
interface TaskInterface
{
    /**
     * @param array $input 当前运行上下文只读副本（含之前所有节点的产物）
     * @return array 本节点增量产物（键值对），合并进运行上下文
     */
    public function execute(array $input): array;
}