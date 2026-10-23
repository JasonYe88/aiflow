-- 005: 可视化工作流的草稿与已发布图
-- 节点明细（HTTP、模型等）不在本表展开，整张图以 JSON 存放
-- published_json 为空字符串表示尚未发布，运行只认已发布图

CREATE TABLE ai_workflow_graph (
    workflow_id    VARCHAR(64) NOT NULL PRIMARY KEY,
    draft_json     TEXT        NOT NULL,
    published_json TEXT        NOT NULL,
    updated_at     DATETIME    NOT NULL
);
