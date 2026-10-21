<?php
declare(strict_types=1);

namespace AIFlow\Tests;

use AIFlow\AIKernel;
use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    public function testKernelClassExists(): void
    {
        $this->assertTrue(class_exists(AIKernel::class));
    }
}