<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/**
 * Python RAG 桥 / HttpRagClient 调用失败
 */
class RagException extends \RuntimeException implements AIFlowException
{
}
