# 知识库产品调研（Gaia Chrome + Dify 官网）

> 调研日：2026-10-22  
> 方法：本机 **Google Chrome**（`user-chrome-devtools`）操作 `gaia.yafex.cn`；对照 [Dify 知识库中文文档](https://docs.dify.ai/zh/self-host/use-dify/knowledge/readme.md)。  
> **注意：** Cursor 内置浏览器打开同一 URL 常为英文/缺样式，**不能**作为产品调研依据；以 Chrome 中文界面为准。

临时库：`aiflow-learn-tmp`（可删）。

---

## 0. 调研结论（一句话）

Gaia = 私有化 Dify。知识库是与「工作室」并列的一等产品：先管内容与召回，再被应用引用。aiflow 已有表与检索架子；要学的是 **运营闭环与能力分层**，不是抄 Notion/爬站/多模态。

---

## 1. Chrome 实操：Gaia 中文界面（事实）

### 1.1 顶栏与列表 `/datasets`

| 元素 | 文案 |
|------|------|
| 顶栏 | 探索 · **工作室** · **知识库** · 工具 · AI 作图 · 插件 |
| 列表 Tab | **知识库** / **API** |
| 筛选 | 所有知识库 · 全部标签 · 搜索 · **外部知识库 API** |
| 创建卡 | **创建知识库**；副文案含 Webhook；链到「连接外部知识库」 |
| 库卡片 | 名 + `N 文档 · N 千字符 · N 关联应用` |

实测卡片：`aiflow-learn-tmp` → `0 文档 · 0 千字符 · 0 关联应用`。

### 1.2 创建向导 `/datasets/create`

三步（与用户 Chrome 截图一致）：

1. **选择数据源**  
2. **文本分段与清洗**  
3. **处理并完成**

数据源三选一：

| 选项 | Chrome 所见 |
|------|-------------|
| **导入已有文本** | 上传区；TXT/MD/MDX/PDF/HTML/XLSX/XLS/DOCX/CSV…；单文件 ≤60MB；无文件则「下一步」禁用 |
| **同步自 Notion 内容** | 「Notion 未绑定」+「去绑定」（与截图一致） |
| **同步自 Web 站点** | 爬站入口 |

旁路：**创建一个空知识库**（只起名 → 进文档页再添加）。

### 1.3 单库三页（空库 `aiflow-learn-tmp`）

侧栏标签：**本地文档** · **无关联应用**

| 页 | 路由 | 能力 |
|----|------|------|
| **文档** | `/documents` | 搜索 · 元数据 · **添加文件**；空态引导上传/网站/Notion 等 |
| **召回测试** | `/hitTesting` | 源文本 ≤200 字 · 模式角标「向量检索」· **测试** · 记录 · 右侧结果区 |
| **设置** | `/settings` | 名称 · 描述 · 可见权限（只有我）· 保存；空库时尚无完整索引/检索块 |

---

## 2. Dify 官网能力地图（规范）

主入口：[知识库](https://docs.dify.ai/zh/self-host/use-dify/knowledge/readme.md)

### 2.1 三条创建路径

1. **即用型**：导入 → 分段清洗 → 索引/检索 → 处理完成  
2. **自定义知识流水线**（Knowledge Pipeline）：可编排复杂处理  
3. **连接外部知识库**：API 对接，不迁数据  

即用型数据源：本地文件 / Notion / 网页 / 空库。  
创建后 **数据源类型不可改**（文档说明）。

### 2.2 分段与清洗（Step 2）

- 参数：分隔符、最大长度、重叠长度  
- 模式：**通用**（单层） vs **父子**（子匹配、父返回）；库级模式创建后不可改  
- 预处理：连续空白、去 URL/邮箱  
- 可选：分段摘要自动生成（再嵌入）  
- **预览分段** 后再确认  

### 2.3 索引与检索（Step 3 / 设置）

| 索引 | 检索选项 | 备注 |
|------|----------|------|
| **高质量**（Embedding） | 向量 / 全文 / **混合**；可选 Rerank、权重、TopK、Score | 高质量不可降回经济；可选 Q&A（Q to Q） |
| **经济**（关键词/倒排） | 倒排 + TopK | 可升级为高质量 |

混合检索：语义↔关键词权重，或外接 Rerank。

### 2.4 文档与分段运营

文档：增删、启用/禁用、归档、重命名、改分段设置（结构不可改）、摘要、元数据。  
分段：增删改、启用/禁用、关键词（经济）、图片附件、摘要作检索钩子。  
父子模式：子分段可改写为关键词/摘要/常见问法。

### 2.5 召回测试

- 临时改检索参数，**不落库**  
- 与正式 retrieve **同一 API**  
- 「记录」含：本页测试 + **关联应用** 的生产/调试召回  

### 2.6 接到应用（工作室侧）

聊天助手：上下文 → 添加知识库 → 召回策略 → 元数据筛选 → 引用归属 → 调试发布。  
多库：**多路召回** + 权重或 Rerank；Embedding 不一致时强制 Rerank。  
Chatflow/Workflow：**知识检索** 节点 + 元数据筛选（禁用/自动/手动）。  
知识库侧栏展示 **关联应用数**，可跳转。

### 2.7 API 生命周期（开发视角）

空库 → `create-by-text` / `create-by-file`（异步 `batch`）→ 轮询 indexing（waiting→parsing→cleaning→splitting→indexing→completed）→ retrieve 测试。  
文档状态批处理：enable / disable / archive。

---

## 3. aiflow 对照与取舍

| Dify/Gaia | aiflow 现状 | 建议 |
|-----------|-------------|------|
| 列表卡片（文档/千字符/关联应用） | CatalogConsole 扁列表 | **做**（L1） |
| 空库 + 单库「文档/召回测试/设置」 | 一页表单 | **做**（L2） |
| 文件上传多格式 | `ingestText` / `ingestFile` 文本 | **先 TXT/MD**；PDF/Office 后置 |
| 分段预览 / 分隔符 / 重叠 | 空行分段 | **做预览**；策略进 `meta_json` |
| 通用 vs 父子 | 无 | **后置** |
| 高质量 Embedding | EmbeddingRetriever / Hybrid 架子 | **保留**；默认可经济/关键词 |
| Q&A / 摘要自动生成 / 多模态 | 无 | **不做**（近中期） |
| Notion / Web / Webhook / 外部库 API | 无 | **不做**（可远期「外部检索适配器」） |
| 元数据筛选 | 无 | **后置** |
| 多路召回 + Rerank | 单入口 binding | **单库绑定先稳**；多库合并后置 |
| 工作室引用知识库 | ChatDemo 注入 + binding | **保持**：对话消费、知识库运营分离 |
| 异步 indexing 状态机 | 同步 ingest | 小文件同步；大文件再补 status |

### 产品原则（写入后续实现）

1. **管库与用库分离**：CatalogConsole「知识库」管内容；对话/Agent 只读绑定。  
2. **空库合法**：先建容器，再 Add。  
3. **召回可测**：独立试跑 + hits 明细，不依赖整轮对话。  
4. **能力分层**：P0 运营 IA + 文本入库 + 关键词/可选向量；P1 分段策略与预览；P2 外部源与高级检索。

---

## 4. 推荐落地顺序（调研 → 实现）

| 优先级 | 项 | 对应 |
|--------|----|------|
| P0 | 列表卡片 + 空库 | L1 |
| P0 | 单库：文档 / 召回测试 / 设置 | L2 |
| P0 | Add 文本 + TXT/MD 文件；文档启用/禁用 | L3 |
| P0 | 对话只消费 binding；运营进知识库页 | L4 |
| P1 | 分段参数 + 预览；indexing status 字段 | — |
| P1 | 召回测试临时 TopK / 模式切换 | — |
| P2 | PDF/Office、父子、元数据、多库 Rerank、外部 API | — |

---

## 5. 信息源

| 源 | 用途 |
|----|------|
| Chrome · `gaia.yafex.cn` | 中文 IA、文案、空态、权限展示 |
| [Dify 知识库](https://docs.dify.ai/zh/self-host/use-dify/knowledge/readme.md) | 能力全景 |
| [分段与清洗](https://docs.dify.ai/zh/self-host/use-dify/knowledge/create-knowledge/chunking-and-cleaning-text.md) | Step2 |
| [索引与检索](https://docs.dify.ai/zh/self-host/use-dify/knowledge/create-knowledge/setting-indexing-methods.md) | Step3 |
| [维护文档](https://docs.dify.ai/zh/self-host/use-dify/knowledge/manage-knowledge/maintain-knowledge-documents.md) | 运营 |
| [召回测试](https://docs.dify.ai/zh/self-host/use-dify/knowledge/test-retrieval.md) | 测试页 |
| [应用内集成](https://docs.dify.ai/zh/self-host/use-dify/knowledge/integrate-knowledge-within-application.md) | 用库 |
| 用户 Chrome 截图 | 纠正内置浏览器偏差 |

---

## 6. 调研规范（后续）

对 Gaia/Dify 类页面做产品调研时：

1. 优先用本机 **Chrome**（MCP `user-chrome-devtools`）  
2. 内置浏览器仅作连通性检查，**不以样式/语言为准**  
3. 能力边界以 **docs.dify.ai** 为准，Gaia 作私有化 UI 验证  
