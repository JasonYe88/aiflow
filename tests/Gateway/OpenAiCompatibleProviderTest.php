<?php
declare(strict_types=1);

namespace AIFlow\Tests\Gateway;

use AIFlow\Exception\ModelCallException;
use AIFlow\Gateway\OpenAiCompatibleProvider;
use AIFlow\Support\HttpResponse;
use AIFlow\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

class OpenAiCompatibleProviderTest extends TestCase
{
    /** @var FakeHttpClient */
    private $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    private function provider(): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider('https://api.deepseek.com', 'sk-test', 'deepseek-chat', $this->http);
    }

    public function testChatParsesContentAndUsage(): void
    {
        $this->http->queue(new HttpResponse(200, json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => '你好，我是 DeepSeek']]],
            'model' => 'deepseek-chat',
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ], JSON_UNESCAPED_UNICODE)));

        $result = $this->provider()->chat([['role' => 'user', 'content' => '你好']]);

        $this->assertSame('你好，我是 DeepSeek', $result->content);
        $this->assertSame('deepseek-chat', $result->model);
        $this->assertSame(11, $result->inputTokens);
        $this->assertSame(7, $result->outputTokens);
        $this->assertGreaterThan(0, $result->elapsedMs);
    }

    public function testChatSendsOpenAiShapedRequest(): void
    {
        $this->http->queue(new HttpResponse(200, json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => [],
        ])));

        $this->provider()->chat([['role' => 'user', 'content' => 'hi']], ['temperature' => 0.5]);

        $req = $this->http->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $req['url']);
        $this->assertContains('Authorization: Bearer sk-test', $req['headers']);
        $payload = json_decode($req['body'], true);
        $this->assertSame('deepseek-chat', $payload['model']);
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $payload['messages']);
        $this->assertFalse($payload['stream']);
        $this->assertSame(0.5, $payload['temperature']);
    }

    public function testChatThrowsWithVendorBodyOnHttpError(): void
    {
        $this->http->queue(new HttpResponse(401, '{"error":{"message":"Authentication Fails"}}'));

        try {
            $this->provider()->chat([['role' => 'user', 'content' => 'hi']]);
            $this->fail('应抛 ModelCallException');
        } catch (ModelCallException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('Authentication Fails', $e->responseBody);
        }
    }
}
