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
        $this->assertTrue(
            $redis->connect('127.0.0.1', 6379),
            '本机 Redis 不可用，跳过前请确认 phpstudy Redis 已启动'
        );
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