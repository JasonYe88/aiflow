<?php
declare(strict_types=1);

/**
 * AIFlow M4 工作流冒烟示例：四类节点全链路演示（无需网络，可离线跑）
 *
 *   php examples/workflow.php          # 无 key：脚本桩渠道
 *   AIFLOW_QWEN_KEY=xxx php examples/workflow.php   # 真实渠道验证
 *
 * 业务叙事（虚构但贴近 FMS 场景）：利润款商品周报工作流
 *   1. load_orders   task      拉一周订单，产出销售统计上下文
 *   2. need_insight  decision  销售额低于阈值时才让模型写洞察（省 token）
 *   3. insight       llm       模型根据统计写经营洞察
 *   4. fan_out       parallel  报表入库与通知两个分支隔离执行
 *   5. dispatch 演示异步队列（存储表）：入队 → consume 消费
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\AIKernel;
use AIFlow\Support\ModelResult;
use AIFlow\Workflow\Node;
use AIFlow\Workflow\TaskInterface;
use AIFlow\Workflow\Workflow;

/* ---------------- 业务节点类（宿主真实场景中就是普通 PHP 类） ---------------- */

final class LoadOrdersTask implements TaskInterface
{
    public function execute(array $input): array
    {
        // 真实场景：查 FMS 订单表聚合；示例里造数
        $orders = [
            ['sku' => 'A-100', 'qty' => 42, 'amount' => 9660],
            ['sku' => 'B-200', 'qty' => 7, 'amount' => 2100],
        ];
        $salesTotal = array_sum(array_column($orders, 'amount'));
        $threshold = (int)($input['insight_threshold'] ?? 10000);
        return [
            'orders' => $orders,
            'sales_total' => $salesTotal,
            // 销售额不及阈值 → 需要模型写洞察（省 token：达标周直接走汇总分支）
            'needs_insight' => $salesTotal < $threshold,
        ];
    }
}

final class SaveReportTask implements TaskInterface
{
    public function execute(array $input): array
    {
        // 真实场景：写报表表/发邮件；示例里仅模拟
        return ['report_saved' => true, 'report_rows' => count($input['orders'])];
    }
}

final class NotifyOwnerTask implements TaskInterface
{
    public function execute(array $input): array
    {
        return ['notified' => true, 'to' => $input['owner'] ?? 'boss'];
    }
}

/* ---------------- 工作流声明：纯 PHP 类，IDE 全程可跳转 ---------------- */

final class WeeklyReportWorkflow extends Workflow
{
    public function nodes(): array
    {
        return [
            Node::task('load_orders', LoadOrdersTask::class),
            // needs_insight=true → insight 节点；false → 直跳 fan_out（insight 记 skipped）
            Node::decision('need_insight', 'needs_insight', [
                'true' => 'insight',
                'false' => 'fan_out',
            ]),
            Node::llm('insight', 'qwen-plus', function (array $ctx) {
                return '这是本周利润款销售数据（JSON）：' . json_encode($ctx['orders'], JSON_UNESCAPED_UNICODE)
                    . '。请给出三条经营洞察，每条一句话。';
            }),
            Node::parallel('fan_out', [
                'report' => SaveReportTask::class,
                'notify' => [NotifyOwnerTask::class],
            ]),
        ];
    }
}

/* ---------------- 引导内核并运行 ---------------- */

$hasKey = (bool)getenv('AIFLOW_QWEN_KEY');
$kernel = AIKernel::boot([
    'storage' => ['dsn' => 'sqlite::memory:'],
    'migration' => ['auto' => true],
    'models' => $hasKey ? [
        'qwen-plus' => [
            'driver' => 'openai',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'api_key' => getenv('AIFLOW_QWEN_KEY'),
            'model' => 'qwen-plus',
        ],
    ] : [],
]);

// 无 key 时注入脚本桩渠道，保证示例离线可跑（真实场景删掉这段即可）
if (!$hasKey) {
    $kernel->gateway()->add('qwen-plus', new class implements \AIFlow\Contract\ModelProviderInterface {
        public function chat(array $messages, array $options = []): ModelResult
        {
            return new ModelResult(
                '(脚本桩回复) 1. A-100 是本周主力利润款，建议追加备货；2. B-200 客单价偏低，可打包搭售；3. 周末转化率更高，广告投放向周末倾斜。',
                'qwen-plus-stub',
                120,
                80,
                3
            );
        }
        public function chatStream(array $messages, array $options, callable $onDelta): ModelResult
        {
            throw new \LogicException('示例桩不支持流式');
        }
    });
}

$engine = $kernel->workflow();

// ---- 同步 run ----
echo "== 同步 run() ==\n";
$result = $engine->run(new WeeklyReportWorkflow(), ['owner' => 'jason', 'insight_threshold' => 10000]);
echo "run_id:    {$result->runId}\n";
echo "status:    {$result->status}\n";
echo "context:   " . json_encode($result->context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "== 节点日志（ai_workflow_run_log）==\n";
foreach ($engine->logs($result->runId) as $log) {
    echo sprintf(
        "#%-2d %-14s %-8s in=%s out=%s\n",
        (int)$log['node_index'],
        $log['node_key'],
        $log['status'],
        mb_substr((string)$log['input'], 0, 60),
        mb_substr((string)$log['output'], 0, 60)
    );
}
echo "\n";

// ---- 异步 dispatch / consume ----
echo "== 异步 dispatch() 存储表队列 ==\n";
$queueId = $engine->dispatch(new WeeklyReportWorkflow(), ['owner' => 'ops-bot'], 'cron-20261021');
echo "queue_id:  {$queueId}\n";
$processed = $engine->consume(5);
echo "consumed:  {$processed} 条\n";
$row = $kernel->storage()->query('SELECT status, run_id FROM ai_workflow_queue WHERE id = ?', [$queueId])[0];
echo "队列行:    status={$row['status']} run_id={$row['run_id']}\n\n";

// 调低阈值触发 llm 分支：验证 decision 跳转
echo "== 低阈值触发 llm 分支（need_insight 命中 true）==\n";
$r2 = $engine->run(new WeeklyReportWorkflow(), ['owner' => 'jason', 'insight_threshold' => 20000]);
echo "insight:   " . mb_substr((string)($r2->context['insight'] ?? '(未产出)'), 0, 60) . "...\n";
$decLog = null;
foreach ($engine->logs($r2->runId) as $log) {
    if ($log['node_key'] === 'need_insight') {
        $decLog = json_decode((string)$log['output'], true);
    }
}
echo "decision:  " . json_encode($decLog, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n冒烟完成 ✅  十五表全链路可查（ai_workflow_run / ai_workflow_run_log / ai_workflow_queue）\n";