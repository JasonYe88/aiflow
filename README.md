# AIFlow

可嵌入 PHP 项目的 LLMOps 内核：权限、积分、对话、Agent、工作流。

> 状态：M4 完成——五大能力齐备，全量 **173 用例 / 1642 断言绿**（PHP 7.3 / 7.4 / 8.x 三版本 CI 矩阵守护）。
> 仓库：[github.com/JasonYe88/aiflow](https://github.com/JasonYe88/aiflow) · 详细接入指南见 [docs/integration.md](docs/integration.md) · 申请即用的 MySQL 整库 DDL 见 [database/schema.mysql.sql](database/schema.mysql.sql)。

## 环境要求

- PHP >= 7.3（存量生产机友好；新项目建议 7.4+）
- ext-pdo、ext-json、ext-curl；可选：ext-redis
- MySQL 5.7+（InnoDB）或 SQLite；建表交由内置迁移自动完成

## 开发

```bash
composer install
composer test        # 全量 PHPUnit（含 MySQL 契约轮，未配置 AIFLOW_TEST_MYSQL_DSN 自动跳过）
```

离线冒烟示例：`php examples/boot.php` · `php examples/agent.php` · `php examples/workflow.php`

## 快速开始

### 安装（宿主项目内）

```bash
# 方式 A：Packagist 直装（M5 发布后，届时推荐）
composer require jasonye88/aiflow

# 方式 B：VCS 仓库（✅ 现在即可用——本仓库已上线 GitHub）
# 宿主 composer.json:
#   { "repositories": [{"type": "vcs", "url": "https://github.com/JasonYe88/aiflow.git"}],
#     "require": {"jasonye88/aiflow": "dev-master"} }
composer install

# 方式 C：path 仓库（同机本地联调）
#   { "repositories": [{"type": "path", "url": "../aiflow"}],
#     "require": {"jasonye88/aiflow": "dev-master"} }

# 方式 D：SPL 引导（无 Composer 的老项目；fms 现行方式）
# 参照 docs/integration.md 方式 D 的 fms/include/aiflowBootstrap.php 实例
```

### boot 一次，全部能力就绪

```php
require 'vendor/autoload.php';

use AIFlow\AIKernel;

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'mysql:host=127.0.0.1;dbname=fms;charset=utf8mb4', 'user' => 'root', 'pass' => ''],
    'cache'     => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379],
    'migration' => ['auto' => true],   // 自动建 11 张 ai_* 表（幂等可重复跑）
]);

// 按需取用：
$kernel->chat();      // 会话/消息持久化
$kernel->gateway();   // 模型网关（OpenAI 兼容，chat / chatStream SSE）
$kernel->credit();    // 计价 + 钱包两阶段记账
$kernel->guard();     // RBAC 权限（deny 优先、通配）
$kernel->agent();     // function-calling 循环 + 工具权限门
$kernel->workflow();  // 四类节点工作流（run 同步 / dispatch+consume 异步队列）
```

### 不想让框架自动建表？（DBA 管控 / 快速安装）

```bash
mysql -u root -p 你的库 < database/schema.mysql.sql   # 11 张表终态一次到位，可重复执行
```

## 能力矩阵

| 能力 | 用法入口 | 状态 |
|------|----------|------|
| Kernel 引导 + 配置 | `AIKernel::boot($config)` | ✅ M0 |
| 存储适配（MySQL/SQLite）+ 迁移 | `storage()` / `migrate()` | ✅ M0 |
| 缓存适配（Redis/Array） | `cache()` | ✅ M0 |
| 模型网关 + 对话 + SSE 流式 | `gateway()->chat / chatStream` | ✅ M1（千问真实验收）|
| 会话/消息持久化 | `chat()->startConversation / history` | ✅ M1 |
| token 计价（PriceBook） | `credit()->setPriceRule / calcAmount` | ✅ M2 |
| 钱包两阶段记账 + Billing 编排 | `credit()->reserve / settle` | ✅ M2 |
| RBAC 权限（deny 优先/通配） | `guard()->check / guard` | ✅ M2 |
| Agent + 工具调用 | `agent()->run(messages, tools)` | ✅ M3 |
| 工作流（task/decision/llm/parallel） | `workflow()->run` | ✅ M4 |
| 异步工作流（存储表队列） | `workflow()->dispatch / consume` | ✅ M4 |
| FMS 宿主接入（计费对话/余额拦截） | CLI 已验收；页面接入拟插件化 | ✅ 后端链路 |

## 文档

| 文档 | 内容 |
|------|------|
| [docs/integration.md](docs/integration.md) | 宿主接入指南：五种安装方式、15 行示例、配置速查、部署注意 |
| [docs/architecture.md](docs/architecture.md) | 代码结构：分层架构、目录树、数据模型 |
| [database/schema.mysql.sql](database/schema.mysql.sql) | 整库 MySQL DDL（快速安装） |
| [docs/design/](docs/design/2026-10-21-aiflow-llmops-framework-design.md) | 总体设计与里程碑路线 |
| [docs/plans/](docs/plans/) | M0–M4 各里程碑实施记录 |
| [docs/progress/todo.md](docs/progress/todo.md) | 任务全景快照 |

## License

Apache-2.0