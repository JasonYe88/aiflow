<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

/**
 * 检索器契约：可替换实现（simple 关键词 / 日后向量 / 外部 Gaia API）
 */
interface RetrieverInterface
{
    /**
     * @param string $baseId 知识库 id
     * @param string $query 用户问题
     * @param array $opt { top_k?: int }
     * @return array<int, array{content:string,score:float,document_id:string,base_id:string,title:string,chunk_id:string}>
     */
    public function retrieve(string $baseId, string $query, array $opt = []): array;
}