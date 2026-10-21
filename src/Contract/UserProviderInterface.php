<?php
declare(strict_types=1);

namespace AIFlow\Contract;

/**
 * 宿主用户体系适配器：把宿主用户 ID 映射为框架角色
 * FMS 等宿主项目实现此接口后注入内核（M2 AccessControl 消费，M0 先立契约）
 */
interface UserProviderInterface
{
    /**
     * 查询用户挂载的角色标识列表
     *
     * @param mixed $userId 宿主项目的用户 ID（int 或 string 均可能）
     * @return string[] 角色标识，如 ["admin"]
     */
    public function rolesOf($userId): array;
}