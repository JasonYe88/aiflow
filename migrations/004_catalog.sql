-- 004: 编目控制台登记表（技能 / 工具 / 入口绑定 / 个人规则 / 工作流清单）
-- 工作流清单只做登记，执行仍走 PHP 工作流类

CREATE TABLE ai_skill (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    skill_id    VARCHAR(128) NOT NULL,
    name        VARCHAR(255) NOT NULL,
    summary     VARCHAR(512) NOT NULL,
    instruction TEXT         NOT NULL,
    tools_json  TEXT         NOT NULL,
    owner_id    VARCHAR(64)  NOT NULL,
    is_system   INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_skill_sid ON ai_skill (skill_id);

CREATE TABLE ai_tool (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    tool_id     VARCHAR(128) NOT NULL,
    name        VARCHAR(128) NOT NULL,
    description TEXT         NOT NULL,
    is_async    INT          NOT NULL DEFAULT 0,
    php_ref     VARCHAR(255) NOT NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_tool_tid ON ai_tool (tool_id);

CREATE TABLE ai_entry (
    id            VARCHAR(32)  NOT NULL PRIMARY KEY,
    entry_id      VARCHAR(128) NOT NULL,
    skill_id      VARCHAR(128) NOT NULL,
    is_production INT          NOT NULL DEFAULT 1,
    is_enabled    INT          NOT NULL DEFAULT 1,
    updated_by    VARCHAR(64)  NOT NULL,
    updated_at    DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_entry_eid ON ai_entry (entry_id);

CREATE TABLE ai_user_rule (
    user_id     VARCHAR(64) NOT NULL PRIMARY KEY,
    dont_text   TEXT        NOT NULL,
    prefer_text TEXT        NOT NULL,
    updated_at  DATETIME    NOT NULL
);

CREATE TABLE ai_workflow_def (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    tag         VARCHAR(64)  NOT NULL,
    description VARCHAR(512) NOT NULL,
    status      VARCHAR(16)  NOT NULL,
    is_template INT          NOT NULL DEFAULT 0,
    owner_id    VARCHAR(64)  NOT NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL
);
CREATE INDEX idx_ai_wfdef_updated ON ai_workflow_def (updated_at);
