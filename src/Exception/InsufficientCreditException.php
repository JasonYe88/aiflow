<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 余额不足（预检 reserve 阶段抛出，模型调用不会发生）
 * need/have 公有属性供前端展示"还差多少"（单位：分）
 */
class InsufficientCreditException extends \RuntimeException implements AIFlowException
{
    /** @var int 本次需要的额度（分） */
    public $need;

    /** @var int 当前可用额度（余额 - 已冻结，分） */
    public $have;

    public function __construct(string $message, int $need, int $have)
    {
        $this->need = $need;
        $this->have = $have;
        parent::__construct($message . "（需要 {$need} 分，可用 {$have} 分）");
    }
}