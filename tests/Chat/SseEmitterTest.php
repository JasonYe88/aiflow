<?php
declare(strict_types=1);

namespace AIFlow\Tests\Chat;

use AIFlow\Chat\SseEmitter;
use PHPUnit\Framework\TestCase;

class SseEmitterTest extends TestCase
{
    public function testFrameBasic(): void
    {
        $this->assertSame("event: delta\ndata: 你好\n\n", SseEmitter::frame('delta', '你好'));
    }

    public function testFrameSplitsMultiLineData(): void
    {
        $this->assertSame(
            "event: delta\ndata: a\ndata: b\n\n",
            SseEmitter::frame('delta', "a\nb")
        );
    }

    public function testFrameNormalizesCrlf(): void
    {
        $this->assertSame(
            "event: delta\ndata: a\ndata: b\n\n",
            SseEmitter::frame('delta', "a\r\nb")
        );
    }

    public function testFrameWithoutEvent(): void
    {
        $this->assertSame("data: x\n\n", SseEmitter::frame('', 'x'));
    }

    /**
     * 回归测试：flush() 曾因 ob_flush 不弹层写成 while 死循环，
     * 此用例保证有活动输出缓冲时 flush 有界终止（且不改变缓冲层数）
     * 注：send() 含 ob_flush 冲刷语义，无法用 ob_start 捕获断言，帧格式由 frame() 用例覆盖
     */
    public function testFlushTerminatesWithActiveBuffers(): void
    {
        $before = ob_get_level(); // PHPUnit 自身可能已有缓冲层，只断言层数不变
        ob_start();
        SseEmitter::flush();
        $this->assertSame($before + 1, ob_get_level());
        ob_end_clean();
        $this->assertSame($before, ob_get_level());
    }
}
