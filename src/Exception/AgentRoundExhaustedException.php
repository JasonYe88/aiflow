<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * Agent 循环达到最大轮数仍未得到最终回答
 * 携带已完成轮数与轨迹长度供宿主诊断（死循环/模型反复调用工具时触发）
 */
class AgentRoundExhaustedException extends \RuntimeException implements AIFlowException
{
    /** @var int 已执行轮数 */
    public $rounds;

    /** @var int 已执行的工具调用次数 */
    public $toolCalls;

    public function __construct(string $message, int $rounds, int $toolCalls)
    {
        parent::__construct($message);
        $this->rounds = $rounds;
        $this->toolCalls = $toolCalls;
    }
}