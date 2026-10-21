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

    /** M3：options 携带 tools/tool_choice 时须原样透传（function-calling 的前提） */
    public function testChatPassesToolsAndToolChoice(): void
    {
        $this->http->queue(new HttpResponse(200, json_encode([
            'choices' => [['message' => ['content' => 'ok', 'tool_calls' => []]]],
            'usage' => [],
        ])));

        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => '查询城市天气',
                'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ],
        ]];
        $this->provider()->chat([['role' => 'user', 'content' => '北京天气?']], [
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        $payload = json_decode($this->http->requests[0]['body'], true);
        $this->assertSame($tools, $payload['tools']);
        $this->assertSame('auto', $payload['tool_choice']);
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

    /** 构造一段 DeepSeek 风格的 SSE 响应体 */
    private function sseBody(array $deltas, array $usage = ['prompt_tokens' => 5, 'completion_tokens' => 6]): string
    {
        $lines = [];
        foreach ($deltas as $delta) {
            $lines[] = 'data: ' . json_encode([
                'choices' => [['delta' => ['content' => $delta], 'index' => 0]],
            ], JSON_UNESCAPED_UNICODE);
        }
        if ($usage !== []) {
            $lines[] = 'data: ' . json_encode(['choices' => [], 'usage' => $usage], JSON_UNESCAPED_UNICODE);
        }
        $lines[] = 'data: [DONE]';
        return implode("\n\n", $lines) . "\n\n";
    }

    public function testChatStreamYieldsDeltasLiveAndReturnsUsage(): void
    {
        $this->http->queue(new HttpResponse(200, $this->sseBody(['你好', '，', '世界'])));

        $received = [];
        $result = $this->provider()->chatStream(
            [['role' => 'user', 'content' => 'hi']],
            [],
            function (string $delta) use (&$received) {
                $received[] = $delta;
            }
        );

        // 增量实时到达（回调语义）
        $this->assertSame(['你好', '，', '世界'], $received);
        // 最终结果：拼接内容 + usage
        $this->assertSame('你好，世界', $result->content);
        $this->assertSame(5, $result->inputTokens);
        $this->assertSame(6, $result->outputTokens);
    }

    public function testChatStreamSendsStreamTrue(): void
    {
        // FakeHttpClient::requestStream 把 body 对半切成 2 个 chunk，
        // 天然覆盖「SSE 事件被网络分片拆开」——解析器必须跨 chunk 缓冲拼行
        $this->http->queue(new HttpResponse(200, $this->sseBody(['A', 'B'])));

        $this->provider()->chatStream([['role' => 'user', 'content' => 'hi']], [], function (): void {});

        $payload = json_decode($this->http->requests[0]['body'], true);
        $this->assertTrue($payload['stream']);
    }

    public function testChatStreamHandlesMissingUsage(): void
    {
        $this->http->queue(new HttpResponse(200, $this->sseBody(['X'], []))); // 无 usage chunk

        $result = $this->provider()->chatStream([['role' => 'user', 'content' => 'hi']], [], function (): void {});

        $this->assertSame('X', $result->content);
        $this->assertSame(0, $result->inputTokens);
        $this->assertSame(0, $result->outputTokens);
    }

    public function testChatStreamThrowsOnHttpError(): void
    {
        $this->http->queue(new HttpResponse(500, '{"error":{"message":"boom"}}'));

        $this->expectException(ModelCallException::class);
        $this->provider()->chatStream([['role' => 'user', 'content' => 'hi']], [], function (): void {});
    }
}
