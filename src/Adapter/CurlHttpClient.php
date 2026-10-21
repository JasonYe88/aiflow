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
        // 注意：$ch 不加类型声明——PHP 7.4 传 resource，PHP 8+ 传 CurlHandle 对象
        $onChunkWrapper = function ($ch, string $chunk) use ($onChunk, &$fullBody) {
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
    private function exec(string $method, string $url, array $headers, string $body, $onChunkWrapper, int &$status, string &$fullBody): void
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
