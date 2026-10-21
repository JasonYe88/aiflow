<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 框架异常根（marker 接口）
 * 宿主 catch (AIFlowException $e) 一个类型即可捕获框架全部异常，
 * 无需逐个罗列具体异常类；具体类型按需 instanceof 精细分派
 */
interface AIFlowException extends \Throwable
{
}