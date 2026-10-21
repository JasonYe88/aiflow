<?php
declare(strict_types=1);

namespace AIFlow\Workflow;

/**
 * 节点值对象：工厂方法即四类内置节点的全部语义（不可变）
 *
 * - task：业务 PHP 类（TaskInterface），支持 retries 重试
 * - decision：读上下文字段取值跳转（分支映射未命中 → 顺序走下一节点）
 * - llm：经 ModelGateway 调模型，回复文本作为产物写入 context[节点key]
 * - parallel：多分支隔离执行，各分支产物聚合到 context[节点key][分支名]
 */
final class Node
{
    /** decision/跳转目标的哨兵 key：直接结束工作流 */
    public const END = '__end__';

    public const TYPE_TASK = 'task';
    public const TYPE_DECISION = 'decision';
    public const TYPE_LLM = 'llm';
    public const TYPE_PARALLEL = 'parallel';

    /** @var string */
    private $type;

    /** @var string */
    private $key;

    /** @var string task 类名 */
    private $taskClass = '';

    /** @var int task 重试次数（0 = 不重试） */
    private $retries = 0;

    /** @var string decision 读取的上下文字段名 */
    private $field = '';

    /** @var array decision 分支映射：字段值(规范化) → 目标节点 key（或 Node::END） */
    private $branchMap = [];

    /** @var array parallel 分支：分支名 → task 类名 | task 类名数组 */
    private $branches = [];

    /** @var string llm 渠道名 */
    private $channel = '';

    /** @var callable|null llm 提示语构造器 fn(array $ctx): string|array(messages) */
    private $prompt;

    private function __construct(string $type, string $key)
    {
        $this->type = $type;
        $this->key = $key;
    }

    /**
     * task 节点：执行业务类（须实现 TaskInterface）
     *
     * @param string $key 节点 key（唯一，决策跳转/日志定位/产物命名均依赖它）
     * @param string $taskClass 实现 TaskInterface 的类名
     * @param array $opt { retries?: int } 任务抛异常重试次数，默认 0
     */
    public static function task(string $key, string $taskClass, array $opt = []): self
    {
        $node = new self(self::TYPE_TASK, $key);
        $node->taskClass = $taskClass;
        $node->retries = max(0, (int)($opt['retries'] ?? 0));
        return $node;
    }

    /**
     * decision 节点：按上下文字段值跳转
     *
     * 分支 key 为规范化字符串：bool → 'true'/'false'，null → 'null'，
     * int/string → 字符串本体；未命中映射 → 顺序执行下一节点（不视为失败）
     *
     * @param string $key 节点 key
     * @param string $field 读取的上下文字段名（缺失视为 null）
     * @param array $branchMap { 规范化值: 目标节点 key 或 Node::END }
     */
    public static function decision(string $key, string $field, array $branchMap): self
    {
        $node = new self(self::TYPE_DECISION, $key);
        $node->field = $field;
        $node->branchMap = $branchMap;
        return $node;
    }

    /**
     * llm 节点：调用模型渠道，回复文本写入 context[key]
     *
     * @param string $key 节点 key；模型回复作为产物落的上下文键
     * @param string $channel ModelGateway 注册的渠道名
     * @param callable $prompt fn(array $ctx): string|array —— 返回字符串按单条
     *        user message 发送；返回数组则视为完整 OpenAI messages 结构透传
     */
    public static function llm(string $key, string $channel, callable $prompt): self
    {
        $node = new self(self::TYPE_LLM, $key);
        $node->channel = $channel;
        $node->prompt = $prompt;
        return $node;
    }

    /**
     * parallel 节点：多分支隔离执行（FPM 下顺序调度，语义上等价并行，
     * 各分支拿主上下文副本互不可见；Swoole 宿主可替换引擎做真并发）
     *
     * @param string $key 节点 key；各分支最终产物聚合为 context[key][分支名]
     * @param array $branches { 分支名: task 类名 | task 类名数组（分支内串行小流水） }
     */
    public static function parallel(string $key, array $branches): self
    {
        $node = new self(self::TYPE_PARALLEL, $key);
        $node->branches = $branches;
        return $node;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function taskClass(): string
    {
        return $this->taskClass;
    }

    public function retries(): int
    {
        return $this->retries;
    }

    public function field(): string
    {
        return $this->field;
    }

    /** @return array 规范化值 → 目标 key */
    public function branchMap(): array
    {
        return $this->branchMap;
    }

    /** @return array 分支名 → task 类(名数组) */
    public function branches(): array
    {
        return $this->branches;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function prompt(): ?callable
    {
        return $this->prompt;
    }
}