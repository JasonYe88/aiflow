-- ============================================================================
-- AIFlow 完整建表 DDL（MySQL 终态，快速安装用）
-- ============================================================================
-- 内容：11 张 ai_* 表 + ai_migration 版本表 =  migrations/001 + 002 + 003 的最终形态
-- 适用：MySQL 5.7 / 8.0，utf8mb4，强制 InnoDB（钱包并发扣减/队列抢占依赖事务与行锁）
--
-- 用法（二选一）：
--   1) 快速灌库（推荐新宿主）：
--        mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS fms DEFAULT CHARSET utf8mb4"
--        mysql -u root -p fms < database/schema.mysql.sql
--      然后宿主配置 'migration' => ['auto' => true] 亦安全：
--      文件末尾已把 001/002/003 预登记进 ai_migration，Migrator 会全部跳过，
--      将来包升级新增 004 时只执行增量——与自动迁移殊途同归。
--   2) 继续走自动迁移：配置 'migration' => ['auto' => true]，boot 时自动建表，
--      本文件可不入库（见 docs/integration.md §5）。
--
-- 约定：主键为框架生成的 32 位十六进制字符串（Id::gen，无 AUTO_INCREMENT）；
--       金额/余额单位为分；token 数量为 BIGINT。
-- DDL 全部 IF NOT EXISTS + REPLACE 幂等，可重复执行。
-- ============================================================================

CREATE TABLE IF NOT EXISTS ai_role (
    id         VARCHAR(32)  NOT NULL PRIMARY KEY,
    code       VARCHAR(64)  NOT NULL,
    name       VARCHAR(128) NOT NULL,
    created_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX uk_ai_role_code ON ai_role (code);

CREATE TABLE IF NOT EXISTS ai_resource (
    id         VARCHAR(32)  NOT NULL PRIMARY KEY,
    type       VARCHAR(16)  NOT NULL, -- app | workflow | tool
    identifier VARCHAR(128) NOT NULL,
    name       VARCHAR(128) NOT NULL,
    created_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX uk_ai_resource_ident ON ai_resource (type, identifier);

CREATE TABLE IF NOT EXISTS ai_role_resource (
    id          VARCHAR(32) NOT NULL PRIMARY KEY,
    role_id     VARCHAR(32) NOT NULL,
    resource_id VARCHAR(32) NOT NULL,
    effect      VARCHAR(8)  NOT NULL, -- allow | deny，deny 优先
    created_at  DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX uk_ai_role_res ON ai_role_resource (role_id, resource_id);

CREATE TABLE IF NOT EXISTS ai_wallet (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    owner_type VARCHAR(16) NOT NULL, -- user | dept | app
    owner_id   VARCHAR(32) NOT NULL,
    balance    BIGINT      NOT NULL DEFAULT 0, -- 余额（分）
    frozen     BIGINT      NOT NULL DEFAULT 0, -- 预检冻结（分）
    created_at DATETIME    NOT NULL,
    updated_at DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX uk_ai_wallet_owner ON ai_wallet (owner_type, owner_id);

CREATE TABLE IF NOT EXISTS ai_price_rule (
    id                VARCHAR(32)   NOT NULL PRIMARY KEY,
    model             VARCHAR(64)   NOT NULL,
    input_price_per_m  BIGINT       NOT NULL, -- 每百万 input token 单价（分）
    output_price_per_m BIGINT       NOT NULL, -- 每百万 output token 单价（分）
    multiplier        DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    created_at        DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX uk_ai_price_model ON ai_price_rule (model);

CREATE TABLE IF NOT EXISTS ai_ledger_entry (
    id            VARCHAR(32) NOT NULL PRIMARY KEY,
    wallet_id     VARCHAR(32) NOT NULL,
    model         VARCHAR(64) NOT NULL,
    input_tokens  BIGINT      NOT NULL DEFAULT 0,
    output_tokens BIGINT      NOT NULL DEFAULT 0,
    amount        BIGINT      NOT NULL, -- 正=扣费，负=冲正（分）
    kind          VARCHAR(8)  NOT NULL, -- reserve | settle | refund
    ref_id        VARCHAR(64) NOT NULL, -- 调用链 ID，幂等键
    created_at    DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ai_ledger_wallet ON ai_ledger_entry (wallet_id);
CREATE INDEX idx_ai_ledger_ref    ON ai_ledger_entry (ref_id);

CREATE TABLE IF NOT EXISTS ai_conversation (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    user_id    VARCHAR(32) NOT NULL,
    app_id     VARCHAR(32) NOT NULL,
    title      VARCHAR(255),
    updated_seq VARCHAR(32), -- 会话活跃序列（毫秒前缀单调 id；002 迁移增加）
    created_at DATETIME    NOT NULL,
    updated_at DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ai_conv_user ON ai_conversation (user_id);

CREATE TABLE IF NOT EXISTS ai_message (
    id              VARCHAR(32) NOT NULL PRIMARY KEY,
    conversation_id VARCHAR(32) NOT NULL,
    role            VARCHAR(16) NOT NULL, -- user | assistant | system | tool
    content         MEDIUMTEXT,
    input_tokens    BIGINT      NOT NULL DEFAULT 0,
    output_tokens   BIGINT      NOT NULL DEFAULT 0,
    elapsed_ms      BIGINT      NOT NULL DEFAULT 0,
    created_at      DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ai_msg_conv ON ai_message (conversation_id);

CREATE TABLE IF NOT EXISTS ai_workflow_run (
    id          VARCHAR(32) NOT NULL PRIMARY KEY,
    workflow_id VARCHAR(64) NOT NULL, -- 工作流标识
    status      VARCHAR(16) NOT NULL, -- running | success | failed
    context     MEDIUMTEXT,           -- 运行上下文 JSON
    ref_id      VARCHAR(64),
    started_at  DATETIME    NOT NULL,
    finished_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ai_run_wf ON ai_workflow_run (workflow_id);

CREATE TABLE IF NOT EXISTS ai_workflow_run_log (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    run_id     VARCHAR(32) NOT NULL,
    node_key   VARCHAR(64)  NOT NULL,
    node_index INT          NOT NULL,
    status     VARCHAR(16)  NOT NULL, -- running | success | failed | skipped
    input      MEDIUMTEXT,
    output     MEDIUMTEXT,
    created_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ai_runlog_run ON ai_workflow_run_log (run_id);

CREATE TABLE IF NOT EXISTS ai_workflow_queue (
    id             VARCHAR(32)  NOT NULL PRIMARY KEY,
    workflow_class VARCHAR(191) NOT NULL,
    status         VARCHAR(16)  NOT NULL, -- queued | running | done | failed
    input          MEDIUMTEXT,
    ref_id         VARCHAR(64),
    run_id         VARCHAR(32),
    attempts       INT          NOT NULL DEFAULT 0,
    error          MEDIUMTEXT,
    created_at     DATETIME     NOT NULL,
    started_at     DATETIME,
    finished_at    DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ai_queue_status ON ai_workflow_queue (status);

CREATE TABLE IF NOT EXISTS ai_migration (
    version    VARCHAR(64) NOT NULL PRIMARY KEY,
    applied_at DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 预登记已并入本 DDL 的迁移版本：boot('migration.auto' => true) 时 Migrator 全部跳过，
-- 后续包升级新增迁移（如 004+）仍会正常增量执行
REPLACE INTO ai_migration (version, applied_at) VALUES
    ('001_init.sql', NOW()),
    ('002_conversation_activity_seq.sql', NOW()),
    ('003_workflow_queue.sql', NOW());