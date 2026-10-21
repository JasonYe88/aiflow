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
