<?php
declare(strict_types=1);

namespace AIFlow\Tests\Support;

use AIFlow\Contract\ModelProviderInterface;
use AIFlow\Support\ModelResult;

/**
 * 测试用脚本化模型提供方：按序吐预设响应，免网络
 * 脚本用尽后重复最后一个响应（模拟"一直要求调工具"的死循环场景）；
 * calls[] 记录每次收到的 (messages, options) 供断言循环回填结构
 */
final class ScriptedProvider implements ModelProviderInterface
{
    /** @var ModelResult[] 预设响应队列 */
    public $script = [];

    /** @var array 已发生的调用 [{messages, options}] */
    public $calls = [];

    /** @var int 当前脚本游标 */
    private $i = 0;

    public function script(ModelResult $result): void
    {
        $this->script[] = $result;
    }

    public function chat(array $messages, array $options = []): ModelResult
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];
        if ($this->script === []) {
            throw new \LogicException('ScriptedProvider 未编排响应');
        }
        $result = $this->script[min($this->i, count($this->script) - 1)];
        $this->i++;
        return $result;
    }

    public function chatStream(array $messages, array $options, callable $onDelta): ModelResult
    {
        throw new \LogicException('ScriptedProvider 不支持流式（AgentRuntime 循环不用流式）');
    }
}