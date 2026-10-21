<?php
declare(strict_types=1);

namespace AIFlow\Access;

use AIFlow\Contract\UserProviderInterface;

/**
 * 默认用户→角色映射：roles_map 精确匹配优先，default_roles 兜底
 *
 * M2 的 FMS 先用本实现（配置驱动）；
 * M3+ 宿主接真实登录态时换成自己的 UserProviderInterface 实现（查用户表/会话）注入即可。
 *
 * 配置对应（AIKernel::boot 的 access 段）：
 *   'roles_map'     => ['userId' => ['role', ...], ...]  精确映射
 *   'default_roles' => ['user']                          未命中与未配置用户的兜底角色
 */
final class FixedUserProvider implements UserProviderInterface
{
    /** @var array */
    private $rolesMap;

    /** @var string[] */
    private $defaultRoles;

    public function __construct(array $rolesMap = [], array $defaultRoles = ['user'])
    {
        $this->rolesMap = $rolesMap;
        $this->defaultRoles = $defaultRoles;
    }

    /**
     * @param mixed $userId 宿主用户 ID（int/string 均可；PHP 数组键对纯数字串自动归一为 int，双向命中）
     * @return string[]
     */
    public function rolesOf($userId): array
    {
        $key = is_scalar($userId) ? (string)$userId : '';
        if ($key !== '' && array_key_exists($key, $this->rolesMap)) {
            return array_values((array)$this->rolesMap[$key]);
        }
        return $this->defaultRoles;
    }
}