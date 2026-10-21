<?php
declare(strict_types=1);

namespace AIFlow\Gateway;

use AIFlow\Contract\HttpClientInterface;
use AIFlow\Contract\ModelProviderInterface;
use AIFlow\Exception\ModelCallException;
use AIFlow\Support\HttpResponse;
use AIFlow\Support\ModelResult;

/**
 * OpenAI 兼容协议提供方：DeepSeek / 通义 / Kimi 等 99% 国产与开源模型一个类通吃
 * 通过 tests/Gateway/OpenAiCompatibleProviderTest.php（FakeHttpClient）验证
 */
final class OpenAiCompatibleProvider implements ModelProviderInterface
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var HttpClientInterface */
    private $http;

    public function __construct(string $baseUrl, string $apiKey, string $model, HttpClientInterface $http)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->http = $http;
    }

    public function chat(array $messages, array $options = []): ModelResult
    {
        $start = microtime(true);
        $payload = $this->payload($messages, $options, false);
        $resp = $this->http->request('POST', $this->endpoint(), $this->headers(), json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertOk($resp);
        $data = $this->decode($resp->body);

        return new ModelResult(
            (string)($data['choices'][0]['message']['content'] ?? ''),
            (string)($data['model'] ?? $this->model),
            (int)($data['usage']['prompt_tokens'] ?? 0),
            (int)($data['usage']['completion_tokens'] ?? 0),
            // Windows 下 microtime 分辨率约 15ms，快速调用会取整为 0；保证 >=1ms
            (int)max(1, round((microtime(true) - $start) * 1000)),
            $data
        );
    }

    public function chatStream(array $messages, array $options, callable $onDelta): ModelResult
    {
        throw new \LogicException('流式实现见 M1 Task 4');
    }

    /** 组装请求体 */
    private function payload(array $messages, array $options, bool $stream): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => $stream,
        ];
        foreach (['temperature', 'max_tokens', 'top_p'] as $key) {
            if (isset($options[$key])) {
                $payload[$key] = $options[$key];
            }
        }
        return $payload;
    }

    private function endpoint(): string
    {
        return $this->baseUrl . '/chat/completions';
    }

    /** @return string[] */
    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /** 非 2xx 归一为 ModelCallException（携带厂商原始响应） */
    private function assertOk(HttpResponse $resp): void
    {
        if ($resp->statusCode < 200 || $resp->statusCode >= 300) {
            $msg = $resp->body;
            $decoded = json_decode($resp->body, true);
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $msg = (string)$decoded['error']['message'];
            }
            throw new ModelCallException(
                "模型调用失败 HTTP {$resp->statusCode}：{$msg}",
                $resp->statusCode,
                $resp->body
            );
        }
    }

    /** @return array */
    private function decode(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ModelCallException('模型响应不是合法 JSON：' . substr($body, 0, 200), 0, $body);
        }
        return $data;
    }
}
