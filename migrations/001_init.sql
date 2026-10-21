-- AIFlow 初始结构：10 张核心表
-- 约定：主键为框架生成的 32 位十六进制字符串；金额/余额单位为分；token 数量为 BIGINT
-- 可移植性：不用 AUTO_INCREMENT / ENGINE / 内联 KEY；二级索引统一 CREATE INDEX

CREATE TABLE ai_role (
    id         VARCHAR(32)  NOT NULL PRIMARY KEY,
    code       VARCHAR(64)  NOT NULL,
    name       VARCHAR(128) NOT NULL,
    created_at DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_role_code ON ai_role (code);

CREATE TABLE ai_resource (
    id         VARCHAR(32)  NOT NULL PRIMARY KEY,
    type       VARCHAR(16)  NOT NULL, -- app | workflow | tool
    identifier VARCHAR(128) NOT NULL,
    name       VARCHAR(128) NOT NULL,
    created_at DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_resource_ident ON ai_resource (type, identifier);

CREATE TABLE ai_role_resource (
    id          VARCHAR(32) NOT NULL PRIMARY KEY,
    role_id     VARCHAR(32) NOT NULL,
    resource_id VARCHAR(32) NOT NULL,
    effect      VARCHAR(8)  NOT NULL, -- allow | deny，deny 优先
    created_at  DATETIME    NOT NULL
);
CREATE UNIQUE INDEX uk_ai_role_res ON ai_role_resource (role_id, resource_id);

CREATE TABLE ai_wallet (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    owner_type VARCHAR(16) NOT NULL, -- user | dept | app
    owner_id   VARCHAR(32) NOT NULL,
    balance    BIGINT      NOT NULL DEFAULT 0, -- 余额（分）
    frozen     BIGINT      NOT NULL DEFAULT 0, -- 预检冻结（分）
    created_at DATETIME    NOT NULL,
    updated_at DATETIME    NOT NULL
);
CREATE UNIQUE INDEX uk_ai_wallet_owner ON ai_wallet (owner_type, owner_id);

CREATE TABLE ai_price_rule (
    id                VARCHAR(32)   NOT NULL PRIMARY KEY,
    model             VARCHAR(64)   NOT NULL,
    input_price_per_m  BIGINT       NOT NULL, -- 每百万 input token 单价（分）
    output_price_per_m BIGINT       NOT NULL, -- 每百万 output token 单价（分）
    multiplier        DECIMAL(10,4)  NOT NULL DEFAULT 1.0000,
    created_at        DATETIME      NOT NULL
);
CREATE UNIQUE INDEX uk_ai_price_model ON ai_price_rule (model);

CREATE TABLE ai_ledger_entry (
    id            VARCHAR(32) NOT NULL PRIMARY KEY,
    wallet_id     VARCHAR(32) NOT NULL,
    model         VARCHAR(64) NOT NULL,
    input_tokens  BIGINT      NOT NULL DEFAULT 0,
    output_tokens BIGINT      NOT NULL DEFAULT 0,
    amount        BIGINT      NOT NULL, -- 正=扣费，负=冲正
    kind          VARCHAR(8)  NOT NULL, -- reserve | settle | refund
    ref_id        VARCHAR(64) NOT NULL, -- 调用链 ID，幂等键
    created_at    DATETIME    NOT NULL
);
CREATE INDEX idx_ai_ledger_wallet ON ai_ledger_entry (wallet_id);
CREATE INDEX idx_ai_ledger_ref    ON ai_ledger_entry (ref_id);

CREATE TABLE ai_conversation (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    user_id    VARCHAR(32) NOT NULL,
    app_id     VARCHAR(32) NOT NULL,
    title      VARCHAR(255),
    created_at DATETIME    NOT NULL,
    updated_at DATETIME    NOT NULL
);
CREATE INDEX idx_ai_conv_user ON ai_conversation (user_id);

CREATE TABLE ai_message (
    id              VARCHAR(32) NOT NULL PRIMARY KEY,
    conversation_id VARCHAR(32) NOT NULL,
    role            VARCHAR(16) NOT NULL, -- user | assistant | system | tool
    content         MEDIUMTEXT,
    input_tokens    BIGINT      NOT NULL DEFAULT 0,
    output_tokens   BIGINT      NOT NULL DEFAULT 0,
    elapsed_ms      BIGINT      NOT NULL DEFAULT 0,
    created_at      DATETIME    NOT NULL
);
CREATE INDEX idx_ai_msg_conv ON ai_message (conversation_id);

CREATE TABLE ai_workflow_run (
    id          VARCHAR(32) NOT NULL PRIMARY KEY,
    workflow_id VARCHAR(64)  NOT NULL, -- 工作流标识
    status      VARCHAR(16)  NOT NULL, -- running | success | failed
    context     TEXT,                  -- 运行上下文 JSON
    ref_id      VARCHAR(64),
    started_at  DATETIME    NOT NULL,
    finished_at DATETIME
);
CREATE INDEX idx_ai_run_wf ON ai_workflow_run (workflow_id);

CREATE TABLE ai_workflow_run_log (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    run_id     VARCHAR(32) NOT NULL,
    node_key   VARCHAR(64)  NOT NULL,
    node_index INT          NOT NULL,
    status     VARCHAR(16)  NOT NULL, -- running | success | failed | skipped
    input      MEDIUMTEXT,
    output     MEDIUMTEXT,
    created_at DATETIME     NOT NULL
);
CREATE INDEX idx_ai_runlog_run ON ai_workflow_run_log (run_id);