<?php
declare(strict_types=1);

namespace AIFlow\Tests\Knowledge;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Knowledge\HybridRetriever;
use AIFlow\Knowledge\KnowledgeService;
use AIFlow\Knowledge\RetrieverInterface;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class HybridRetrieverTest extends TestCase
{
    public function testMergesWeightedScoresByChunkId(): void
    {
        $a = new class implements RetrieverInterface {
            public function retrieve(string $baseId, string $query, array $opt = []): array
            {
                return [[
                    'chunk_id' => 'c1',
                    'content' => '退货政策',
                    'score' => 1.0,
                    'document_id' => 'd1',
                    'base_id' => $baseId,
                    'title' => 'A',
                ]];
            }
        };
        $b = new class implements RetrieverInterface {
            public function retrieve(string $baseId, string $query, array $opt = []): array
            {
                return [
                    [
                        'chunk_id' => 'c1',
                        'content' => '退货政策',
                        'score' => 0.5,
                        'document_id' => 'd1',
                        'base_id' => $baseId,
                        'title' => 'A',
                    ],
                    [
                        'chunk_id' => 'c2',
                        'content' => '天气',
                        'score' => 0.8,
                        'document_id' => 'd2',
                        'base_id' => $baseId,
                        'title' => 'B',
                    ],
                ];
            }
        };
        $hybrid = new HybridRetriever($a, $b, 0.4, 0.6);
        $hits = $hybrid->retrieve('b1', 'q', ['top_k' => 5]);
        $this->assertCount(2, $hits);
        // c1: 1.0*0.4 + 0.5*0.6 = 0.7；c2: 0.8*0.6 = 0.48
        $this->assertSame('c1', $hits[0]['chunk_id']);
        $this->assertEqualsWithDelta(0.7, $hits[0]['score'], 0.0001);
        $this->assertSame('c2', $hits[1]['chunk_id']);
    }

    public function testIngestFileAndUpdateBase(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $kb = new KnowledgeService($storage);

        $baseId = $kb->createBase('file.demo', '文件库', 'u1', '旧描述');
        $kb->updateBase($baseId, ['name' => '文件库2', 'description' => '新描述']);
        $base = $kb->getBase($baseId);
        $this->assertSame('文件库2', $base['name']);
        $this->assertSame('新描述', $base['description']);

        $tmp = tempnam(sys_get_temp_dir(), 'aiflow_kb_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "第一段退货说明\n\n第二段余额查询");
        try {
            $docId = $kb->ingestFile($baseId, $tmp, null, true);
            $docs = $kb->listDocuments($baseId);
            $this->assertSame('file', $docs[0]['source_type']);
            $this->assertSame($tmp, $docs[0]['source_ref']);
            $hits = $kb->retrieveBase($baseId, '退货', ['top_k' => 3]);
            $this->assertNotEmpty($hits);
            $this->assertNotSame('', $docId);
        } finally {
            @unlink($tmp);
        }
    }
}
