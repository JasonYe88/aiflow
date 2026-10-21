-- 003: M4 工作流异步队列（存储表实现，零外部依赖）
-- 设计决策（2026-10-21 拍板）：异步 dispatch 默认存储表队列，Redis 队列后续做可插拔 QueueInterface
-- 驱动方式：宿主 cron / FPM 请求尾巴循环调 WorkflowEngine::consume() 消费
CREATE TABLE ai_workflow_queue (
    id             VARCHAR(32)  NOT NULL PRIMARY KEY,
    workflow_class VARCHAR(191) NOT NULL,
    status         VARCHAR(16)  NOT NULL,
    input          MEDIUMTEXT,
    ref_id         VARCHAR(64),
    run_id         VARCHAR(32),
    attempts       INT          NOT NULL DEFAULT 0,
    error          MEDIUMTEXT,
    created_at     DATETIME     NOT NULL,
    started_at     DATETIME,
    finished_at    DATETIME
);
CREATE INDEX idx_ai_queue_status ON ai_workflow_queue (status);