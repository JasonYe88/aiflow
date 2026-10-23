<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

use AIFlow\Contract\StorageInterface;
use AIFlow\Gateway\ModelGateway;

/**
 * 基于已缓存 embedding_json 的余弦检索（需先 EmbeddingIndexer::indexBase）
 */
final class EmbeddingRetriever implements RetrieverInterface
{
    /** @var StorageInterface */
    private $storage;

    /** @var ModelGateway */
    private $gateway;

    /** @var string */
    private $channel;

    /** @var float 低于此分丢弃 */
    private $minScore;

    public function __construct(
        StorageInterface $storage,
        ModelGateway $gateway,
        string $channel,
        float $minScore = 0.0
    ) {
        $this->storage = $storage;
        $this->gateway = $gateway;
        $this->channel = $channel;
        $this->minScore = $minScore;
    }

    public function retrieve(string $baseId, string $query, array $opt = []): array
    {
        $topK = max(1, (int)($opt['top_k'] ?? 5));
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $rows = $this->storage->query(
            'SELECT c.id AS chunk_id, c.content, c.document_id, c.base_id, c.embedding_json, d.title
             FROM ai_knowledge_chunk c
             INNER JOIN ai_knowledge_document d ON d.id = c.document_id
             WHERE c.base_id = ? AND d.enabled = 1 AND d.status = ?
               AND c.embedding_json IS NOT NULL AND c.embedding_json != ?',
            [$baseId, 'ready', '']
        );
        if ($rows === []) {
            return [];
        }

        $qVec = $this->gateway->embed($this->channel, [$query])[0];
        $scored = [];
        foreach ($rows as $row) {
            $vec = VectorMath::decode((string)$row['embedding_json']);
            if ($vec === []) {
                continue;
            }
            $score = VectorMath::cosine($qVec, $vec);
            if ($score < $this->minScore) {
                continue;
            }
            $scored[] = [
                'chunk_id' => (string)$row['chunk_id'],
                'content' => (string)$row['content'],
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
}
