<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 模型调用异常：携带厂商原始错误（状态码 + 响应体）
 */
class ModelCallException extends \RuntimeException
{
    /** @var int */
    public $statusCode;

    /** @var string */
    public $responseBody;

    public function __construct(string $message, int $statusCode = 0, string $responseBody = '')
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }
}
