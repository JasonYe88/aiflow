<?php
declare(strict_types=1);

namespace AIFlow\Tests\Knowledge;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Knowledge\KnowledgeRetrieveTool;
use AIFlow\Knowledge\KnowledgeService;
use AIFlow\Knowledge\SimpleKeywordRetriever;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class KnowledgeServiceTest extends TestCase
{
    /** @var KnowledgeService */
    private $kb;

    /** @var PdoStorage */
    private $storage;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoStorage($pdo);
        (new Migrator($this->storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->kb = new KnowledgeService($this->storage);
    }

    public function testCreateBaseRejectsDuplicateCode(): void
    {
        $this->kb->createBase('demo.faq', '演示FAQ', 'u1', '仅架子');
        $this->expectException(\InvalidArgumentException::class);
        $this->kb->createBase('demo.faq', '另一份', 'u1');
    }

    public function testIngestSplitBindRetrieveAndDisable(): void
    {
        $baseId = $this->kb->createBase('demo.faq', '演示FAQ', 'u1', '假数据');
        $docId = $this->kb->ingestText(
            $baseId,
            '演示文档',
            "问：退货几天到账？\n答：一般三个工作日入账。\n\n问：如何查余额？\n答：打开钱包页即可查看。"
        );
        $this->kb->setDocumentEnabled($docId, true);
        $this->kb->bind('app:demo', $baseId, 'prod', 'u1');

        $hits = $this->kb->retrieve('app:demo', '退货到账', ['top_k' => 3]);
        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('三个工作日', $hits[0]['content']);
        $this->assertArrayHasKey('score', $hits[0]);
        $this->assertArrayHasKey('chunk_id', $hits[0]);

        $this->kb->setDocumentEnabled($docId, false);
        $this->assertSame([], $this->kb->retrieve('app:demo', '退货到账', ['top_k' => 3]));
    }

    public function testDisabledDocumentSkippedByRetriever(): void
    {
        $baseId = $this->kb->createBase('demo.off', '关闭库', 'u1');
        $docId = $this->kb->ingestText($baseId, '关文档', "关键词苹果香蕉\n\n另一段橙子", false);
        $retriever = new SimpleKeywordRetriever($this->storage);
        $this->assertSame([], $retriever->retrieve($baseId, '苹果'));
        $this->kb->setDocumentEnabled($docId, true);
        $hits = $retriever->retrieve($baseId, '苹果');
        $this->assertCount(1, $hits);
        $this->assertSame(1.0, $hits[0]['score']);
    }

    public function testBindIsIdempotentPerEntryBaseEnv(): void
    {
        $baseId = $this->kb->createBase('demo.bind', '绑定库', 'u1');
        $id1 = $this->kb->bind('app:demo', $baseId, 'prod', 'u1', true);
        $id2 = $this->kb->bind('app:demo', $baseId, 'prod', 'u2', false);
        $this->assertSame($id1, $id2);
        $rows = $this->storage->query(
            'SELECT is_enabled, updated_by FROM ai_knowledge_binding WHERE id = ?',
            [$id1]
        );
        $this->assertSame(0, (int)$rows[0]['is_enabled']);
        $this->assertSame('u2', $rows[0]['updated_by']);
    }

    public function testArchiveBaseStillListedButSkippedOnRetrieve(): void
    {
        $baseId = $this->kb->createBase('demo.arch', '归档库', 'u1');
        $docId = $this->kb->ingestText($baseId, '文', "检索命中词：蓝莓");
        $this->kb->setDocumentEnabled($docId, true);
        $this->kb->bind('app:demo', $baseId, 'prod', 'u1');
        $this->kb->archiveBase($baseId);
        $base = $this->kb->getBase($baseId);
        $this->assertSame('archived', $base['status']);
        $this->assertSame([], $this->kb->retrieve('app:demo', '蓝莓'));
    }

    public function testKnowledgeRetrieveTool(): void
    {
        $baseId = $this->kb->createBase('demo.tool', '工具库', 'u1');
        $docId = $this->kb->ingestText($baseId, '文', "架子验收：关键词南瓜");
        $this->kb->setDocumentEnabled($docId, true);
        $this->kb->bind('app:demo', $baseId, 'prod', 'u1');

        $tool = new KnowledgeRetrieveTool($this->kb, 'app:demo');
        $this->assertSame('knowledge_retrieve', $tool->name());
        $out = $tool->execute(['query' => '南瓜', 'top_k' => 2]);
        $this->assertSame(1, $out['count']);
        $this->assertStringContainsString('南瓜', $out['hits'][0]['content']);
    }

    public function testListBasesAndDocuments(): void
    {
        $baseId = $this->kb->createBase('demo.list', '列表库', 'owner-a');
        $this->kb->ingestText($baseId, 'd1', "第一段\n\n第二段");
        $this->assertCount(1, $this->kb->listBases('owner-a'));
        $this->assertCount(1, $this->kb->listDocuments($baseId));
        $byCode = $this->kb->getBaseByCode('demo.list');
        $this->assertSame($baseId, $byCode['id']);
    }

    public function testListBasesWithStatsAndPreviewChunks(): void
    {
        $baseId = $this->kb->createBase('demo.stats', '统计库', 'u1', 'desc');
        $this->kb->ingestText($baseId, 'd1', "第一段内容\n\n第二段内容");
        $this->kb->bind('app:ai_chat_demo', $baseId, 'prod', 'u1', true);

        $rows = $this->kb->listBasesWithStats();
        $found = null;
        foreach ($rows as $r) {
            if ($r['id'] === $baseId) {
                $found = $r;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame(1, (int)$found['doc_count']);
        $this->assertGreaterThan(0, (int)$found['word_count']);
        $this->assertSame(1, (int)$found['binding_count']);

        $preview = $this->kb->previewChunks("甲\n\n乙\n\n丙", ['separator' => '\n\n', 'max_length' => 500]);
        $this->assertSame(3, $preview['count']);
        $this->assertCount(3, $preview['chunks']);

        $base2 = $this->kb->createBase('demo.meta', '配置库', 'u1', '', 'simple', [
            'chunk' => ['separator' => '\n\n', 'max_length' => 40, 'overlap' => 5],
            'indexing' => 'economy',
        ]);
        $this->assertSame('economy', $this->kb->baseMeta($base2)['indexing']);
        $doc = $this->kb->ingestText($base2, '长文', str_repeat('字', 90));
        $this->assertNotSame('', $doc);

        $binds = $this->kb->bindingsOfBase($baseId);
        $this->assertCount(1, $binds);
        $this->kb->setBindingEnabled((string)$binds[0]['id'], false, 'u1');
        $stats = $this->kb->getBaseWithStats($baseId);
        $this->assertSame(0, (int)$stats['binding_count']);
    }
}
