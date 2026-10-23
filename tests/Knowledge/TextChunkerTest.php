<?php
declare(strict_types=1);

namespace AIFlow\Tests\Knowledge;

use AIFlow\Knowledge\TextChunker;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    public function testSplitByParagraphAndOverlap(): void
    {
        $c = new TextChunker();
        $chunks = $c->split("第一段内容足够长。\n\n第二段也在这里。", [
            'separator' => '\n\n',
            'max_length' => 500,
            'overlap' => 50,
        ]);
        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('第一段', $chunks[0]);
        $this->assertStringContainsString('第二段', $chunks[1]);
    }

    public function testMaxLengthWindowsWithOverlap(): void
    {
        $c = new TextChunker();
        $text = str_repeat('甲', 120);
        $chunks = $c->split($text, [
            'separator' => '\n\n',
            'max_length' => 50,
            'overlap' => 10,
            'clean_whitespace' => false,
        ]);
        $this->assertGreaterThan(1, count($chunks));
        $this->assertLessThanOrEqual(50, mb_strlen($chunks[0]));
    }

    public function testStripUrls(): void
    {
        $c = new TextChunker();
        $chunks = $c->split("访问 https://example.com 与 a@b.com 即可", [
            'strip_urls' => true,
            'max_length' => 500,
        ]);
        $this->assertStringNotContainsString('https://', $chunks[0]);
        $this->assertStringNotContainsString('a@b.com', $chunks[0]);
    }
}
