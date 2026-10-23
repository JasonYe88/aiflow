# 知识库架子 Todo（当前主线）

> 更新：2026-10-22  
> **K 架子已落地。** 下一主线：对照 Gaia 创建/单库 IA 升级 Demo 运营面（L 区）。  
> 计划：[../plans/2026-10-22-aiflow-knowledge-skeleton.md](../plans/2026-10-22-aiflow-knowledge-skeleton.md)  
> **调研主文档：** [gaia-dify-research.md](gaia-dify-research.md)（Chrome 中文 Gaia + Dify 官网；内置浏览器不作依据）

勾选：`[ ]` / `[x]` / `[-]` 不做

---

## 搁置（明确不在本阶段）

- [-] Gaia 文档 disabled 恢复 / 串绑清理 / 空壳库补数（原 H1～H6）
- [-] 灌入制度 PDF、分类表等真实内容
- [-] Notion / 网站同步 / Webhook / External Knowledge API
- [-] PDF/Office 解析器、完整权限矩阵
- [-] FMS 业务页改造

---

## K 架子任务 ✅

- [x] K1 迁移 `006_knowledge.sql`（base/document/chunk/binding）+ `database/schema.mysql.sql` 同步 + Migrator 断言更新
- [x] K2 `RetrieverInterface` + `SimpleKeywordRetriever`（无向量；仅检索已启用文档的 chunk）
- [x] K3 `KnowledgeService`：createBase / archiveBase / ingestText / setDocumentEnabled / bind / retrieve / 列表查询
- [x] K4 `AIKernel::knowledge()` 惰性装配
- [x] K5 `tests/Knowledge/*` TDD 全绿 + `examples/knowledge.php` 冒烟（假数据）
- [x] K6 文档：`docs/integration.md` 增加知识库架子小节；本 Todo 与计划勾选同步
- [x] K7（可选）`KnowledgeRetrieveTool` 挂 Agent，权限门建议 `tool:knowledge.retrieve`

---

## L 学 Gaia IA → Demo 运营面（当前）

对照：`gaia.yafex.cn/datasets/create` + 单库 Documents / Retrieval Testing / Settings。

- [x] L0 产品调研：Chrome 操作 Gaia 中文 UI + Dify 官网知识库文档 → [gaia-dify-research.md](gaia-dify-research.md)
- [x] L1 CatalogConsole：知识库列表卡片（文档数 / 粗估词量 / 绑定入口数）+ 空库创建
- [x] L2 单库页三栏：文档列表（启用/禁用）· 召回测试（hits 明细）· 设置（名/描述/绑定）
- [x] L3 Add 文本 + `ingestFile`（TXT/MD）；分段预览
- [x] L4 对话页仅消费绑定（`knowledge_seed=false`）；运营动作全部进知识库页
- [x] L5 创建向导对齐 Gaia Step1：选数据源 + 拖拽上传；Step2 分段预览；Step3 建库入库（Notion/Web/PDF 后置）
- [x] L6 Step2 对齐 Gaia：通用分段参数 / 经济·高质量索引 / 向量·关键词·混合检索配置（写入 meta_json；父子/Q&A/Rerank 后置）

---

## 架子验收口令

```bash
php vendor/phpunit/phpunit/phpunit --filter Knowledge
php examples/knowledge.php
```

期望：建库 → 写入两段假文本 → 启用 → 绑定 `app:demo` → retrieve 能命中；禁用文档后不再命中。
