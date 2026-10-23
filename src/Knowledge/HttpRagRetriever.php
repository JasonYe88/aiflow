<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

/**
 * 经 HTTP 调用 Python RAG 桥的检索器（实现 RetrieverInterface，可注入 KnowledgeService）
 */
final class HttpRagRetriever implements RetrieverInterface
{
    /** @var HttpRagClient */
    private $client;

    public function __construct(HttpRagClient $client)
    {
        $this->client = $client;
    }

    public function retrieve(string $baseId, string $query, array $opt = []): array
    {
        $topK = max(1, (int)($opt['top_k'] ?? 5));
        return $this->client->retrieve($baseId, $query, $topK);
    }

    public function client(): HttpRagClient
    {
        return $this->client;
    }
}
