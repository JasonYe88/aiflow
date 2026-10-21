<?php
declare(strict_types=1);

namespace AIFlow\Adapter;

use AIFlow\Contract\CacheInterface;
use Redis;

/**
 * 基于 phpredis 的缓存适配器；未装扩展时内核自动降级 ArrayCache
 */
final class RedisCache implements CacheInterface
{
    /** @var Redis */
    private $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function get(string $key)
    {
        $value = $this->redis->get($key);
        return $value === false ? null : (string)$value;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return $ttl > 0 ? $this->redis->setex($key, $ttl, $value)
                        : $this->redis->set($key, $value);
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($key) >= 0;
    }
}