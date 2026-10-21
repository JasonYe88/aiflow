<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * 框架异常根（marker 接口）
 * 为什么用接口不用抽象类：不占用实现类的单继承槽——
 * 各异常可自由继承 \RuntimeException / \LogicException 等 SPL 基类，
 * 宿主捕获本接口即拿到全部框架异常
 */
interface AIFlowException
{
}