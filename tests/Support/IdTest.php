<?php
declare(strict_types=1);

namespace AIFlow\Tests\Support;

use AIFlow\Support\Id;
use PHPUnit\Framework\TestCase;

class IdTest extends TestCase
{
    public function testGenReturns32HexChars(): void
    {
        $id = Id::gen();
        $this->assertSame(32, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testGenUnique(): void
    {
        $this->assertNotSame(Id::gen(), Id::gen());
    }
}