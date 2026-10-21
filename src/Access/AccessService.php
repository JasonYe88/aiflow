<?php
declare(strict_types=1);

namespace AIFlow\Access;

use AIFlow\Contract\StorageInterface;
use AIFlow\Contract\UserProviderInterface;
use AIFlow\Exception\PermissionDeniedException;
use AIFlow\Support\Id;

/**
 * 扁平 RBAC 权限判定：角色 → 资源（app/workflow/tool）授权（allow/deny）
 *
 * 判定语义（已确认决策）：
 * - 角色 = 权限的载体：用户经 UserProviderInterface 映射到角色集合（并集）
 * - 资源标识 = "type:identifier"，如 "app:ai_chat_demo"
 * - 通配：identifier '*' 与 type '*'（超管 = 显式授权 `*:*`，无隐式超管）
 * - **deny 优先于 allow**：任一角色对目标资源有 deny 即拒绝
 * - 无任何授权 = 拒绝；未挂任何角色 = 拒绝
 */
final class AccessService
{
    /** @var StorageInterface */
    private $storage;

    /** @var UserProviderInterface */
    private $users;

    public function __construct(StorageInterface $storage, UserProviderInterface $users)
    {
        $this->storage = $storage;
        $this->users = $users;
    }

    /** 角色 upsert（code 唯一）：内置约定 admin/developer/user，可自定义；返回角色 id */
    public function ensureRole(string $code, string $name): string
    {
        if ($code === '') {
            throw new \InvalidArgumentException('角色 code 不能为空');
        }
        $rows = $this->storage->query('SELECT id FROM ai_role WHERE code = ?', [$code]);
        if ($rows !== []) {
            return (string)$rows[0]['id'];
        }
        $id = Id::gen();
        try {
            $this->storage->execute(
                'INSERT INTO ai_role (id, code, name, created_at) VALUES (?, ?, ?, ?)',
                [$id, $code, $name === '' ? $code : $name, date('Y-m-d H:i:s')]
            );
            return $id;
        } catch (\Throwable $ignored) {
            $rows = $this->storage->query('SELECT id FROM ai_role WHERE code = ?', [$code]);
            if ($rows === []) {
                throw $ignored;
            }
            return (string)$rows[0]['id'];
        }
    }

    /**
     * 资源 upsert（type+identifier 唯一）：type 约定 app|workflow|tool，'*' 为通配登记（配 identifier '*' 即超管通配）
     * 返回资源 id
     */
    public function ensureResource(string $type, string $identifier, string $name = ''): string
    {
        if ($type === '' || $identifier === '') {
            throw new \InvalidArgumentException('资源 type/identifier 不能为空');
        }
        $rows = $this->storage->query(
            'SELECT id FROM ai_resource WHERE type = ? AND identifier = ?',
            [$type, $identifier]
        );
        if ($rows !== []) {
            return (string)$rows[0]['id'];
        }
        $id = Id::gen();
        try {
            $this->storage->execute(
                'INSERT INTO ai_resource (id, type, identifier, name, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, $type, $identifier, $name === '' ? ($type . ':' . $identifier) : $name, date('Y-m-d H:i:s')]
            );
            return $id;
        } catch (\Throwable $ignored) {
            $rows = $this->storage->query(
                'SELECT id FROM ai_resource WHERE type = ? AND identifier = ?',
                [$type, $identifier]
            );
            if ($rows === []) {
                throw $ignored;
            }
            return (string)$rows[0]['id'];
        }
    }

    /**
     * 授权 upsert（角色+资源 唯一）：effect ∈ allow|deny，重复授权为改判
     * 角色/资源不存在时自动登记（宿主 CLI/管理端一条命令完成三表初始化）
     */
    public function grant(string $roleCode, string $type, string $identifier, string $effect = 'allow'): void
    {
        if ($effect !== 'allow' && $effect !== 'deny') {
            throw new \InvalidArgumentException("effect 非法：{$effect}（allow|deny）");
        }
        $roleId = $this->ensureRole($roleCode, $roleCode);
        $resourceId = $this->ensureResource($type, $identifier);
        $rows = $this->storage->query(
            'SELECT id FROM ai_role_resource WHERE role_id = ? AND resource_id = ?',
            [$roleId, $resourceId]
        );
        if ($rows !== []) {
            // upsert 改判（先查再改：MySQL 对值未变的 UPDATE 返回 0 行，不可靠）
            $this->storage->execute(
                'UPDATE ai_role_resource SET effect = ? WHERE id = ?',
                [$effect, (string)$rows[0]['id']]
            );
            return;
        }
        $this->storage->execute(
            'INSERT INTO ai_role_resource (id, role_id, resource_id, effect, created_at) VALUES (?, ?, ?, ?, ?)',
            [Id::gen(), $roleId, $resourceId, $effect, date('Y-m-d H:i:s')]
        );
    }

    /** 回收授权：不存在时静默（幂等）；不改角色/资源本身 */
    public function revoke(string $roleCode, string $type, string $identifier): void
    {
        $this->storage->execute(
            'DELETE FROM ai_role_resource WHERE role_id IN (SELECT id FROM ai_role WHERE code = ?)
             AND resource_id IN (SELECT id FROM ai_resource WHERE type = ? AND identifier = ?)',
            [$roleCode, $type, $identifier]
        );
    }

    /**
     * 判定：角色并集的授权中看目标资源的精确 + 通配授权，deny 优先
     *
     * @param mixed  $userId 宿主用户 ID
     * @param string $resource "type:identifier"
     */
    public function check($userId, string $resource): bool
    {
        $parts = explode(':', $resource, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException("资源标识非法：{$resource}（应为 type:identifier）");
        }
        list($type, $identifier) = $parts;
        $roles = $this->users->rolesOf($userId);
        if ($roles === []) {
            return false; // 未挂任何角色 = 全拒绝
        }
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $rows = $this->storage->query(
            "SELECT rr.effect
             FROM ai_role_resource rr
             JOIN ai_resource r ON r.id = rr.resource_id
             JOIN ai_role ro ON ro.id = rr.role_id
             WHERE ro.code IN ({$placeholders}) AND r.type IN (?, '*') AND r.identifier IN (?, '*')",
            array_merge($roles, [$type, $identifier])
        );
        foreach ($rows as $row) {
            if ($row['effect'] === 'deny') {
                return false; // deny 优先
            }
        }
        return $rows !== [];
    }

    /** check 的抛异常版本：未通过抛 PermissionDeniedException（携带 userId/resource 供审计） */
    public function guard($userId, string $resource): void
    {
        if (!$this->check($userId, $resource)) {
            throw new PermissionDeniedException('无权访问', (string)$userId, $resource);
        }
    }
}