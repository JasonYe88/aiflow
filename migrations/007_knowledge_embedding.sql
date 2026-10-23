-- 007: 知识库分段向量缓存（OpenAI 兼容 /embeddings 写入；检索用 EmbeddingRetriever）
-- embedding_json 为空表示尚未建索引；simple/http 检索忽略本列

ALTER TABLE ai_knowledge_chunk ADD COLUMN embedding_json TEXT;
