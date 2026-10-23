<?php
declare(strict_types=1);

namespace AIFlow\Workflow\Handler;

use AIFlow\Workflow\NodeHandler;
use AIFlow\Workflow\NodeOutcome;

/**
 * 开始节点：把本次运行的输入放进上下文，然后沿 source 出边往下走
 */
final class StartHandler implements NodeHandler
{
    public function type(): string
    {
        return 'start';
    }

    public function execute(array $config, array $inputs): NodeOutcome
    {
        return new NodeOutcome($inputs, 'source', false);
    }
}
