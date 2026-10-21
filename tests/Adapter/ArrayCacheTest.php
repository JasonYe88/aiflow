<?php
declare(strict_types=1);

namespace AIFlow\Tests\Adapter;

use AIFlow\Adapter\ArrayCache;
use PHPUnit\Framework\TestCase;

class ArrayCacheTest extends TestCase
{
    /** @var ArrayCache */
    private $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    public function testGetSetDelete(): void
    {
        $this->assertNull($this->cache->get('k'));
        $this->assertTrue($this->cache->set('k', 'v'));
        $this->assertSame('v', $this->cache->get('k'));
        $this->assertTrue($this->cache->delete('k'));
        $this->assertNull($this->cache->get('k'));
    }

    public function testTtlExpires(): void
    {
        $this->cache->set('k', 'v', 1);
        $this->cache->rewind(2); // 测试钩子：把时钟拨快 2 秒
        $this->assertNull($this->cache->get('k'));
    }
}