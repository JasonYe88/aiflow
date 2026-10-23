<?php
declare(strict_types=1);

/**
 * 知识库架子冒烟：建库 → 假文本入库 → 启用 → 绑定 → 检索 → 禁用后无命中
 * 运行：php examples/knowledge.php
 * 不含真实业务制度/分类内容；Agent 工具可选挂载演示
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\AIKernel;
use AIFlow\Knowledge\KnowledgeRetrieveTool;

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . sys_get_temp_dir() . '/aiflow_knowledge_demo.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
]);

$kb = $kernel->knowledge();

// 可重复跑：code 冲突则复用已有库
$existing = $kb->getBaseByCode('demo.faq');
if ($existing !== null) {
    $baseId = (string)$existing['id'];
    echo "复用知识库 {$baseId} (demo.faq)\n";
} else {
    $baseId = $kb->createBase('demo.faq', '演示FAQ', 'u_demo', '仅架子假数据');
    echo "创建知识库 {$baseId}\n";
}

$docId = $kb->ingestText(
    $baseId,
    '演示文档-' . date('His'),
    "问：退货几天到账？\n答：一般三个工作日入账。\n\n问：如何查余额？\n答：打开钱包页即可查看。"
);
$kb->setDocumentEnabled($docId, true);
$kb->bind('app:demo', $baseId, 'prod', 'u_demo');

$hits = $kb->retrieve('app:demo', '退货到账', ['top_k' => 3]);
echo '命中条数: ' . count($hits) . "\n";
if ($hits !== []) {
    echo '首条摘要: ' . mb_substr($hits[0]['content'], 0, 40) . "… score={$hits[0]['score']}\n";
}

$tool = new KnowledgeRetrieveTool($kb, 'app:demo');
$kernel->agent()->register($tool);
$toolOut = $tool->execute(['query' => '查余额']);
echo "Tool knowledge_retrieve count={$toolOut['count']}\n";

$kb->setDocumentEnabled($docId, false);
$after = $kb->retrieve('app:demo', '退货到账', ['top_k' => 3]);
echo '禁用后命中: ' . count($after) . "（期望 0）\n";

if ($hits === [] || $after !== []) {
    fwrite(STDERR, "knowledge 冒烟失败：启用应命中、禁用应无命中\n");
    exit(1);
}

echo "knowledge 冒烟通过\n";
