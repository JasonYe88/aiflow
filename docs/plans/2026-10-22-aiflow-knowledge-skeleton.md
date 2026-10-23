# aiflow 知识库架子（Knowledge Skeleton）实施计划

> 创建：2026-10-22  
> 决策：**先搭架子，不做具体业务内容灌库，也不做 Gaia 内容止血。**  
> Gaia 运营文档（ops-policy / checklist H 区）保留作日后参考，本里程碑不执行。

---

## 目标

在 aiflow 内落地可嵌入的知识库**最小闭环架子**：

```
知识库 → 文档（启用/禁用）→ 分段（chunks）→ 检索（可替换实现）→ 入口绑定 → Agent Tool 可调用
```

学 Gaia 的是**结构与职责边界**，不是抄 UI，也不是现在搬美客多/制度正文。

---

## 范围

### ✅ 做（架子）

| 项 | 说明 |
|----|------|
| 表结构 | `ai_knowledge_base` / `ai_knowledge_document` / `ai_knowledge_chunk` / `ai_knowledge_binding` |
| KnowledgeService | 建库/归档、登记文档、启用禁用、纯文本入库并分段、绑定入口、列表查询 |
| RetrieverInterface | 检索契约；默认 `SimpleKeywordRetriever`（无向量依赖，测架子用） |
| Kernel | `$kernel->knowledge()` 惰性装配 |
| 权限挂钩 | 资源类型沿用 `ai_resource.type = knowledge`（可选 guard，架子阶段可先 API 级校验 owner） |
| 测试 + 示例 | PHPUnit + `examples/knowledge.php`（假数据，非生产内容） |
| 文档 | integration 增一小节；本计划；架子 Todo |

### ❌ 不做（本阶段）

- 不灌 Gaia 真实文档 / 不修 disabled / 不拆串绑  
- 不做上传 UI、文件存储、Webhook  
- 不做真实 Embedding / 向量库 / Rerank（预留接口即可）  
- 不做 Python RAG 桥（仍属 M5）  
- 不改 FMS 业务页  

---

## 数据模型（草案）

```
ai_knowledge_base
  id, code(唯一), name, description, owner_id, status(draft|active|archived),
  retriever(simple|…), meta_json, created_at, updated_at

ai_knowledge_document
  id, base_id, title, source_type(text|file|url|external), source_ref,
  status(pending|ready|failed), enabled(0|1), word_count,
  error, created_at, updated_at

ai_knowledge_chunk
  id, document_id, base_id, position, content, token_estimate, created_at
  -- 索引：base_id + document 便于按库检索；enabled 文档才参与

ai_knowledge_binding
  id, entry_id(业务入口如 app:xxx), base_id, env(prod|test),
  is_enabled, updated_by, updated_at
  UNIQUE(entry_id, base_id, env)
```

迁移文件：`migrations/006_knowledge.sql`（接在 005 之后）。

---

## 服务 API（架子）

```php
$kb = $kernel->knowledge();

$baseId = $kb->createBase('demo.faq', '演示FAQ', 'u1', '仅架子演示');
$docId  = $kb->ingestText($baseId, '演示文档', "问：…\n答：…\n\n下一段…");
$kb->setDocumentEnabled($docId, true);
$kb->bind('app:demo_chat', $baseId, 'prod', 'u1');

$hits = $kb->retrieve('app:demo_chat', '用户问题', ['top_k' => 5]);
// → [{content, score, document_id, base_id, title}, …]
```

Agent 衔接（架子可选 Task）：提供 `KnowledgeRetrieveTool` 实现 `ToolInterface`，权限门 `tool:knowledge.retrieve`。

---

## Task 分解

| Task | 内容 | 完成标准 | 状态 |
|------|------|----------|------|
| K1 | `006_knowledge.sql` + schema.mysql.sql 同步 + MigratorTest 更新 | migrate 幂等；表存在 | ✅ |
| K2 | `RetrieverInterface` + `SimpleKeywordRetriever` | 单测：命中/未命中/仅 enabled | ✅ |
| K3 | `KnowledgeService` CRUD + ingestText 分段 + bind | 单测全绿 | ✅ |
| K4 | `AIKernel::knowledge()` | KernelBoot 用例 | ✅ |
| K5 | `examples/knowledge.php` + CI 一步 | 离线可跑 | ✅ |
| K6 | docs：integration 小节 + checklist I 区与本计划对齐 | 文档一致 | ✅ |
| K7 | `KnowledgeRetrieveTool` | Agent 可注册 | ✅ |

---

## 验收口令

```bash
php vendor/phpunit/phpunit/phpunit --filter Knowledge
php examples/knowledge.php
```

用**假文本**演示闭环即可，不出现真实制度/分类业务内容。

---

## 与现有文档关系

| 文档 | 地位 |
|------|------|
| [ops-policy.md](../knowledge/ops-policy.md) | 组织制度参考，**本阶段不执行内容运营** |
| [todo-checklist.md](../knowledge/todo-checklist.md) H/J | Gaia 止血 / 发布单，**搁置** |
| [todo-checklist.md](../knowledge/todo-checklist.md) I | 与本计划对齐，本阶段只盯 I/K |
| 本文 | **当前主线** |
