<?php
declare(strict_types=1);

namespace AIFlow\Workflow;

/**
 * 图上一种节点的执行插槽
 *
 * 引擎只认 type()，不解释 config。HTTP、模型、分支这些明细以后各写一个实现类注册进来。
 * 业务逻辑放在这个类里，改一处即可，不进画面 JSON。
 */
interface NodeHandler
{
    /** 与图节点的 type 字段对应，一种类型一个实现 */
    public function type(): string;

    /**
     * @param array $config 节点上的 config，引擎原样传入
     * @param array $inputs 当前上下文（开始节点的输入，加上游节点合并进来的产出）
     */
    public function execute(array $config, array $inputs): NodeOutcome;
}
