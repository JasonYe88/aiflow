<?php
declare(strict_types=1);

namespace AIFlow\Gateway;

use AIFlow\Contract\EmbeddingCapableInterface;
use AIFlow\Contract\HttpClientInterface;
use AIFlow\Contract\ModelProviderInterface;
use AIFlow\Exception\ModelCallException;
use AIFlow\Support\HttpResponse;
use AIFlow\Support\ModelResult;

/**
 * OpenAI 兼容协议提供方：DeepSeek / 通义 / Kimi 等 99% 国产与开源模型一个类通吃
 * 通过 tests/Gateway/OpenAiCompatibleProviderTest.php（FakeHttpClient）验证
 */
final class OpenAiCompatibleProvider implements ModelProviderInterface, EmbeddingCapableInterface
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

    public function embed(array $texts, array $options = []): array
    {
        if ($texts === []) {
            return [];
        }
        $input = array_values($texts);
        // 单条时多数兼容渠道接受 string；多条用数组
        $payload = [
            'model' => (string)($options['model'] ?? $this->model),
            'input' => count($input) === 1 ? $input[0] : $input,
        ];
        foreach (['dimensions', 'encoding_format'] as $key) {
            if (isset($options[$key])) {
                $payload[$key] = $options[$key];
            }
        }
        $resp = $this->http->request(
            'POST',
            $this->baseUrl . '/embeddings',
            $this->headers(),
            (string)json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
        $this->assertOk($resp);
        $data = $this->decode($resp->body);
        $rows = $data['data'] ?? null;
        if (!is_array($rows)) {
            throw new ModelCallException('embeddings 响应缺少 data', $resp->statusCode, $resp->body);
        }
        usort($rows, function ($a, $b) {
            return ((int)($a['index'] ?? 0)) <=> ((int)($b['index'] ?? 0));
        });
        $vectors = [];
        foreach ($rows as $row) {
            $emb = $row['embedding'] ?? null;
            if (!is_array($emb)) {
                throw new ModelCallException('embeddings 条目缺少 embedding', $resp->statusCode, $resp->body);
            }
            $vec = [];
            foreach ($emb as $v) {
                $vec[] = (float)$v;
            }
            $vectors[] = $vec;
        }
        if (count($vectors) !== count($input)) {
            throw new ModelCallException(
                'embeddings 条数与输入不一致：' . count($vectors) . '/' . count($input),
                $resp->statusCode,
                $resp->body
            );
        }
        return $vectors;
    }

    public function chatStream(array $messages, array $options, callable $onDelta): ModelResult
    {
        $start = microtime(true);
        $payload = $this->payload($messages, $options, true);
        $content = '';
        $usage = null;

        $resp = $this->http->requestStream(
            'POST',
            $this->endpoint(),
            $this->headers(),
            (string)json_encode($payload, JSON_UNESCAPED_UNICODE),
            function (string $chunk) use (&$content, &$usage, $onDelta): void {
                // SSE 按行解析：跨 chunk 缓冲，"data: {...}" 行有效，"[DONE]" 结束
                static $buffer = '';
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || strpos($line, 'data:') !== 0) {
                        continue; // 空行（事件分隔）与心跳/注释行
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') {
                        continue; // 结束标记
                    }
                    $json = json_decode($data, true);
                    if (!is_array($json)) {
                        continue; // 容错：坏行不炸流
                    }
                    $delta = (string)($json['choices'][0]['delta']['content'] ?? '');
                    if ($delta !== '') {
                        $content .= $delta;
                        call_user_func($onDelta, $delta);
                    }
                    if (isset($json['usage']) && is_array($json['usage'])) {
                        $usage = $json['usage']; // DeepSeek 在最后一个 chunk 带 usage
                    }
                }
            }
        );

        $this->assertOk($resp);

        return new ModelResult(
            $content,
            $this->model,
            (int)($usage['prompt_tokens'] ?? 0),
            (int)($usage['completion_tokens'] ?? 0),
            (int)max(1, round((microtime(true) - $start) * 1000)),
            ['stream' => true, 'body_length' => strlen($resp->body)]
        );
    }

    /** 组装请求体 */
    private function payload(array $messages, array $options, bool $stream): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => $stream,
        ];
        // tools/tool_choice：M3 function-calling 透传（OpenAI 协议，国产兼容渠道通用）
        foreach (['temperature', 'max_tokens', 'top_p', 'stream_options', 'tools', 'tool_choice'] as $key) {
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
