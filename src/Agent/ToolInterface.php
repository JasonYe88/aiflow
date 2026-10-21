<?php
declare(strict_types=1);

namespace AIFlow\Agent;

/**
 * Agent 工具契约：一个工具 = 一个 PHP 类
 *
 * 宿主/插件实现本接口后 $runtime->register($tool) 即可被模型自主调用：
 * - name 汇入 function name（勿用中文/空格）；一家工具一个 name
 * - description 决定模型"何时想到用你"，写清场景与边界
 * - schema 遵循 JSON Schema 的 parameters 形态（OpenAI 协议直接透传）
 * - execute 返回 string 或 array（统一 JSON 回填给模型；自行控制体积，超长自行截断）
 */
interface ToolInterface
{
    /** 工具唯一名（喂给模型的 function name，英文 snake_case） */
    public function name(): string;

    /** 一句话用途描述（模型据此决定是否调用——这是提示工程的一部分） */
    public function description(): string;

    /** JSON Schema 参数声明（associative array，形如 {type:object, properties:{...}}） */
    public function schema(): array;

    /**
     * 执行工具
     *
     * @param array $args 模型给出的参数（已 JSON 解码；非法 JSON 容错为 []）
     * @return mixed string 或可 json_encode 的结构
     * @throws \Throwable 业务失败建议抛异常（由运行时转为错误性 tool 结果回填模型，不炸整个 run）
     */
    public function execute(array $args);
}