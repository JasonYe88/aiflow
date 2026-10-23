<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

/**
 * 混合检索：关键词 + 向量（或任意两个 Retriever）按权重合并，同 chunk 取加权分
 */
final class HybridRetriever implements RetrieverInterface
{
    /** @var RetrieverInterface */
    private $primary;

    /** @var RetrieverInterface */
    private $secondary;

    /** @var float */
    private $primaryWeight;

    /** @var float */
    private $secondaryWeight;

    public function __construct(
        RetrieverInterface $primary,
        RetrieverInterface $secondary,
        float $primaryWeight = 0.4,
        float $secondaryWeight = 0.6
    ) {
        $this->primary = $primary;
        $this->secondary = $secondary;
        $sum = $primaryWeight + $secondaryWeight;
        if ($sum <= 0) {
            $primaryWeight = 0.5;
            $secondaryWeight = 0.5;
            $sum = 1.0;
        }
        $this->primaryWeight = $primaryWeight / $sum;
        $this->secondaryWeight = $secondaryWeight / $sum;
    }

    public function retrieve(string $baseId, string $query, array $opt = []): array
    {
        $topK = max(1, (int)($opt['top_k'] ?? 5));
        // 两侧多取一些再合并，避免漏掉交叉命中
        $fetch = max($topK * 3, 10);
        $inner = $opt;
        $inner['top_k'] = $fetch;

        $merged = [];
        foreach ($this->primary->retrieve($baseId, $query, $inner) as $hit) {
            $this->accumulate($merged, $hit, $this->primaryWeight);
        }
        foreach ($this->secondary->retrieve($baseId, $query, $inner) as $hit) {
            $this->accumulate($merged, $hit, $this->secondaryWeight);
        }

        $list = array_values($merged);
        usort($list, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            }
            return ($a['score'] < $b['score']) ? 1 : -1;
        });
        return array_slice($list, 0, $topK);
    }

    /**
     * @param array<string, array> $merged
     * @param array{chunk_id?:string,content?:string,score?:float,document_id?:string,base_id?:string,title?:string} $hit
     */
    private function accumulate(array &$merged, array $hit, float $weight): void
    {
        $id = (string)($hit['chunk_id'] ?? '');
        if ($id === '') {
            $id = md5((string)($hit['content'] ?? '') . '|' . (string)($hit['document_id'] ?? ''));
        }
        $part = ((float)($hit['score'] ?? 0)) * $weight;
        if (!isset($merged[$id])) {
            $merged[$id] = [
                'chunk_id' => (string)($hit['chunk_id'] ?? $id),
                'content' => (string)($hit['content'] ?? ''),
                'score' => round($part, 4),
                'document_id' => (string)($hit['document_id'] ?? ''),
                'base_id' => (string)($hit['base_id'] ?? ''),
                'title' => (string)($hit['title'] ?? ''),
            ];
            return;
        }
        $merged[$id]['score'] = round($merged[$id]['score'] + $part, 4);
        if ($merged[$id]['content'] === '' && isset($hit['content'])) {
            $merged[$id]['content'] = (string)$hit['content'];
        }
        if ($merged[$id]['title'] === '' && isset($hit['title'])) {
            $merged[$id]['title'] = (string)$hit['title'];
        }
    }
}
