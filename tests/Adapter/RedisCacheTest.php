<?php
declare(strict_types=1);

namespace AIFlow\Tests\Adapter;

use AIFlow\Adapter\RedisCache;
use PHPUnit\Framework\TestCase;

class RedisCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis 未安装');
        }
    }

    public function testGetSetDelete(): void
    {
        $redis = new \Redis();
        try {
            $connected = $redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\RedisException $e) {
            $this->markTestSkipped('本机 Redis 不可用（连接抛异常）：' . $e->getMessage());
        }
        if (!$connected) {
            $this->markTestSkipped('本机 Redis 不可用（127.0.0.1:6379 连接失败），CI 由 Redis service container 提供实例');
        }
        $redis->select(15); // 测试专用库，避免污染

        $cache = new RedisCache($redis);
        $cache->delete('aiflow:test:k');
        $this->assertNull($cache->get('aiflow:test:k'));
        $this->assertTrue($cache->set('aiflow:test:k', 'v'));
        $this->assertSame('v', $cache->get('aiflow:test:k'));
        $this->assertTrue($cache->delete('aiflow:test:k'));
        $this->assertNull($cache->get('aiflow:test:k'));
    }
}