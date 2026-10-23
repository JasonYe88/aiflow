<?php
declare(strict_types=1);

namespace AIFlow\Workflow\Handler;

use AIFlow\Workflow\NodeHandler;
use AIFlow\Workflow\NodeOutcome;

/**
 * 结束节点：上下文原样作为本节点产出，并停止往下走
 */
final class EndHandler implements NodeHandler
{
    public function type(): string
    {
        return 'end';
    }

    public function execute(array $config, array $inputs): NodeOutcome
    {
        return new NodeOutcome($inputs, 'source', true);
    }
}
