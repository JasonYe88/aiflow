<?php
declare(strict_types=1);

namespace AIFlow\Contract;

/**
 * 可选能力：OpenAI 兼容 /embeddings
 * 未实现的 Provider 不能作为 knowledge.embed_channel
 */
interface EmbeddingCapableInterface
{
    /**
     * @param string[] $texts
     * @param array $options 可选：model 覆盖等
     * @return float[][] 与 $texts 等长的向量列表
     */
    public function embed(array $texts, array $options = []): array;
}
