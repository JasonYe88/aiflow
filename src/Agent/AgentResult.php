<?php
declare(strict_types=1);

namespace AIFlow\Agent;

/**
 * Agent 运行结果
 *
 * 与 ModelResult 鸭子兼容（content/model/inputTokens/outputTokens/elapsedMs 同名同义）：
 * 宿主可直接把 function(): AgentRuntime 丢进 Billing::run —— 它只读这两个 token 字段。
 * inputTokens/outputTokens 为整轮循环的累计值。
 */
final class AgentResult
{
    /** @var string 最终回答文本 */
    public $content;

    /** @var string 实际使用的模型名 */
    public $model;

    /** @var int 全部轮次输入 token 累计 */
    public $inputTokens;

    /** @var int 全部轮次输出 token 累计 */
    public $outputTokens;

    /** @var int 整个循环耗时毫秒 */
    public $elapsedMs;

    /** @var int 实际对话轮数（含最后一次纯文本回答轮） */
    public $rounds;

    /** @var array 每轮工具调用轨迹 [{round, tool, args, output, ok}]（可观测/审计） */
    public $steps;

    public function __construct(
        string $content,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $elapsedMs,
        int $rounds,
        array $steps = []
    ) {
        $this->content = $content;
        $this->model = $model;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->elapsedMs = $elapsedMs;
        $this->rounds = $rounds;
        $this->steps = $steps;
    }
}