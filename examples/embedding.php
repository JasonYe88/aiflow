<?php
declare(strict_types=1);

/**
 * Embedding 检索冒烟（内置假 HTTP，离线可跑）
 *
 * 生产配置示例：
 *   'models' => ['emb' => ['driver'=>'openai','base_url'=>...,'api_key'=>...,'model'=>'text-embedding-v3']],
 *   'knowledge' => ['retriever'=>'embedding','embed_channel'=>'emb'],
 *   然后 $kernel->embeddingIndexer()->indexBase($baseId);
 *
 * 运行：php examples/embedding.php
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\Contract\HttpClientInterface;
use AIFlow\Gateway\ModelGateway;
use AIFlow\Gateway\OpenAiCompatibleProvider;
use AIFlow\AIKernel;
use AIFlow\Knowledge\EmbeddingIndexer;
use AIFlow\Knowledge\EmbeddingRetriever;
use AIFlow\Knowledge\KnowledgeService;
use AIFlow\Support\HttpResponse;

/** 示例专用假客户端（不依赖 tests/） */
final class EmbFakeHttp implements HttpClientInterface
{
    private $queue = [];
    public function queue(HttpResponse $r): void { $this->queue[] = $r; }
    public function request(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \RuntimeException('EmbFakeHttp 队列空');
        }
        return $next;
    }
    public function requestStream(string $method, string $url, array $headers, string $body, callable $onChunk): HttpResponse
    {
        return $this->request($method, $url, $headers, $body);
    }
}

$http = new EmbFakeHttp();
$http->queue(new HttpResponse(200, json_encode([
    'data' => [
        ['index' => 0, 'embedding' => [1.0, 0.0, 0.0]],
        ['index' => 1, 'embedding' => [0.0, 1.0, 0.0]],
    ],
])));
$http->queue(new HttpResponse(200, json_encode([
    'data' => [['index' => 0, 'embedding' => [0.99, 0.01, 0.0]]],
])));

$gateway = new ModelGateway();
$gateway->add('emb', new OpenAiCompatibleProvider('https://emb.test', 'sk', 'text-embedding-demo', $http));

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . sys_get_temp_dir() . '/aiflow_emb_demo.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
]);
$storage = $kernel->storage();
$retriever = new EmbeddingRetriever($storage, $gateway, 'emb');
$kb = new KnowledgeService($storage, $retriever);

$existing = $kb->getBaseByCode('demo.emb');
$baseId = $existing !== null
    ? (string)$existing['id']
    : $kb->createBase('demo.emb', '向量演示', 'u_demo');

$docId = $kb->ingestText(
    $baseId,
    'emb-' . date('His'),
    "退货三个工作日到账\n\n今天天气适合出门"
);
$kb->setDocumentEnabled($docId, true);

$written = (new EmbeddingIndexer($storage, $gateway, 'emb'))->indexBase($baseId, ['force' => true]);
echo "indexed: {$written}\n";

$hits = $kb->retrieveBase($baseId, '退货时效', ['top_k' => 2]);
echo 'hits: ' . count($hits) . "\n";
if ($hits === [] || strpos($hits[0]['content'], '退货') === false) {
    fwrite(STDERR, "embedding 冒烟失败\n");
    exit(1);
}
echo 'top score=' . $hits[0]['score'] . "\n";
echo "embedding 冒烟通过\n";
