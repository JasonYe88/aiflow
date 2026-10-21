<?php
declare(strict_types=1);

namespace AIFlow\Contract;

use AIFlow\Support\ModelResult;

/**
 * 模型提供方契约：一个实现对应一种接入协议（openai 兼容/claude/自有代理…）
 * 实现负责协议解析与错误归一（非 2xx → ModelCallException）
 */
interface ModelProviderInterface
{
    /**
     * 非流式对话
     *
     * @param array $messages OpenAI 消息格式 [["role"=>"user","content"=>"…"], …]
     * @param array $options  可选参数：temperature 等，透传给厂商
     */
    public function chat(array $messages, array $options = []): ModelResult;

    /**
     * 流式对话：增量实时回调 $onDelta($deltaText)，结束后返回完整结果
     * 为什么用回调不用 Generator：回调在数据到达时同步执行（curl WRITEFUNCTION），
     * Generator 会先读完响应再 yield，失去流式意义
     *
     * @param callable $onDelta function(string $delta): void
     */
    public function chatStream(array $messages, array $options, callable $onDelta): ModelResult;
}
