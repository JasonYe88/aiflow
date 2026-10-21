<?php
declare(strict_types=1);

namespace AIFlow\Adapter;

use AIFlow\Contract\CacheInterface;

/**
 * 进程内数组缓存：默认实现与测试用；FPM 每请求即弃，Swoole 常驻需换 RedisCache
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{0: string, 1: int}> [value, expireAt]，expireAt=0 表示不过期 */
    private $store = [];

    /** @var int 可拨动的时钟偏移（秒），仅测试用 */
    private $clockOffset = 0;

    public function get(string $key): ?string
    {
        if (!isset($this->store[$key])) {
            return null;
        }
        list($value, $expireAt) = $this->store[$key];
        if ($expireAt > 0 && $this->now() >= $expireAt) {
            unset($this->store[$key]);
            return null;
        }
        return $value;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->store[$key] = [$value, $ttl > 0 ? $this->now() + $ttl : 0];
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    /** 测试钩子：把逻辑时钟拨快 $seconds 秒 */
    public function rewind(int $seconds): void
    {
        $this->clockOffset += $seconds;
    }

    private function now(): int
    {
        return time() + $this->clockOffset;
    }
}