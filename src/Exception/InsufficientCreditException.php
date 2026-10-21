<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 余额不足：预检冻结时抛出，携带需求量与持有量供宿主展示/引导充值
 */
class InsufficientCreditException extends \RuntimeException implements AIFlowException
{
    /** @var int 本次调用需要（分） */
    public $need;

    /** @var int 钱包当前持有（分） */
    public $have;

    public function __construct(string $message, int $need, int $have)
    {
        parent::__construct($message);
        $this->need = $need;
        $this->have = $have;
    }
}