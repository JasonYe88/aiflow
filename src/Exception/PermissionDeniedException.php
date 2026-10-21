<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 权限拒绝：RBAC 校验未通过时抛出，携带用户与资源标识供宿主审计
 */
class PermissionDeniedException extends \RuntimeException implements AIFlowException
{
    /** @var string 被拒用户 ID */
    public $userId;

    /** @var string 被拒资源标识 */
    public $resource;

    public function __construct(string $message, string $userId, string $resource)
    {
        parent::__construct($message);
        $this->userId = $userId;
        $this->resource = $resource;
    }
}