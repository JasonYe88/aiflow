<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 工作流定义非法（节点 key 重复 / 决策跳转目标不存在 / parallel 分支为空等）
 * 属"编译期"错误：run() 启动时一次性校验，落库前 fail fast
 */
class WorkflowDefinitionException extends \InvalidArgumentException implements AIFlowException
{
}