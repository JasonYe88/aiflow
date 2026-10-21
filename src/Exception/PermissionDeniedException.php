<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 无权访问资源（guard 判定未通过时抛出）
 */
class PermissionDeniedException extends \RuntimeException implements AIFlowException
{
    /** @var string 宿主用户 ID */
    public $userId;

    /** @var string 资源标识 type:identifier，如 app:ai_chat_demo */
    public $resource;

    public function __construct(string $message, string $userId, string $resource)
    {
        $this->userId = $userId;
        $this->resource = $resource;
        parent::__construct($message . "（用户 {$userId} 无权访问 {$resource}）");
    }
}