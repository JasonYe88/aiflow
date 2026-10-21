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
