# AIFlow

可嵌入 PHP 项目的 LLMOps 内核：权限、积分、对话、Agent、工作流、知识库。

> **M5 开源发布候选**：Packagist 包名 `jasonye88/aiflow`（Apache-2.0）。你推送 `v0.1.0` tag 后即可在 [Packagist](https://packagist.org) 提交收录——步骤见 [docs/release.md](docs/release.md)。
> 仓库：[github.com/JasonYe88/aiflow](https://github.com/JasonYe88/aiflow) · 接入指南 [docs/integration.md](docs/integration.md) · MySQL DDL [database/schema.mysql.sql](database/schema.mysql.sql)

## 10 秒装包

```bash
composer require jasonye88/aiflow
```

若 Packagist 尚未收录，用 VCS：

```bash
composer config repositories.aiflow vcs https://github.com/JasonYe88/aiflow.git
composer require jasonye88/aiflow:dev-master
```

```php
<?php
require 'vendor/autoload.php';

use AIFlow\AIKernel;

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . __DIR__ . '/aiflow.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
]);

$cid = $kernel->chat()->startConversation('user_1', 'app:demo');
echo "conversation: {$cid}\n";
```

差异化定位：Dify / One-API 是独立平台；**aiflow 嵌进现有 PHP 项目**，不改宿主架构即可上 AI 能力。

## 环境要求

- PHP >= 7.3（存量生产机友好；新项目建议 7.4+）
- ext-pdo、ext-json、ext-curl；可选：ext-redis、ext-mbstring
- MySQL 5.7+（InnoDB）或 SQLite；建表交由内置迁移自动完成

## 开发与冒烟

```bash
composer install
composer test
php examples/boot.php
php examples/agent.php
php examples/workflow.php
php examples/knowledge.php
php examples/rag.php
php examples/embedding.php
php examples/knowledge_workflow.php   # 检索注入工作流 llm
```

Python RAG 桥（可选）：

```bash
python plugins/python-rag/server.py
AIFLOW_RAG_URL=http://127.0.0.1:8765 php examples/rag.php
```

## 能力入口

```php
$kernel->chat();      // 会话/消息持久化
$kernel->gateway();   // 模型网关（OpenAI 兼容，chat / chatStream SSE）
$kernel->credit();    // 计价 + 钱包两阶段记账
$kernel->guard();     // RBAC 权限（deny 优先、通配）
$kernel->agent();     // function-calling 循环 + 工具权限门
$kernel->workflow();  // 四类节点工作流（run 同步 / dispatch+consume 异步队列）
$kernel->knowledge(); // 知识库架子；knowledge.retriever=http 挂 Python 桥
```

手工灌库：`mysql -u root -p 你的库 < database/schema.mysql.sql`（001–006 终态，21 张业务表）。

## 能力矩阵

| 能力 | 用法入口 | 状态 |
|------|----------|------|
| Kernel 引导 + 配置 | `AIKernel::boot($config)` | ✅ M0 |
| 存储适配（MySQL/SQLite）+ 迁移 | `storage()` / `migrate()` | ✅ M0 |
| 缓存适配（Redis/Array） | `cache()` | ✅ M0 |
| 模型网关 + 对话 + SSE 流式 | `gateway()->chat / chatStream` | ✅ M1 |
| 会话/消息持久化 | `chat()->startConversation / history` | ✅ M1 |
| token 计价（PriceBook） | `credit()->setPriceRule / calcAmount` | ✅ M2 |
| 钱包两阶段记账 + Billing 编排 | `credit()->reserve / settle` | ✅ M2 |
| RBAC 权限（deny 优先/通配） | `guard()->check / guard` | ✅ M2 |
| Agent + 工具调用 | `agent()->run(messages, tools)` | ✅ M3 |
| 工作流（task/decision/llm/parallel） | `workflow()->run` | ✅ M4 |
| 异步工作流（存储表队列） | `workflow()->dispatch / consume` | ✅ M4 |
| 知识库架子 | `knowledge()` | ✅ |
| Python RAG 桥（可选） | `HttpRagRetriever` + `plugins/python-rag` | ✅ M5 |
| Embedding 检索 | `embeddingIndexer()` + `EmbeddingRetriever` | ✅ |
| 混合检索 / 本地文件入库 | `hybrid` + `ingestFile` | ✅ |
| 对话演示页 | `demo/ChatDemoPage::run` | ✅ M2 |

## 文档

| 文档 | 内容 |
|------|------|
| [docs/integration.md](docs/integration.md) | 宿主接入指南 |
| [docs/release.md](docs/release.md) | Packagist 发版检查单 |
| [docs/architecture.md](docs/architecture.md) | 分层架构与目录树 |
| [CHANGELOG.md](CHANGELOG.md) | 版本记录 |
| [plugins/python-rag/](plugins/python-rag/) | RAG 桥协议与启动 |
| [docs/design/](docs/design/2026-10-21-aiflow-llmops-framework-design.md) | 总体设计 |
| [docs/plans/](docs/plans/) | 里程碑实施记录 |

## License

Apache-2.0
