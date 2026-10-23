<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

use AIFlow\Contract\StorageInterface;
use AIFlow\Gateway\ModelGateway;

/**
 * 将已启用文档的 chunk 向量化并写回 embedding_json
 */
final class EmbeddingIndexer
{
    /** @var StorageInterface */
    private $storage;

    /** @var ModelGateway */
    private $gateway;

    /** @var string */
    private $channel;

    /** @var int 每批送入 /embeddings 的条数 */
    private $batchSize;

    public function __construct(StorageInterface $storage, ModelGateway $gateway, string $channel, int $batchSize = 16)
    {
        $this->storage = $storage;
        $this->gateway = $gateway;
        $this->channel = $channel;
        $this->batchSize = max(1, $batchSize);
    }

    /**
     * @param array $opt { force?: bool 已有向量是否重算 }
     * @return int 写入条数
     */
    public function indexBase(string $baseId, array $opt = []): int
    {
        $force = !empty($opt['force']);
        $sql = 'SELECT c.id, c.content, c.embedding_json
             FROM ai_knowledge_chunk c
             INNER JOIN ai_knowledge_document d ON d.id = c.document_id
             WHERE c.base_id = ? AND d.enabled = 1 AND d.status = ?';
        $rows = $this->storage->query($sql, [$baseId, 'ready']);
        $todo = [];
        foreach ($rows as $row) {
            $json = $row['embedding_json'] ?? null;
            if (!$force && $json !== null && $json !== '') {
                continue;
            }
            $todo[] = $row;
        }
        if ($todo === []) {
            return 0;
        }

        $written = 0;
        foreach (array_chunk($todo, $this->batchSize) as $batch) {
            $texts = [];
            foreach ($batch as $row) {
                $texts[] = (string)$row['content'];
            }
            $vectors = $this->gateway->embed($this->channel, $texts);
            foreach ($batch as $i => $row) {
                $this->storage->execute(
                    'UPDATE ai_knowledge_chunk SET embedding_json = ? WHERE id = ?',
                    [VectorMath::encode($vectors[$i]), (string)$row['id']]
                );
                $written++;
            }
        }
        return $written;
    }
}
