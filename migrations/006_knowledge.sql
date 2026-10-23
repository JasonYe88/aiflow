-- 006: 知识库架子（库 / 文档 / 分段 / 入口绑定）
-- 本阶段不做真实 Embedding：chunk 存纯文本，默认 SimpleKeywordRetriever 检索
-- 业务内容灌库、向量索引、上传 UI 均后置

CREATE TABLE ai_knowledge_base (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    code        VARCHAR(128) NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description VARCHAR(512) NOT NULL,
    owner_id    VARCHAR(64)  NOT NULL,
    status      VARCHAR(16)  NOT NULL,
    retriever   VARCHAR(32)  NOT NULL,
    meta_json   TEXT         NOT NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_kb_code ON ai_knowledge_base (code);
CREATE INDEX idx_ai_kb_owner ON ai_knowledge_base (owner_id);

CREATE TABLE ai_knowledge_document (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    base_id     VARCHAR(32)  NOT NULL,
    title       VARCHAR(255) NOT NULL,
    source_type VARCHAR(16)  NOT NULL,
    source_ref  VARCHAR(255) NOT NULL,
    status      VARCHAR(16)  NOT NULL,
    enabled     INT          NOT NULL DEFAULT 0,
    word_count  INT          NOT NULL DEFAULT 0,
    error       TEXT,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL
);
CREATE INDEX idx_ai_kdoc_base ON ai_knowledge_document (base_id);

CREATE TABLE ai_knowledge_chunk (
    id             VARCHAR(32) NOT NULL PRIMARY KEY,
    document_id    VARCHAR(32) NOT NULL,
    base_id        VARCHAR(32) NOT NULL,
    position       INT         NOT NULL,
    content        MEDIUMTEXT  NOT NULL,
    token_estimate INT         NOT NULL DEFAULT 0,
    created_at     DATETIME    NOT NULL
);
CREATE INDEX idx_ai_kchunk_base ON ai_knowledge_chunk (base_id);
CREATE INDEX idx_ai_kchunk_doc ON ai_knowledge_chunk (document_id);

CREATE TABLE ai_knowledge_binding (
    id         VARCHAR(32)  NOT NULL PRIMARY KEY,
    entry_id   VARCHAR(128) NOT NULL,
    base_id    VARCHAR(32)  NOT NULL,
    env        VARCHAR(16)  NOT NULL,
    is_enabled INT          NOT NULL DEFAULT 1,
    updated_by VARCHAR(64)  NOT NULL,
    updated_at DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_kbind ON ai_knowledge_binding (entry_id, base_id, env);
CREATE INDEX idx_ai_kbind_entry ON ai_knowledge_binding (entry_id);