<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 网络层异常（连接失败/超时等），非 HTTP 状态码错误
 */
class HttpException extends ModelCallException
{
}
