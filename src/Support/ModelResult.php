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
