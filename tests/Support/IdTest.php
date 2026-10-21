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

    /**
     * 进程内严格单调递增：会话活跃度（updated_seq）与消息历史排序都依赖
     * "ORDER BY id ASC = 生成顺序"，同毫秒生成的 id 也必须有序
     */
    public function testGenStrictlyMonotonicEvenInSameMillisecond(): void
    {
        $prev = Id::gen();
        for ($i = 0; $i < 1000; $i++) {
            $next = Id::gen();
            $this->assertSame(-1, strcmp($prev, $next), "id 必须严格递增：{$prev} -> {$next}");
            $prev = $next;
        }
    }
}
