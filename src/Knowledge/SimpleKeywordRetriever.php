<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

use AIFlow\Contract\StorageInterface;

/**
 * 架子默认检索：对已启用文档的 chunk 做关键词打分（无向量依赖）
 * 仅用于骨架验收与离线测试；生产可换 EmbeddingRetriever
 */
final class SimpleKeywordRetriever implements RetrieverInterface
{
    /** @var StorageInterface */
    private $storage;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
    }

    public function retrieve(string $baseId, string $query, array $opt = []): array
    {
        $topK = max(1, (int)($opt['top_k'] ?? 5));
        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }

        $rows = $this->storage->query(
            'SELECT c.id AS chunk_id, c.content, c.document_id, c.base_id, d.title
             FROM ai_knowledge_chunk c
             INNER JOIN ai_knowledge_document d ON d.id = c.document_id
             WHERE c.base_id = ? AND d.enabled = 1 AND d.status = ?',
            [$baseId, 'ready']
        );

        $scored = [];
        foreach ($rows as $row) {
            $content = (string)$row['content'];
            $hay = mb_strtolower($content);
            $hits = 0;
            foreach ($terms as $term) {
                if ($term !== '' && mb_strpos($hay, $term) !== false) {
                    $hits++;
                }
            }
            if ($hits === 0) {
                continue;
            }
            $score = $hits / count($terms);
            $scored[] = [
                'chunk_id' => (string)$row['chunk_id'],
                'content' => $content,
                'score' => round($score, 4),
                'document_id' => (string)$row['document_id'],
                'base_id' => (string)$row['base_id'],
                'title' => (string)$row['title'],
            ];
        }

        usort($scored, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            }
            return ($a['score'] < $b['score']) ? 1 : -1;
        });

        return array_slice($scored, 0, $topK);
    }

    /** @return string[] */
    private function terms(string $query): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return [];
        }
        // 空白/标点切分；整句保留；中文再拆二字邻接（「退货到账」→退货/货到/到账），避免非连续子串零命中
        $parts = preg_split('/[\s\p{P}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts[] = $q;
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $q)) {
            $chars = preg_split('//u', preg_replace('/[^\x{4e00}-\x{9fff}a-z0-9]/u', '', $q) ?: '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $n = count($chars);
            for ($i = 0; $i < $n - 1; $i++) {
                $parts[] = $chars[$i] . $chars[$i + 1];
            }
        }
        $uniq = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '' || isset($uniq[$p])) {
                continue;
            }
            // 单字噪声过多，架子阶段丢掉
            if (mb_strlen($p) < 2) {
                continue;
            }
            $uniq[$p] = true;
        }
        return array_keys($uniq);
    }
}