<?php
declare(strict_types=1);

namespace AIFlow\Workflow;

/**
 * 一个节点跑完后交给图运行器的结果
 *
 * outputs 会合并进整张图的上下文，后写的键覆盖先写的键。
 * nextHandle 决定走哪条出边，缺省 source。
 * stop 为 true 时不再找下游（结束节点用）。
 */
final class NodeOutcome
{
    /** @var array */
    public $outputs;

    /** @var string */
    public $nextHandle;

    /** @var bool */
    public $stop;

    public function __construct(array $outputs, string $nextHandle = 'source', bool $stop = false)
    {
        $this->outputs = $outputs;
        $this->nextHandle = $nextHandle;
        $this->stop = $stop;
    }
}
