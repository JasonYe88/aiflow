<?php
declare(strict_types=1);

namespace AIFlow\Tests\Knowledge;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Gateway\ModelGateway;
use AIFlow\Gateway\OpenAiCompatibleProvider;
use AIFlow\Knowledge\EmbeddingIndexer;
use AIFlow\Knowledge\EmbeddingRetriever;
use AIFlow\Knowledge\KnowledgeService;
use AIFlow\Knowledge\VectorMath;
use AIFlow\Support\HttpResponse;
use AIFlow\Support\Migrator;
use AIFlow\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

class EmbeddingRetrieverTest extends TestCase
{
    public function testVectorMathCosine(): void
    {
        $this->assertEqualsWithDelta(1.0, VectorMath::cosine([1, 0], [1, 0]), 1e-6);
        $this->assertEqualsWithDelta(0.0, VectorMath::cosine([1, 0], [0, 1]), 1e-6);
        $this->assertSame([], VectorMath::decode(''));
        $this->assertSame([1.5, 2.0], VectorMath::decode(VectorMath::encode([1.5, 2.0])));
    }

    public function testIndexAndRetrieveOrdersByCosine(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $kb = new KnowledgeService($storage);
        $baseId = $kb->createBase('emb.demo', '向量库', 'u1');
        $docId = $kb->ingestText(
            $baseId,
            '两段',
            "退货三个工作日到账\n\n天气晴朗适合郊游"
        );
        $kb->setDocumentEnabled($docId, true);

        $http = new FakeHttpClient();
        // indexBase：一批 2 条
        $http->queue(new HttpResponse(200, json_encode([
            'data' => [
                ['index' => 0, 'embedding' => [1.0, 0.0, 0.0]],
                ['index' => 1, 'embedding' => [0.0, 1.0, 0.0]],
            ],
        ])));
        // retrieve：query 向量贴近第一段
        $http->queue(new HttpResponse(200, json_encode([
            'data' => [
                ['index' => 0, 'embedding' => [0.95, 0.05, 0.0]],
            ],
        ])));

        $gateway = new ModelGateway();
        $gateway->add('emb', new OpenAiCompatibleProvider('https://emb.test', 'sk', 'text-embedding', $http));

        $indexer = new EmbeddingIndexer($storage, $gateway, 'emb', 16);
        $this->assertSame(2, $indexer->indexBase($baseId));
        $this->assertSame(0, $indexer->indexBase($baseId)); // 已有向量跳过

        $retriever = new EmbeddingRetriever($storage, $gateway, 'emb');
        $hits = $retriever->retrieve($baseId, '退货到账', ['top_k' => 2]);
        $this->assertCount(2, $hits);
        $this->assertStringContainsString('退货', $hits[0]['content']);
        $this->assertGreaterThan($hits[1]['score'], $hits[0]['score']);
        $this->assertStringContainsString('/embeddings', $http->requests[0]['url']);
    }
}
