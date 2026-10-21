<?php
declare(strict_types=1);

namespace AIFlow\Contract;

/**
 * 缓存适配器契约（M0 用于迁移版本表加速，M1+ 用于会话热点缓存）
 */
interface CacheInterface
{
    /** @return string|null 命中返回字符串，未命中返回 null */
    public function get(string $key): ?string;

    /** @param int $ttl 过期秒数，0 表示永不过期 */
    public function set(string $key, string $value, int $ttl = 0): bool;

    /** 幂等删除：键不存在也恒返回 true，不抛异常 */
    public function delete(string $key): bool;
}