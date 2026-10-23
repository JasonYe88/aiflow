<?php
declare(strict_types=1);

/**
 * 知识库 → 工作流注入冒烟：RetrieveKnowledgeTask + llm（脚本桩，离线）
 * 运行：php examples/knowledge_workflow.php
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\AIKernel;
use AIFlow\Knowledge\RetrieveKnowledgeTask;
use AIFlow\Support\ModelResult;
use AIFlow\Workflow\Node;
use AIFlow\Workflow\Workflow;

final class FaqAnswerWorkflow extends Workflow
{
    public function nodes(): array
    {
        return [
            Node::task('rag', RetrieveKnowledgeTask::class),
            Node::llm('answer', 'stub', function (array $ctx) {
                $block = (string)($ctx['knowledge_context'] ?? '(无命中)');
                return "请仅依据下列资料回答。\n\n{$block}\n\n问：" . ($ctx['query'] ?? '');
            }),
        ];
    }
}

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . sys_get_temp_dir() . '/aiflow_kb_wf.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
    'models'    => [],
]);

// 脚本桩渠道：不打真网
$kernel->gateway()->add('stub', new class implements \AIFlow\Contract\ModelProviderInterface {
    public function chat(array $messages, array $options = []): ModelResult
    {
        $q = (string)($messages[0]['content'] ?? '');
        $hint = (strpos($q, '三个工作日') !== false) ? '三个工作日' : '暂无依据';
        return new ModelResult("答：{$hint}。", 'stub', 1, 1, 1, []);
    }
    public function chatStream(array $messages, array $options, callable $onDelta): ModelResult
    {
        throw new \LogicException('unused');
    }
});

$kb = $kernel->knowledge();
$existing = $kb->getBaseByCode('demo.wf');
$baseId = $existing !== null
    ? (string)$existing['id']
    : $kb->createBase('demo.wf', '工作流FAQ', 'u_demo');
$docId = $kb->ingestText($baseId, 'FAQ-' . date('His'), "问：退货几天到账？\n答：一般三个工作日入账。");
$kb->setDocumentEnabled($docId, true);
$kb->bind('app:wf_demo', $baseId, 'prod', 'u_demo');

RetrieveKnowledgeTask::useKnowledge($kb);
$result = $kernel->workflow()->run(new FaqAnswerWorkflow(), [
    'entry_id' => 'app:wf_demo',
    'query' => '退货多久到账',
], 'kb-wf-demo');

if (!$result->isSuccess() || (int)($result->context['knowledge_hit_count'] ?? 0) < 1) {
    fwrite(STDERR, "knowledge_workflow 冒烟失败\n");
    exit(1);
}
echo 'hits=' . $result->context['knowledge_hit_count'] . "\n";
echo 'answer=' . $result->context['answer'] . "\n";
echo "knowledge_workflow 冒烟通过\n";
