# AIFlow M1（模型网关 + 对话 + 流式）实施计划

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 完成 M1：OpenAI 兼容模型网关（DeepSeek 首发）+ ChatService 会话/消息 + SSE 流式输出，并在 FMS 里挂载 aiflow、交付一个可真实对话的 demo 页面。

**Architecture:** aiflow 侧新增三块——`HttpClient` 契约（curl 实现 + 测试用 Fake）、`OpenAiCompatibleProvider`（非流式解析 JSON / 流式解析 SSE，流式用回调而非 Generator，贴合 curl WRITEFUNCTION 实时语义）、`ChatService`（复用 M0 的 ai_conversation/ai_message 表）+ `SseEmitter`（FPM 流式输出）。FMS 侧零侵入挂载：SPL 自动加载引导文件 + 独立配置文件 + demo 控制器（沿用现有 demo 的内联 HTML 风格）。

**Tech Stack:** PHP 7.4+、ext-curl、ext-json、PDO；FMS 侧 OF 框架 demo 控制器惯例 + jQuery/原生 JS fetch 流式消费。

**设计依据:** `docs/plans/2026-10-21-aiflow-llmops-framework-design.md`；M0 已完成（12 commits，含 MyISAM/InnoDB 修复）。

**已确认决策:**

1. **流式用回调不用 Generator**：`chatStream($messages, $options, callable $onDelta): ModelResult`——回调在 curl WRITEFUNCTION 触发时实时执行，天然贴合 SSE 直通；Generator 方案会把整段响应读完才 yield，失去流式意义
2. **HTTP 层抽象成契约**：Provider 依赖 `HttpClientInterface`，测试注入 `FakeHttpClient`（免网络），生产用 `CurlHttpClient`
3. **FMS 零侵入挂载**：SPL 自动加载引导文件 `include/aiflowBootstrap.php`（FMS 无 Composer），aiflow 表放**独立库** `aiflow_fms`（默认本机 phpstudy MySQL root/root），不动 FMS 的 `fms` 库
4. **demo 页面**：`ctrl/demo/jasonYe/aiChatDemo.php`，沿用现有 demo 的 echo 内联 HTML 风格；页面与 SSE 动作同控制器；M1 不接 FMS 登录态/权限（userId 固定 `demo_user`，M2 再接）
5. **DeepSeek API**：`https://api.deepseek.com/chat/completions`，Bearer 鉴权，OpenAI 兼容响应；流式时 usage 在最后一个 chunk（缺省时 tokens 记 0，不阻塞）
6. **PHP 7.4 红线**同 M0（禁 enum/readonly/named args/match/union types/constructor promotion）

**环境约定:**

- aiflow 仓库：`D:\phpstudy_pro\WWW\aiflow`（HEAD = `d60f23d`）；FMS 仓库：`d:\phpstudy_pro\WWW\fms`
- Windows PowerShell：命令连接用 `;` 不用 `&&`；本地 PHP 8.0.2（php.ini 已启用 pdo_sqlite/pdo_mysql，curl 一般已启用，用 `php -m` 验证）
- 本机 MySQL：127.0.0.1:3306 root/root（M0 已验证）
- 所有 aiflow 任务 TDD：红 → 绿 → commit；FMS 任务为集成验证型

---

### Task 1: HTTP 层（契约 + curl 实现）

**Files:**
- Create: `src/Contract/HttpClientInterface.php`
- Create: `src/Support/HttpResponse.php`
- Create: `src/Adapter/CurlHttpClient.php`
- Create: `src/Exception/HttpException.php`
- Create: `src/Exception/ModelCallException.php`
- Modify: `composer.json`（require 加 `"ext-curl": "*"`）
- Test: `tests/Adapter/CurlHttpClientTest.php`（opt-in live）

**Step 1: 定义契约与值类**

`src/Contract/HttpClientInterface.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Contract;

use AIFlow\Support\HttpResponse;

/**
 * HTTP 客户端契约：模型网关对外的网络依赖全部走此接口
 * 测试注入 FakeHttpClient 免网络；生产用 CurlHttpClient
 */
interface HttpClientInterface
{
    /**
     * 同步请求，返回完整响应
     *
     * @param string $method  大写方法名，如 POST
     * @param string $url     完整 URL
     * @param string[] $headers 头行数组，如 ["Authorization: Bearer x", "Content-Type: application/json"]
     * @param string $body    请求体（空串表示无 body）
     * @return HttpResponse
     */
    public function request(string $method, string $url, array $headers, string $body): HttpResponse;

    /**
     * 流式请求：响应体分片到达时实时回调 $onChunk($chunk)，结束后返回完整响应
     * 回调在数据到达时同步执行（贴合 curl WRITEFUNCTION），这是流式能力的基石
     *
     * @param callable $onChunk function(string $chunk): void
     * @return HttpResponse 完整响应（body 为全部分片拼接）
     */
    public function requestStream(string $method, string $url, array $headers, string $body, callable $onChunk): HttpResponse;
}
```

`src/Support/HttpResponse.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Support;

/**
 * HTTP 响应值对象
 */
final class HttpResponse
{
    /** @var int 状态码 */
    public $statusCode;

    /** @var string 响应体 */
    public $body;

    public function __construct(int $statusCode, string $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }
}
```

`src/Exception/ModelCallException.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 模型调用异常：携带厂商原始错误（状态码 + 响应体）
 */
class ModelCallException extends \RuntimeException
{
    /** @var int */
    public $statusCode;

    /** @var string */
    public $responseBody;

    public function __construct(string $message, int $statusCode = 0, string $responseBody = '')
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }
}
```

`src/Exception/HttpException.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 网络层异常（连接失败/超时等），非 HTTP 状态码错误
 */
class HttpException extends ModelCallException
{
}
```

**Step 2: 实现 CurlHttpClient**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Adapter;

use AIFlow\Contract\HttpClientInterface;
use AIFlow\Exception\HttpException;
use AIFlow\Support\HttpResponse;

/**
 * 基于 ext-curl 的 HTTP 客户端
 * 通过 tests/Adapter/CurlHttpClientTest.php（可选 live）验证
 */
final class CurlHttpClient implements HttpClientInterface
{
    /** @var int 请求超时秒数 */
    private $timeout;

    public function __construct(int $timeout = 120)
    {
        $this->timeout = $timeout;
    }

    public function request(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $fullBody = '';
        $status = 0;
        $this->exec($method, $url, $headers, $body, null, $status, $fullBody);
        return new HttpResponse($status, $fullBody);
    }

    public function requestStream(string $method, string $url, array $headers, string $body, callable $onChunk): HttpResponse
    {
        $fullBody = '';
        $status = 0;
        $onChunkWrapper = function (string $ch, string $chunk) use ($onChunk, &$fullBody) {
            $fullBody .= $chunk;
            call_user_func($onChunk, $chunk);
            return strlen($chunk); // 必须返回长度，否则传输中断
        };
        $this->exec($method, $url, $headers, $body, $onChunkWrapper, $status, $fullBody);
        return new HttpResponse($status, $fullBody);
    }

    /**
     * curl 执行公共路径
     *
     * @param callable|null $onChunkWrapper null=不同步回调；非 null=WRITEFUNCTION
     * @param int $status 输出参数：状态码
     * @param string $fullBody 输出参数：完整响应体
     * @throws HttpException 连接失败/超时
     */
    private function exec(string $method, string $url, array $headers, string $body, ?callable $onChunkWrapper, int &$status, string &$fullBody): void
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => $onChunkWrapper === null,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($body !== '') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($onChunkWrapper !== null) {
            $opts[CURLOPT_WRITEFUNCTION] = $onChunkWrapper;
        }
        curl_setopt_array($ch, $opts);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($result === false) {
            throw new HttpException("HTTP 请求失败（curl {$errno}）：{$error}");
        }
        if ($onChunkWrapper === null) {
            $fullBody = (string)$result;
        }
    }
}
```

**Step 3: composer.json require 加 ext-curl，写 opt-in live 测试**

`tests/Adapter/CurlHttpClientTest.php`：

```php
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
```

`examples/httpEcho.php`（配合 live 测试的 echo 服务）：

```php
<?php
declare(strict_types=1);

/**
 * 最简 echo 服务：原样返回请求体，供 curl live 测试
 * 运行：php -S 127.0.0.1:18099 examples/httpEcho.php
 */

header('Content-Type: application/json');
echo file_get_contents('php://input');
```

**Step 4: 验证与提交**

Run: `composer test`（新测试 skipped，旧 19 tests 不破）
Run: `php -S 127.0.0.1:18099 examples/httpEcho.php`（后台起）+ 设 `$env:AIFLOW_TEST_HTTP_URL='http://127.0.0.1:18099'` 再跑 → 2 个 live 测试通过后停掉服务
Commit: `feat: http client contract with curl adapter, live-tested via local echo server`

---

### Task 2: 模型层契约 + ModelResult 值类

**Files:**
- Create: `src/Contract/ModelProviderInterface.php`
- Create: `src/Support/ModelResult.php`

**Step 1: 定义契约（机械任务）**

`src/Contract/ModelProviderInterface.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Contract;

use AIFlow\Support\ModelResult;

/**
 * 模型提供方契约：一个实现对应一种接入协议（openai 兼容/claude/自有代理…）
 * 实现负责协议解析与错误归一（非 2xx → ModelCallException）
 */
interface ModelProviderInterface
{
    /**
     * 非流式对话
     *
     * @param array $messages OpenAI 消息格式 [["role"=>"user","content"=>"…"], …]
     * @param array $options  可选参数：temperature 等，透传给厂商
     */
    public function chat(array $messages, array $options = []): ModelResult;

    /**
     * 流式对话：增量实时回调 $onDelta($deltaText)，结束后返回完整结果
     * 为什么用回调不用 Generator：回调在数据到达时同步执行（curl WRITEFUNCTION），
     * Generator 会先读完响应再 yield，失去流式意义
     *
     * @param callable $onDelta function(string $delta): void
     */
    public function chatStream(array $messages, array $options, callable $onDelta): ModelResult;
}
```

`src/Support/ModelResult.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Support;

/**
 * 模型调用结果值对象（M2 积分计量直接消费 inputTokens/outputTokens）
 */
final class ModelResult
{
    /** @var string 完整回复文本 */
    public $content;

    /** @var string 实际使用的模型名 */
    public $model;

    /** @var int 输入 token 数 */
    public $inputTokens;

    /** @var int 输出 token 数 */
    public $outputTokens;

    /** @var int 耗时毫秒 */
    public $elapsedMs;

    /** @var array 厂商原始响应（调试/审计用） */
    public $raw;

    public function __construct(string $content, string $model, int $inputTokens, int $outputTokens, int $elapsedMs, array $raw = [])
    {
        $this->content = $content;
        $this->model = $model;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->elapsedMs = $elapsedMs;
        $this->raw = $raw;
    }
}
```

**Step 2: 验证与提交**

Run: `composer test`（纯新增接口/值类，现有测试不变绿即可）
Commit: `feat: model provider contract and result value object`

---

### Task 3: FakeHttpClient + OpenAiCompatibleProvider 非流式（TDD）

**Files:**
- Create: `tests/Support/FakeHttpClient.php`
- Create: `src/Gateway/OpenAiCompatibleProvider.php`
- Test: `tests/Gateway/OpenAiCompatibleProviderTest.php`

**Step 1: 写测试基建 FakeHttpClient**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Support;

use AIFlow\Contract\HttpClientInterface;
use AIFlow\Support\HttpResponse;

/**
 * 测试用 HTTP 假客户端：免网络
 * queue() 排入响应；requests[] 记录收到的请求供断言
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array[] 已发生的请求 [{method,url,headers,body}] */
    public $requests = [];

    /** @var array 排队中的响应（先进先出），元素：HttpResponse 或 \Throwable */
    private $queue = [];

    public function queue(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function queueThrowable(\Throwable $e): void
    {
        $this->queue[] = $e;
    }

    public function request(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next === null) {
            throw new \LogicException('FakeHttpClient 队列空了：测试没排入足够的响应');
        }
        return $next;
    }

    public function requestStream(string $method, string $url, array $headers, string $body, callable $onChunk): HttpResponse
    {
        $resp = $this->request($method, $url, $headers, $body);
        // 模拟分片到达：把 body 切成 2 段实时回调
        $mid = (int)ceil(strlen($resp->body) / 2);
        foreach ([substr($resp->body, 0, $mid), substr($resp->body, $mid)] as $chunk) {
            if ($chunk !== '') {
                call_user_func($onChunk, $chunk);
            }
        }
        return $resp;
    }
}
```

**Step 2: 写失败测试（非流式部分）**

`tests/Gateway/OpenAiCompatibleProviderTest.php`：

```php
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
```

**Step 3: 跑测试确认红灯**

Run: `composer test`
Expected: FAIL——`Class "AIFlow\Gateway\OpenAiCompatibleProvider" not found`

**Step 4: 实现非流式部分（流式方法先抛 LogicException 占位，Task 4 实现）**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Gateway;

use AIFlow\Contract\HttpClientInterface;
use AIFlow\Contract\ModelProviderInterface;
use AIFlow\Exception\ModelCallException;
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
            (int)round((microtime(true) - $start) * 1000),
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
```

**Step 5: 跑测试确认绿灯（占位的 chatStream 无测试覆盖，不报错即可）**

Run: `composer test`
Expected: PASS（新增 3 tests）

**Step 6: Commit**

```powershell
git add -A; git commit -m "feat: openai-compatible provider non-stream chat with fake http client"
```

---

### Task 4: OpenAiCompatibleProvider 流式 SSE 解析（TDD）

**Files:**
- Modify: `src/Gateway/OpenAiCompatibleProvider.php`（实现 chatStream）
- Test: `tests/Gateway/OpenAiCompatibleProviderTest.php`（追加用例）

**Step 1: 追加失败测试**

```php
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

    public function testChatStreamSendsStreamTrueAndParsesSplitChunks(): void
    {
        // 关键覆盖：SSE 事件可能被网络分片拆开，解析器必须按行缓冲拼接
        $this->http->queue(new HttpResponse(200, $this->sseBody(['A', 'B'])));

        $this->provider()->chatStream([['role' => 'user', 'content' => 'hi']], [], function () {});

        $payload = json_decode($this->http->requests[0]['body'], true);
        $this->assertTrue($payload['stream']);
    }

    public function testChatStreamHandlesMissingUsage(): void
    {
        $this->http->queue(new HttpResponse(200, $this->sseBody(['X'], []))); // 无 usage chunk

        $result = $this->provider()->chatStream([['role' => 'user', 'content' => 'hi']], [], function () {});

        $this->assertSame('X', $result->content);
        $this->assertSame(0, $result->inputTokens);
        $this->assertSame(0, $result->outputTokens);
    }

    public function testChatStreamThrowsOnHttpError(): void
    {
        $this->http->queue(new HttpResponse(500, '{"error":{"message":"boom"}}'));

        $this->expectException(ModelCallException::class);
        $this->provider()->chatStream([['role' => 'user', 'content' => 'hi']], [], function () {});
    }
```

注意：`FakeHttpClient::requestStream` 把 body 对半切成 2 个 chunk 回调，天然覆盖“SSE 事件被拆开”场景——解析器必须跨 chunk 缓冲拼行。

**Step 2: 跑测试确认红灯**

Run: `composer test`
Expected: FAIL——`LogicException: 流式实现见 M1 Task 4`

**Step 3: 实现 chatStream（替换占位）**

```php
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
            json_encode($payload, JSON_UNESCAPED_UNICODE),
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
                        continue; // 结束标记，剩余 buffer 已无有效内容
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
            (int)round((microtime(true) - $start) * 1000),
            ['stream' => true, 'body_length' => strlen($resp->body)]
        );
    }
```

**Step 4: 跑测试确认绿灯**

Run: `composer test`
Expected: PASS（累计新增 7 tests）

**Step 5: Commit**

```powershell
git add -A; git commit -m "feat: sse stream parsing for openai-compatible provider, cross-chunk buffered"
```

---

### Task 5: ModelGateway 注册表 + Kernel 装配（TDD）

**Files:**
- Create: `src/Gateway/ModelGateway.php`
- Modify: `src/AIKernel.php`
- Test: `tests/Gateway/ModelGatewayTest.php`
- Test: `tests/KernelBootTest.php`（追加装配用例）

**Step 1: 写失败测试**

`tests/Gateway/ModelGatewayTest.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Gateway;

use AIFlow\Gateway\ModelGateway;
use AIFlow\Support\ModelResult;
use PHPUnit\Framework\TestCase;

class ModelGatewayTest extends TestCase
{
    public function testChatRoutesToRegisteredProvider(): void
    {
        $gateway = new ModelGateway();
        $gateway->add('deepseek', new class implements \AIFlow\Contract\ModelProviderInterface {
            public function chat(array $messages, array $options = []): ModelResult
            {
                return new ModelResult('来自假提供方', 'fake', 1, 2, 3);
            }
            public function chatStream(array $messages, array $options, callable $onDelta): ModelResult
            {
                call_user_func($onDelta, '来自');
                call_user_func($onDelta, '假提供方');
                return new ModelResult('来自假提供方', 'fake', 1, 2, 3);
            }
        });

        $this->assertSame('来自假提供方', $gateway->chat('deepseek', [['role' => 'user', 'content' => 'hi']])->content);

        $deltas = [];
        $result = $gateway->chatStream('deepseek', [['role' => 'user', 'content' => 'hi']], [], function ($d) use (&$deltas) {
            $deltas[] = $d;
        });
        $this->assertSame(['来自', '假提供方'], $deltas);
        $this->assertSame('来自假提供方', $result->content);
    }

    public function testUnknownModelThrows(): void
    {
        $gateway = new ModelGateway();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未注册的模型渠道：foo');
        $gateway->chat('foo', []);
    }
}
```

`tests/KernelBootTest.php` 追加：

```php
    public function testBootWiresGatewayWithModelConfig(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
            'models'  => [
                'deepseek' => [
                    'driver'  => 'openai',
                    'base_url' => 'https://api.deepseek.com',
                    'api_key'  => 'sk-test',
                    'model'    => 'deepseek-chat',
                ],
            ],
        ]);

        $gateway = $kernel->gateway();
        $this->assertInstanceOf(\AIFlow\Gateway\ModelGateway::class, $gateway);
        $this->assertSame($gateway, $kernel->gateway()); // 惰性单例
    }
```

**Step 2: 跑测试确认红灯 → Step 3: 实现**

`src/Gateway/ModelGateway.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Gateway;

use AIFlow\Contract\ModelProviderInterface;
use AIFlow\Support\ModelResult;

/**
 * 模型网关：渠道名 → 提供方的注册表，业务侧统一入口
 * $kernel->gateway()->chat('deepseek', $messages)
 */
final class ModelGateway
{
    /** @var ModelProviderInterface[] */
    private $providers = [];

    public function add(string $channel, ModelProviderInterface $provider): void
    {
        $this->providers[$channel] = $provider;
    }

    public function has(string $channel): bool
    {
        return isset($this->providers[$channel]);
    }

    /** @return string[] 已注册渠道名 */
    public function channels(): array
    {
        return array_keys($this->providers);
    }

    public function chat(string $channel, array $messages, array $options = []): ModelResult
    {
        return $this->provider($channel)->chat($messages, $options);
    }

    public function chatStream(string $channel, array $messages, array $options, callable $onDelta): ModelResult
    {
        return $this->provider($channel)->chatStream($messages, $options, $onDelta);
    }

    private function provider(string $channel): ModelProviderInterface
    {
        if (!isset($this->providers[$channel])) {
            throw new \InvalidArgumentException("未注册的模型渠道：{$channel}");
        }
        return $this->providers[$channel];
    }
}
```

`src/AIKernel.php` 增改（惰性属性 + 工厂 + 访问器）：

```php
    // 新增 use
    use AIFlow\Adapter\CurlHttpClient;
    use AIFlow\Gateway\ModelGateway;
    use AIFlow\Gateway\OpenAiCompatibleProvider;

    // 新增私有属性
    /** @var ModelGateway|null 惰性 */
    private $gateway;

    // 新增访问器
    public function gateway(): ModelGateway
    {
        if ($this->gateway === null) {
            $this->gateway = $this->makeGateway();
        }
        return $this->gateway;
    }

    // 新增工厂：models 配置 → 渠道注册（目前支持 driver=openai，M2+ 扩展更多驱动）
    private function makeGateway(): ModelGateway
    {
        $gateway = new ModelGateway();
        $http = new CurlHttpClient((int)$this->config->get('models.http_timeout', 120));
        foreach ((array)$this->config->get('models', []) as $channel => $conf) {
            if (($conf['driver'] ?? '') !== 'openai') {
                continue; // 未知驱动忽略，保持前向兼容
            }
            $gateway->add($channel, new OpenAiCompatibleProvider(
                (string)$conf['base_url'],
                (string)$conf['api_key'],
                (string)($conf['model'] ?? $channel),
                $http
            ));
        }
        return $gateway;
    }
```

**Step 4: 跑测试确认绿灯 → Step 5: Commit**

```powershell
git add -A; git commit -m "feat: model gateway registry with kernel lazy wiring"
```

---

### Task 6: ChatService 会话/消息（TDD）

**Files:**
- Create: `src/Chat/ChatService.php`
- Test: `tests/Chat/ChatServiceTest.php`

**Step 1: 写失败测试**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Chat;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Chat\ChatService;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class ChatServiceTest extends TestCase
{
    /** @var ChatService */
    private $chat;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->chat = new ChatService($storage);
    }

    public function testConversationLifecycle(): void
    {
        $cid = $this->chat->startConversation('demo_user', 'chat_demo', '第一个会话');
        $this->assertSame(32, strlen($cid));

        // 会话列表按 updated_at 倒序
        $this->chat->startConversation('demo_user', 'chat_demo', '第二个会话');
        $list = $this->chat->listConversations('demo_user');
        $this->assertCount(2, $list);
        $this->assertSame('第二个会话', $list[0]['title']);
        $this->assertSame('第一个会话', $list[1]['title']);

        // 别的用户看不到
        $this->assertCount(0, $this->chat->listConversations('other_user'));
    }

    public function testAppendAndHistory(): void
    {
        $cid = $this->chat->startConversation('demo_user', 'chat_demo');
        $this->chat->appendMessage($cid, 'user', '你好');
        $this->chat->appendMessage($cid, 'assistant', '你好，有什么可以帮你？', 11, 7, 560);

        $history = $this->chat->history($cid);
        $this->assertCount(2, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('你好', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertSame(11, (int)$history[1]['input_tokens']);
        $this->assertSame(7, (int)$history[1]['output_tokens']);
        $this->assertSame(560, (int)$history[1]['elapsed_ms']);

        // append 会话 updated_at 前移（列表排序依据）
        $this->chat->appendMessage($cid, 'user', '再来');
        $list = $this->chat->listConversations('demo_user');
        $this->assertSame($cid, $list[0]['id']);
    }

    public function testHistoryOfUnknownConversationIsEmpty(): void
    {
        $this->assertSame([], $this->chat->history('no_such_id'));
    }
}
```

**Step 2: 红灯 → Step 3: 实现**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Chat;

use AIFlow\Contract\StorageInterface;
use AIFlow\Support\Id;

/**
 * 会话/消息服务：对话的持久化层（表：ai_conversation / ai_message，M0 已建）
 * M2 的积分计量直接消费 appendMessage 的 token 字段
 */
final class ChatService
{
    /** @var StorageInterface */
    private $storage;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /** 开新会话，返回会话 ID */
    public function startConversation(string $userId, string $appId, string $title = ''): string
    {
        $id = Id::gen();
        $now = date('Y-m-d H:i:s');
        $this->storage->execute(
            'INSERT INTO ai_conversation (id, user_id, app_id, title, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $userId, $appId, $title, $now, $now]
        );
        return $id;
    }

    /** 用户会话列表（updated_at 倒序，最新在前） */
    public function listConversations(string $userId, int $limit = 50): array
    {
        return $this->storage->query(
            'SELECT id, title, created_at, updated_at FROM ai_conversation
             WHERE user_id = ? ORDER BY updated_at DESC LIMIT ' . (int)$limit,
            [$userId]
        );
    }

    /** 会话消息（时间正序），未知会话返回空数组 */
    public function history(string $conversationId, int $limit = 200): array
    {
        return $this->storage->query(
            'SELECT role, content, input_tokens, output_tokens, elapsed_ms, created_at FROM ai_message
             WHERE conversation_id = ? ORDER BY created_at ASC, id ASC LIMIT ' . (int)$limit,
            [$conversationId]
        );
    }

    /** 追加消息（user/assistant/system/tool），返回消息 ID */
    public function appendMessage(string $conversationId, string $role, string $content, int $inputTokens = 0, int $outputTokens = 0, int $elapsedMs = 0): string
    {
        $id = Id::gen();
        $this->storage->execute(
            'INSERT INTO ai_message (id, conversation_id, role, content, input_tokens, output_tokens, elapsed_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $conversationId, $role, $content, $inputTokens, $outputTokens, $elapsedMs, date('Y-m-d H:i:s')]
        );
        // 会话活跃度前移（列表排序依据）
        $this->storage->execute(
            'UPDATE ai_conversation SET updated_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $conversationId]
        );
        return $id;
    }
}
```

注意 `history` 的 `ORDER BY created_at ASC, id ASC`：id 是时间戳前缀的 hex，同秒内天然有序，双字段排序保证消息顺序稳定（SQLite/MySQL 双端行为一致）。

**Step 4: 绿灯 → Step 5: Commit**

```powershell
git add -A; git commit -m "feat: chat service over ai_conversation/ai_message tables"
```

---

### Task 7: SseEmitter + Kernel chat() 访问器 + CLI 示例（TDD）

**Files:**
- Create: `src/Chat/SseEmitter.php`
- Modify: `src/AIKernel.php`（加 chat() 访问器）
- Modify: `examples/`（新增 `examples/chat_stream.php`）
- Test: `tests/Chat/SseEmitterTest.php`

**Step 1: 写失败测试（CLI 下用输出缓冲捕获 echo）**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Chat;

use AIFlow\Chat\SseEmitter;
use PHPUnit\Framework\TestCase;

class SseEmitterTest extends TestCase
{
    public function testEventEmitsSseFrame(): void
    {
        ob_start();
        $emitter = new SseEmitter();
        $emitter->event('delta', '{"text":"你好"}');
        $out = ob_get_clean();

        $this->assertSame("event: delta\ndata: {\"text\":\"你好\"}\n\n", $out);
    }

    public function testEventEscapesNewlinesInData(): void
    {
        ob_start();
        $emitter = new SseEmitter();
        // SSE 规范：data 内的换行必须拆成多个 data: 行，否则帧被截断
        $emitter->event('done', "{\"text\":\"a\\nb\"}");
        $out = ob_get_clean();

        $this->assertStringNotContainsString("\ndata: {\"text\":\"a", $out . "\n");
    }

    public function testMultiLineDataRoundTrip(): void
    {
        $payload = ['text' => "第一行\n第二行"];
        $frame = SseEmitter::frame('delta', json_encode($payload, JSON_UNESCAPED_UNICODE));
        // 按规范解析回来应等价
        $lines = explode("\n", $frame);
        $this->assertSame('event: delta', $lines[0]);
        $data = '';
        foreach ($lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $data .= substr($line, 6);
            }
        }
        $this->assertSame($payload, json_decode($data, true));
    }
}
```

注意：`testEventEscapesNewlinesInData` 暗示 JSON 里的 `\n` 是转义序列（json_encode 自动转义换行），所以 frame 生成保持简单即可；`frame()` 静态方法是为了让「帧格式」可独立测试。

**Step 2: 红灯 → Step 3: 实现**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Chat;

/**
 * SSE 输出器（FPM 场景）：逐帧 echo + flush
 * Swoole 场景（M5+）提供同名契约的另一实现
 */
final class SseEmitter
{
    /**
     * 组装一帧 SSE（静态方法便于测试与复用）
     * json_encode 已把换行转义为 \n 序列，data 行内不会出现裸换行
     */
    public static function frame(string $event, string $data): string
    {
        return "event: {$event}\ndata: {$data}\n\n";
    }

    /** 发送 SSE 响应头并开始流（幂等，CLI 下 header 为 no-op） */
    public function start(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=UTF-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no'); // 关闭 nginx 缓冲
        }
        $this->flush();
    }

    /** 发送一帧事件并立刻刷出 */
    public function event(string $event, string $data): void
    {
        echo self::frame($event, $data);
        $this->flush();
    }

    public function close(): void
    {
        $this->flush();
    }

    /** 冲刷所有 PHP 输出缓冲区 + FPM flush */
    private function flush(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }
}
```

`src/AIKernel.php` 追加：

```php
    // use AIFlow\Chat\ChatService;
    /** @var ChatService|null 惰性 */
    private $chat;

    public function chat(): ChatService
    {
        if ($this->chat === null) {
            $this->chat = new ChatService($this->storage);
        }
        return $this->chat;
    }
```

`examples/chat_stream.php`（CLI 串流演示，走真实 DeepSeek）：

```php
<?php
declare(strict_types=1);

/**
 * M1 CLI 对话示例：php examples/chat_stream.php "你的问题"
 * 需要环境变量 AIFLOW_DEEPSEEK_KEY（真实 API key）；未设置则演示假渠道流式效果
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\AIKernel;
use AIFlow\Tests\Support\FakeHttpClient; // 示例复用测试假客户端做无 key 演示

$question = $argv[1] ?? '用一句话介绍你自己';
$apiKey = getenv('AIFLOW_DEEPSEEK_KEY') ?: '';

if ($apiKey !== '') {
    $kernel = AIKernel::boot([
        'storage' => ['dsn' => 'sqlite::memory:'],
        'models' => ['deepseek' => [
            'driver' => 'openai', 'base_url' => 'https://api.deepseek.com',
            'api_key' => $apiKey, 'model' => 'deepseek-chat',
        ]],
    ]);
} else {
    // 无 key 演示：注入假提供方跑通整条流式管线
    $kernel = AIKernel::boot(['storage' => ['dsn' => 'sqlite::memory:']]);
    $sse = implode("\n\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => '这是']]]], JSON_UNESCAPED_UNICODE),
        'data: ' . json_encode(['choices' => [['delta' => ['content' => '无 key 演示流。']]]], JSON_UNESCAPED_UNICODE),
        'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 5]]),
        'data: [DONE]',
    ]) . "\n\n";
    $fake = new FakeHttpClient();
    $fake->queue(new \AIFlow\Support\HttpResponse(200, $sse));
    $kernel->gateway()->add('deepseek', new \AIFlow\Gateway\OpenAiCompatibleProvider(
        'https://demo.local', 'sk-demo', 'deepseek-chat', $fake
    ));
    echo "[未检测到 AIFLOW_DEEPSEEK_KEY，走假渠道演示]\n";
}

echo "Q: {$question}\nA: ";
$result = $kernel->gateway()->chatStream(
    'deepseek',
    [['role' => 'user', 'content' => $question]],
    [],
    function (string $delta): void {
        echo $delta; // CLI 下逐段打印即"流式"
    }
);
echo "\n--- tokens: in={$result->inputTokens} out={$result->outputTokens} elapsed={$result->elapsedMs}ms ---\n";
```

**Step 4: 验证**

Run: `composer test`（全绿）
Run: `php examples/chat_stream.php "你好"`（无 key 演示路径必须出流式效果与 token 统计）
Run: `$env:AIFLOW_DEEPSEEK_KEY='sk-真实key'; php examples/chat_stream.php "你好"`（可选，有 key 才跑）

**Step 5: Commit**

```powershell
git add -A; git commit -m "feat: sse emitter, kernel chat accessor, cli stream example"
```

---

### Task 8: FMS 挂载（配置 + SPL 引导 + 建库建表验证）

**Files（fms 仓库）:**
- Create: `config/base/aiflow.php`
- Create: `include/aiflowBootstrap.php`
- Create: `ctrl/demo/jasonYe/aiflowCli.php`（CLI 验证入口）

**Step 1: 写配置文件 `config/base/aiflow.php`**

```php
<?php
/**
 * 类名 : 无（纯配置）
 * 描述 : aiflow 内核配置（M1 demo）
 *       storage 指向独立库 aiflow_fms（自动建库），不动 FMS 业务库 fms
 * User: jasonYe
 */

return [
    'storage' => [
        // 本机 phpstudy MySQL；生产环境改为对应机器的独立库
        'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=aiflow_fms;charset=utf8mb4',
        'user' => 'root',
        'pass' => 'root',
    ],
    'cache' => ['driver' => 'array'],
    'migration' => [
        'auto' => true,
        'dir'  => 'D:/phpstudy_pro/WWW/aiflow/migrations', // aiflow 仓库的迁移目录
    ],
    'models' => [
        'deepseek' => [
            'driver'   => 'openai',
            'base_url' => 'https://api.deepseek.com',
            // 优先读环境变量 AIFLOW_DEEPSEEK_KEY（避免 key 硬编码入库），其次可填这里
            'api_key'  => getenv('AIFLOW_DEEPSEEK_KEY') ?: '',
            'model'    => 'deepseek-chat',
        ],
    ],
];
```

**Step 2: 写引导文件 `include/aiflowBootstrap.php`**

```php
<?php
/**
 * 描述 : aiflow 内核引导（FMS 零侵入挂载）
 *       1. SPL 自动加载 AIFlow\ → ../aiflow/src（FMS 无 Composer，aiflow 是零依赖 PSR-4 包）
 *       2. aiflow_ensure_database() 首次调用自动建库 aiflow_fms
 *       3. aiflow_kernel() 惰性单例，进程内复用
 * 使用 : require_once ROOT_DIR . '/include/aiflowBootstrap.php';
 * User: jasonYe
 */

if (!defined('AIFLOW_SRC_DIR')) {
    define('AIFLOW_SRC_DIR', 'D:/phpstudy_pro/WWW/aiflow/src');

    spl_autoload_register(function (string $class): void {
        if (strpos($class, 'AIFlow\\') !== 0) {
            return;
        }
        $file = AIFLOW_SRC_DIR . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

/**
 * 描述 : 按 dsn 自动建库（aiflow 表所在库可能不存在）
 *
 * @param array $storageConf aiflow 配置的 storage 节
 */
function aiflow_ensure_database(array $storageConf): void
{
    $dsn = $storageConf['dsn'];
    if (strpos($dsn, 'mysql:') !== 0 || strpos($dsn, 'dbname=') === false) {
        return; // sqlite 或无 dbname 场景无需建库
    }
    $dbName = '';
    if (preg_match('/dbname=([^;]+)/', $dsn, $m)) {
        $dbName = $m[1];
    }
    // 去掉 dbname 的服务级连接
    $serverDsn = preg_replace('/[;]?dbname=[^;]+/', '', $dsn);
    $pdo = new PDO($serverDsn, $storageConf['user'], $storageConf['pass']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

/**
 * 描述 : aiflow 内核惰性单例
 *
 * @return \AIFlow\AIKernel
 */
function aiflow_kernel(): \AIFlow\AIKernel
{
    static $kernel = null;
    if ($kernel === null) {
        $conf = include ROOT_DIR . '/config/base/aiflow.php';
        aiflow_ensure_database($conf['storage']);
        $kernel = \AIFlow\AIKernel::boot($conf);
    }
    return $kernel;
}
```

**Step 3: 写 CLI 验证入口 `ctrl/demo/jasonYe/aiflowCli.php`**

```php
<?php

/**
 * 类名 : ctrl_demo_jasonYe_aiflowCli
 * 描述 : aiflow 挂载验证 CLI（M1）
 *       1. php ctrl/demo/jasonYe/aiflowCli.php migrate   建库建表
 *       2. php ctrl/demo/jasonYe/aiflowCli.php chat "你好"  假渠道流式冒烟
 * 地址 : 仅 CLI
 * User: jasonYe
 */
class ctrl_demo_jasonYe_aiflowCli extends L
{
    public function index()
    {
        $cmd = $_SERVER['argv'][1] ?? 'migrate';

        require_once ROOT_DIR . '/include/aiflowBootstrap.php';
        $kernel = aiflow_kernel();

        if ($cmd === 'migrate') {
            $applied = $kernel->migrate();
            $tables = $kernel->storage()->query("SHOW TABLES LIKE 'ai\\_%'");
            echo "applied: " . (empty($applied) ? '(已是最新)' : implode(', ', $applied)) . "\n";
            echo "ai_* tables: " . count($tables) . "（含 ai_migration）\n";
            foreach ($tables as $row) {
                echo "  - " . reset($row) . "\n";
            }
            return;
        }

        if ($cmd === 'chat') {
            $q = $_SERVER['argv'][2] ?? '你好';
            $key = getenv('AIFLOW_DEEPSEEK_KEY') ?: '';
            if ($key === '') {
                // 无 key 走假渠道冒烟（验证挂载与流式管线，不花 token）
                $sse = implode("\n\n", [
                    'data: ' . json_encode(['choices' => [['delta' => ['content' => '挂载成功，']]]], JSON_UNESCAPED_UNICODE),
                    'data: ' . json_encode(['choices' => [['delta' => ['content' => '流式管线正常。']]]], JSON_UNESCAPED_UNICODE),
                    'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 5]]),
                    'data: [DONE]',
                ]) . "\n\n";
                $fake = new AIFlow\Tests\Support\FakeHttpClient();
                $fake->queue(new AIFlow\Support\HttpResponse(200, $sse));
                $kernel->gateway()->add('deepseek', new AIFlow\Gateway\OpenAiCompatibleProvider(
                    'https://demo.local', 'sk-demo', 'deepseek-chat', $fake
                ));
            }
            $cid = $kernel->chat()->startConversation('demo_user', 'chat_demo', 'CLI 冒烟');
            $kernel->chat()->appendMessage($cid, 'user', $q);
            echo "A: ";
            $result = $kernel->gateway()->chatStream('deepseek', [['role' => 'user', 'content' => $q]], [], function (string $d): void {
                echo $d;
            });
            $kernel->chat()->appendMessage($cid, 'assistant', $result->content, $result->inputTokens, $result->outputTokens, $result->elapsedMs);
            echo "\n(conversation: {$cid}, tokens in/out = {$result->inputTokens}/{$result->outputTokens})\n";
            return;
        }

        echo "用法：php ctrl/demo/jasonYe/aiflowCli.php migrate|chat [问题]\n";
    }
}

return true;
```

注意：假渠道冒烟引用了 aiflow 的 tests/ 类（FakeHttpClient）。SPL 引导只映射 `AIFlow\` 前缀到 src/；测试类在 `AIFlow\Tests\` 前缀下同样以 `AIFlow\` 开头但物理路径在 tests/。引导文件需补一条：把 `AIFlow\Tests\` 前缀映射到 aiflow/tests/ 目录（demo 专用，正式功能不依赖测试类）：

```php
    spl_autoload_register(function (string $class): void {
        if (strpos($class, 'AIFlow\\Tests\\') === 0) {
            $file = AIFLOW_ROOT . '/tests/' . str_replace('\\', '/', substr($class, 13)) . '.php';
            if (is_file($file)) { require $file; }
            return;
        }
        if (strpos($class, 'AIFlow\\') !== 0) {
            return;
        }
        $file = AIFLOW_SRC_DIR . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) { require $file; }
    });
```

（`AIFLOW_ROOT` 定义为 `D:/phpstudy_pro/WWW/aiflow`；**此映射仅 demo 冒烟用，M2 起把假渠道挪进 aiflow 的 examples/Support 作为正式类**——记为 M1 遗留项，不扩散。）

**Step 4: 验证（CLI 实跑）**

```powershell
cd d:\phpstudy_pro\WWW\fms
php ctrl/demo/jasonYe/aiflowCli.php migrate
# 期望：applied: 001_init.sql；ai_* tables: 11（InnoDB 引擎）
php ctrl/demo/jasonYe/aiflowCli.php chat "你好"
# 期望：A: 挂载成功，流式管线正常。(conversation: <32位id>, tokens in/out = 3/5)
php -r "..." 验证 MySQL 库 aiflow_fms 里 ai_conversation 有 1 条、ai_message 有 2 条
```

**Step 5: Commit（fms 仓库）**

```powershell
git add config/base/aiflow.php include/aiflowBootstrap.php ctrl/demo/jasonYe/aiflowCli.php
git commit -m "feat(demo): aiflow M1 挂载——SPL 引导+独立库+CLI 冒烟入口"
```

---

### Task 9: FMS 对话 demo 页面 + SSE 端点

**Files（fms 仓库）:**
- Create: `ctrl/demo/jasonYe/aiChatDemo.php`

**实现要点（沿用现有 demo 的 echo 内联风格，单控制器三动作）：**

1. `index()`：对话页面。左栏会话列表（`a=conversations` JSON 拉取），右栏聊天气泡区 + 输入框 + 发送按钮；顶栏"新会话"按钮。原生 JS（fetch + ReadableStream 读 SSE，不用 EventSource——因为要 POST 消息体）
2. `chat()`：SSE 端点。流程：
   - `require_once ROOT_DIR . '/include/aiflowBootstrap.php';`
   - 输入：`conversationId`（空则 `startConversation('demo_user', 'chat_demo', 截断的消息前 20 字)`）、`message`
   - `appendMessage(user)` → `history()` 取最近 10 条作为上下文 → `gateway()->chatStream('deepseek', …, onDelta: emitter->event('delta', …))` → 完成后 `appendMessage(assistant, tokens, elapsed)` → `emitter->event('done', {conversationId, tokens})`
   - 异常：`ModelCallException` → `emitter->event('error', {message})` 后结束（HTTP 200 + error 事件，避免浏览器把流当成整页错误）
   - 响应头在 `SseEmitter::start()` 统一处理（X-Accel-Buffering: no）
3. `conversations()` + `history()`：JSON 输出（复用 syncEsProfitSku demo 的 `handle()` JSON 风格：code/info/data + try/catch）

前端 JS 核心逻辑（写进 index() 的内联 `<script>`）：

```js
async function send() {
    const msg = input.value.trim();
    if (!msg || busy) return;
    busy = true;
    appendBubble('user', msg);
    input.value = '';
    const bubble = appendBubble('assistant', '');
    const resp = await fetch(base + 'chat', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'conversationId=' + encodeURIComponent(currentCid) + '&message=' + encodeURIComponent(msg),
    });
    const reader = resp.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '', text = '';
    while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, {stream: true});
        let idx;
        while ((idx = buffer.indexOf('\n\n')) !== -1) {
            const frame = buffer.slice(0, idx); buffer = buffer.slice(idx + 2);
            let event = '', data = '';
            frame.split('\n').forEach(l => {
                if (l.startsWith('event: ')) event = l.slice(7);
                if (l.startsWith('data: ')) data += l.slice(6);
            });
            if (event === 'delta') { text += JSON.parse(data).text; bubble.textContent = text; }
            if (event === 'done') { currentCid = JSON.parse(data).conversationId; }
            if (event === 'error') { bubble.textContent = '[出错] ' + JSON.parse(data).message; }
        }
    }
    busy = false;
}
```

（气泡区自动滚底、发送中禁用按钮、assistant 气泡打字机效果即 delta 直渲——细节由实施者按 demo 风格补齐，上述为必须实现的最小闭环。）

**验证：**
- 页面能打开：`http://<本地fms>/index.php/?c=demo_jasonYe_aiChatDemo&a=index`
- `conversations`/`history` 返回 JSON
- 无 key 时点发送 → 应收到 error 事件（DeepSeek key 未配置）而不是 PHP 报错页——**这正是错误处理的验收点**
- 有 key（Task 10）→ 流式逐字输出

**Commit（fms 仓库）:**

```powershell
git add ctrl/demo/jasonYe/aiChatDemo.php
git commit -m "feat(demo): AI 对话 demo 页面——SSE 流式气泡+会话列表+历史上下文"
```

---

### Task 10: 端到端验收（真实 DeepSeek 对话）

**前置：** 用户提供 DeepSeek API key（设置环境变量 `AIFLOW_DEEPSEEK_KEY`，或填入 `config/base/aiflow.php`）

**验收清单（逐项人工/脚本验证）：**

- [ ] `php ctrl/demo/jasonYe/aiflowCli.php chat "用一句话介绍AIFlow"` → 真实 DeepSeek 流式回复 + token 统计 + 会话落库
- [ ] 浏览器打开 demo 页 → 连续多轮对话 → 逐字流式输出、上下文连贯（能记住上文）
- [ ] 刷新页面 → 会话列表还在、点开历史消息完整回放
- [ ] `ai_message` 表里 user/assistant 消息成对、tokens/elapsed 落库正确
- [ ] 断网/错 key 场景 → 页面收到 error 事件并优雅显示，不白屏
- [ ] aiflow 仓库 `composer test` 仍全绿；fms 侧无其它文件改动

**收尾：** aiflow README 能力矩阵更新（M1 全 ✅），两个仓库各自 commit。

---

## M1 验收总表

| 项 | 验收 |
|----|------|
| aiflow：OpenAI 兼容网关（非流式+流式） | Provider 测试全绿（Fake 驱动，免网络） |
| aiflow：curl live 测试 | echo 服务实测 request/requestStream |
| aiflow：ChatService + SseEmitter | 单测全绿 + CLI 示例跑通 |
| FMS：挂载 | SPL 引导 + 独立库 aiflow_fms + 11 张表（InnoDB） |
| FMS：demo 页面 | 流式对话/会话列表/历史回放/错误兜底 |
| 端到端 | 真实 DeepSeek key 多轮流式对话（Task 10） |

## M1 遗留（记入 backlog，不在本计划）

- 假渠道类从 aiflow/tests 挪到正式位置（M2 处理）
- FMS 登录态/权限接入（M2 AccessControl）
- 积分计量钩子（M2 CreditLedger，chatStream 返回的 ModelResult 已带 token 数，接口已就绪）
- Swoole StreamTransport 实现（M5+）