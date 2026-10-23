<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 工作流运行期错误（图出现环、步数超过节点数）
 * 定义非法走 WorkflowDefinitionException，本类只表示图已经开始跑之后走不下去
 */
class WorkflowRuntimeException extends \RuntimeException implements AIFlowException
{
}
