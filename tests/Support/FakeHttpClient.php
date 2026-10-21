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
