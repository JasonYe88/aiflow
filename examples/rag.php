<?php
declare(strict_types=1);

/**
 * M5 RAG 冒烟
 * - 默认：PHP 侧 SimpleKeywordRetriever（离线，CI 可跑）
 * - 设 AIFLOW_RAG_URL：同步分段到 Python 桥并用 HttpRagRetriever 检索
 *
 *   python plugins/python-rag/server.py
 *   AIFLOW_RAG_URL=http://127.0.0.1:8765 php examples/rag.php
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\AIKernel;
use AIFlow\Knowledge\HttpRagRetriever;

$ragUrl = getenv('AIFLOW_RAG_URL') ?: '';
$config = [
    'storage'   => ['dsn' => 'sqlite:' . sys_get_temp_dir() . '/aiflow_rag_demo.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
];

if ($ragUrl !== '') {
    $config['knowledge'] = [
        'retriever' => 'http',
        'rag_url'   => $ragUrl,
        'rag_token' => (string)(getenv('AIFLOW_RAG_TOKEN') ?: ''),
    ];
    echo "live RAG url={$ragUrl}\n";
} else {
    echo "offline SimpleKeywordRetriever（设 AIFLOW_RAG_URL 可打 Python 桥）\n";
}

$kernel = AIKernel::boot($config);
$kb = $kernel->knowledge();

$ragClient = null;
$retriever = $kb->retriever();
if ($retriever instanceof HttpRagRetriever) {
    $ragClient = $retriever->client();
    try {
        $health = $ragClient->health();
        echo 'RAG version=' . ($health['version'] ?? '?') . "\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, '无法连接 RAG 桥：' . $e->getMessage() . "\n");
        exit(1);
    }
}

$existing = $kb->getBaseByCode('demo.rag');
$baseId = $existing !== null
    ? (string)$existing['id']
    : $kb->createBase('demo.rag', 'RAG演示', 'u_demo', 'M5 假数据');

$docId = $kb->ingestText(
    $baseId,
    'RAG文档-' . date('His'),
    "问：退货几天到账？\n答：一般三个工作日入账。\n\n问：如何查余额？\n答：打开钱包页即可查看。"
);
$kb->setDocumentEnabled($docId, true);

$chunks = $kb->exportChunks($baseId);
echo 'exportChunks: ' . count($chunks) . "\n";

if ($ragClient !== null) {
    $ragClient->index($baseId, $chunks, true);
    echo "pushed to Python RAG\n";
}

$hits = $kb->retrieveBase($baseId, '退货到账', ['top_k' => 3]);
echo 'retrieve hits: ' . count($hits) . "\n";
if ($hits === []) {
    fwrite(STDERR, "rag 冒烟失败：应至少 1 条命中\n");
    exit(1);
}
echo 'top: ' . mb_substr($hits[0]['content'], 0, 36) . '… score=' . $hits[0]['score'] . "\n";
echo "rag 冒烟通过\n";
