<?php
declare(strict_types=1);

namespace AIFlow\Tests\Adapter;

use AIFlow\Adapter\CurlHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * live 测试：设置 AIFLOW_TEST_HTTP_URL 时才跑（指向本地 echo 服务），否则跳过
 * 生产可用 php -S 127.0.0.1:18099 examples/httpEcho.php 起一个 echo 服务配合
 */
class CurlHttpClientTest extends TestCase
{
    /** @var string|null */
    private $url;

    protected function setUp(): void
    {
        $url = getenv('AIFLOW_TEST_HTTP_URL');
        $this->url = ($url === false || $url === '') ? null : $url;
        if ($this->url === null) {
            $this->markTestSkipped('未设置 AIFLOW_TEST_HTTP_URL，跳过 curl live 测试');
        }
    }

    public function testRequestReturnsEchoedBody(): void
    {
        $client = new CurlHttpClient(5);
        $resp = $client->request('POST', $this->url, ['Content-Type: application/json'], '{"ping":1}');
        $this->assertSame(200, $resp->statusCode);
        $this->assertStringContainsString('{"ping":1}', $resp->body);
    }

    public function testRequestStreamCallsOnChunk(): void
    {
        $client = new CurlHttpClient(5);
        $chunks = [];
        $resp = $client->requestStream('POST', $this->url, ['Content-Type: application/json'], '{"ping":1}', function (string $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });
        $this->assertSame(200, $resp->statusCode);
        $this->assertNotEmpty($chunks);
        $this->assertSame(implode('', $chunks), $resp->body);
    }
}
