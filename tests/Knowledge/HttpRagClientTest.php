<?php
declare(strict_types=1);

namespace AIFlow\Tests\Knowledge;

use AIFlow\Knowledge\HttpRagClient;
use AIFlow\Knowledge\HttpRagRetriever;
use AIFlow\Support\HttpResponse;
use AIFlow\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

class HttpRagClientTest extends TestCase
{
    public function testIndexAndRetrieveRoundTripViaFakeHttp(): void
    {
        $fake = new FakeHttpClient();
        $fake->queue(new HttpResponse(200, json_encode(['ok' => true, 'count' => 1])));
        $fake->queue(new HttpResponse(200, json_encode([
            'hits' => [[
                'chunk_id' => 'c1',
                'content' => '退货三个工作日到账',
                'score' => 0.88,
                'document_id' => 'd1',
                'base_id' => 'b1',
                'title' => 'FAQ',
            ]],
        ])));

        $client = new HttpRagClient($fake, 'http://rag.test', 'secret');
        $indexed = $client->index('b1', [
            ['chunk_id' => 'c1', 'content' => '退货三个工作日到账', 'document_id' => 'd1', 'title' => 'FAQ'],
        ]);
        $this->assertTrue($indexed['ok']);

        $retriever = new HttpRagRetriever($client);
        $hits = $retriever->retrieve('b1', '退货到账', ['top_k' => 3]);
        $this->assertCount(1, $hits);
        $this->assertSame('c1', $hits[0]['chunk_id']);
        $this->assertSame(0.88, $hits[0]['score']);

        $this->assertSame('POST', $fake->requests[0]['method']);
        $this->assertStringContainsString('/v1/index', $fake->requests[0]['url']);
        $this->assertContains('Authorization: Bearer secret', $fake->requests[0]['headers']);
        $this->assertStringContainsString('/v1/retrieve', $fake->requests[1]['url']);
    }

    public function testNon2xxThrowsRagException(): void
    {
        $fake = new FakeHttpClient();
        $fake->queue(new HttpResponse(503, '{"error":"down"}'));
        $client = new HttpRagClient($fake, 'http://rag.test');
        $this->expectException(\AIFlow\Exception\RagException::class);
        $client->health();
    }
}
