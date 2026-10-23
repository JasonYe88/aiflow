# Changelog

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/)。版本号以 Git tag 为准（如 `v0.1.0`），`composer.json` 不写死 version。

## [Unreleased]

（无）

## [0.1.0] — 2026-10-22

### 首次公开发布候选（M5）

- Kernel：`AIKernel::boot` + 惰性服务（chat / credit / guard / agent / workflow / graph / knowledge / embeddingIndexer）
- 模型网关：OpenAI 兼容 Provider + SSE 流式 + `/embeddings`
- 积分：PriceBook + 钱包两阶段记账 + Billing
- RBAC：deny 优先、通配
- Agent：function-calling 循环 + ToolInterface
- 工作流：task / decision / llm / parallel + 存储表异步队列
- 知识库架子：建库 / 文本分段 / 入口绑定 / SimpleKeywordRetriever
- Python RAG 桥（可选）：`plugins/python-rag` + `HttpRagRetriever`
- Embedding 检索：`EmbeddingIndexer` / `EmbeddingRetriever`（迁移 007 `embedding_json`）
- 工作流知识注入：`RetrieveKnowledgeTask` + `KnowledgeContext`
- `ingestFile` / `updateBase`；`HybridRetriever`（`knowledge.retriever=hybrid`）
- PHP >= 7.3；MySQL / SQLite 双兼容迁移（001–007）

[Unreleased]: https://github.com/JasonYe88/aiflow/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/JasonYe88/aiflow/releases/tag/v0.1.0
