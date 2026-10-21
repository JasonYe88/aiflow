<?php
declare(strict_types=1);

/**
 * M3 Agent 冒烟示例：注册一个工具 → function-calling 循环 → 输出终答与轨迹
 * 运行：php examples/agent.php（可设 AIFLOW_QWEN_KEY 用千问真跑；无 key 时走示例桩渠道）
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\Agent\AgentRuntime;
use AIFlow\Agent\ToolInterface;
use AIFlow\AIKernel;
use AIFlow\Gateway\ModelGateway;

/** 示例工具：报销额度查询（真实宿主里包的是业务 SQL / 工作流） */
final class BudgetLookupTool implements ToolInterface
{
    public function name(): string
    {
        return 'budget_lookup';
    }

    public function description(): string
    {
        return '按部门查询本季度报销额度与已用金额';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dept' => ['type' => 'string', 'description' => '部门名，如 财务部'],
            ],
            'required' => ['dept'],
        ];
    }

    public function execute(array $args)
    {
        // 桩数据——真实场景里是查库 / 调内部服务 / 包一个 M4 工作流
        return ['部门' => (string)($args['dept'] ?? ''), '额度' => 100000, '已用' => 42300, '单位' => '分'];
    }
}

$apiKey = getenv('AIFLOW_QWEN_KEY') ?: '';

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . sys_get_temp_dir() . '/aiflow_m3_demo.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
    'models'    => $apiKey === '' ? [] : [
        'qwen' => [
            'driver'   => 'openai',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'api_key'  => $apiKey,
            'model'    => 'qwen-plus',
        ],
    ],
]);

if ($apiKey !== '') {
    // 真跑：走模型网关（千问 function-calling）
    $agent = $kernel->agent();
    $agent->register(new BudgetLookupTool());
    echo "渠道: qwen（真实调用）\n";
} else {
    // 无 key：桩渠道演示循环结构（千问’internal fake）——脚本化 provider 模拟一次工具调用
    require_once __DIR__ . '/../tests/Support/ScriptedProvider.php';
    $fake = new \AIFlow\Tests\Support\ScriptedProvider();
    $toolCall = new \AIFlow\Support\ModelResult('', 'qwen-plus', 12, 6, 3, [
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'demo_call_1',
                    'type' => 'function',
                    'function' => ['name' => 'budget_lookup', 'arguments' => '{"dept":"财务部"}'],
                ]],
            ],
        ]],
    ]);
    $final = new \AIFlow\Support\ModelResult('财务部本季度额度约 1000.00 元（100000 分），已用 42300 分，剩余 57700 分。', 'qwen-plus', 30, 20, 3, []);
    $fake->script($toolCall);
    $fake->script($final);
    $gateway = new ModelGateway();
    $gateway->add('fake', $fake);
    $agent = new AgentRuntime($gateway);
    $agent->register(new BudgetLookupTool());
    echo "渠道: fake（未设置 AIFLOW_QWEN_KEY，脚本桩演示循环结构）\n";
}

$result = $agent->run($apiKey === '' ? 'fake' : 'qwen', [
    ['role' => 'user', 'content' => '帮我查一下财务部这个季度报销额度还剩多少分？'],
]);

echo "回答: {$result->content}\n";
echo "轮数: {$result->rounds}，token: in={$result->inputTokens} out={$result->outputTokens}\n";
foreach ($result->steps as $i => $step) {
    echo "步骤" . ($i + 1) . ": {$step['tool']} " . json_encode($step['args'], JSON_UNESCAPED_UNICODE)
        . " → " . (is_string($step['output']) ? substr($step['output'], 0, 60) : json_encode($step['output'])) . "（" . ($step['ok'] ? 'ok' : 'failed') . "）\n";
}
echo "AIFlow M3 Agent 冒烟 OK\n";