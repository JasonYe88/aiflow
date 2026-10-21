<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 工作流 task 节点重试耗尽仍失败
 * 携带节点 key 与重试次数供宿主诊断；此时 run 行已置 failed、
 * 失败节点的 run_log 已记录失败原因（output 列存异常消息）
 */
class NodeRetryExhaustedException extends \RuntimeException implements AIFlowException
{
    /** @var string 失败节点 key */
    public $nodeKey;

    /** @var int 已重试次数 */
    public $retries;

    public function __construct(string $message, string $nodeKey, int $retries, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->nodeKey = $nodeKey;
        $this->retries = $retries;
    }
}