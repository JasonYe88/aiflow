<?php
declare(strict_types=1);

namespace AIFlow\Tests\Knowledge;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Gateway\ModelGateway;
use AIFlow\Knowledge\KnowledgeContext;
use AIFlow\Knowledge\KnowledgeService;
use AIFlow\Knowledge\RetrieveKnowledgeTask;
use AIFlow\Support\Migrator;
use AIFlow\Support\ModelResult;
use AIFlow\Tests\Support\ScriptedProvider;
use AIFlow\Workflow\Node;
use AIFlow\Workflow\Workflow;
use AIFlow\Workflow\WorkflowEngine;
use PHPUnit\Framework\TestCase;

class KnowledgeWorkflowTest extends TestCase
{
    protected function tearDown(): void
    {
        RetrieveKnowledgeTask::reset();
    }

    public function testContextHelpers(): void
    {
        $hits = [
            ['title' => 'FAQ', 'content' => '三个工作日到账', 'score' => 0.9],
        ];
        $text = KnowledgeContext::toText($hits);
        $this->assertStringContainsString('FAQ', $text);
        $this->assertStringContainsString('三个工作日', $text);

        $msgs = KnowledgeContext::prependToMessages(
            [['role' => 'user', 'content' => '退货多久？']],
            $hits
        );
        $this->assertSame('system', $msgs[0]['role']);
        $this->assertSame('user', $msgs[1]['role']);
    }

    public function testRetrieveTaskFeedsLlmPrompt(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $kb = new KnowledgeService($storage);
        $baseId = $kb->createBase('wf.kb', '工作流库', 'u1');
        $docId = $kb->ingestText($baseId, '制度', '退货一般三个工作日到账。');
        $kb->setDocumentEnabled($docId, true);
        $kb->bind('app:wf', $baseId, 'prod', 'u1');
        RetrieveKnowledgeTask::useKnowledge($kb);

        $gateway = new ModelGateway();
        $provider = new ScriptedProvider();
        $provider->script(new ModelResult('根据资料：三个工作日。', 'stub', 1, 1, 1, []));
        $gateway->add('stub', $provider);

        $engine = new WorkflowEngine($storage, $gateway);
        $wf = new class extends Workflow {
            public function nodes(): array
            {
                return [
                    Node::task('rag', RetrieveKnowledgeTask::class),
                    Node::llm('answer', 'stub', function (array $ctx) {
                        $ctxBlock = (string)($ctx['knowledge_context'] ?? '');
                        return "资料：\n{$ctxBlock}\n\n用户问：" . ($ctx['query'] ?? '');
                    }),
                ];
            }
        };

        $result = $engine->run($wf, [
            'entry_id' => 'app:wf',
            'query' => '退货多久到账',
        ], 'kb-wf-1');

        $this->assertTrue($result->isSuccess());
        $this->assertGreaterThan(0, $result->context['knowledge_hit_count']);
        $this->assertStringContainsString('三个工作日', (string)$result->context['knowledge_context']);
        $userPrompt = (string)($provider->calls[0]['messages'][0]['content'] ?? '');
        $this->assertStringContainsString('三个工作日', $userPrompt);
        $this->assertStringContainsString('根据资料', (string)$result->context['answer']);
    }
}
