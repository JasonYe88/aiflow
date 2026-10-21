<?php
declare(strict_types=1);

namespace AIFlow\Tests;

use AIFlow\AIKernel;
use PHPUnit\Framework\TestCase;

class KernelBootTest extends TestCase
{
    public function testBootReturnsKernelWithConfig(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
        ]);

        $this->assertInstanceOf(AIKernel::class, $kernel);
        $this->assertSame(['dsn' => 'sqlite::memory:'], $kernel->config()->get('storage'));
        $this->assertSame('array', $kernel->config()->get('cache.driver'));
        $this->assertNull($kernel->config()->get('not_exists'));
    }
}
