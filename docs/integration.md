# AIFlow 宿主接入指南

> 目标读者：想在**自己已有的 PHP 项目**里用上 AI 能力（对话、计费、权限、Agent、工作流）的开发者。
> 一句话：你的项目不做架构改造，`composer` 装一下、boot 一次，五大能力按需取用。
> 状态：本文五种接入方式中 **path 仓库、VCS 仓库、SPL 引导与手工灌库已于 2026-10-21 实测跑通**；Packagist 直装在 M5 发布后可用。
> 与 Dify 的形态差异见 [architecture.md](architecture.md)「关键设计决策」：Dify 是独立部署平台，AIFlow 是嵌进宿主项目的 Composer 库。

---

## 1. 接入方式（按场景选一种）

### 方式 A：Packagist 直装（M5 发布后，推荐）

```bash
composer require yafex/aiflow
```

### 方式 B：VCS 仓库（✅ 已实测：GitHub 仓库已上线）

宿主项目 `composer.json` 声明仓库地址后 require（默认分支为 `master`，版本号写 `dev-master`）：

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/JasonYe88/aiflow.git" }
    ],
    "require": { "yafex/aiflow": "dev-master" }
}
```

```bash
composer install
```

### 方式 C：path 仓库（本地联调，今日实测通过 ✅）

与 aiflow 同机开发时，宿主直接引用本地路径：

```json
{
    "repositories": [
        { "type": "path", "url": "../aiflow" }
    ],
    "require": { "yafex/aiflow": "dev-master" }
}
```

```bash
composer install
# Installing yafex/aiflow (dev-master): Junctioning from ../aiflow
```

### 方式 D：SPL 引导（无 Composer 环境的老宿主）

FMS 现行方式（`fms/include/aiflowBootstrap.php`）：SPL autoload 直挂 `aiflow/src/`，不依赖 Composer。
首次接触的新宿主不建议再用这种方式，仅作为存量项目的参照实现。

### 方式 E：手工灌库快速安装（DBA / 私有化部署）

不想让框架在 boot 时自动建表（如 DBA 统一管控 DDL、私有化交付），直接执行整库 DDL：

```bash
# MySQL 5.7/8.0：11 张 ai_* 表一次到位（= 001+002+003 迁移终态，InnoDB/utf8mb4）
mysql -u root -p 你的库 < database/schema.mysql.sql
```

文件幂等（IF NOT EXISTS + REPLACE）可重复执行；末尾已把已并入的迁移版本**预登记进 `ai_migration`**，
所以 boot 配 `migration.auto: true` 也安全——零重复执行，将来升级只会增量应用新迁移（实测 2026-10-21：灌库 → boot 零增量 → dispatch/consume 工作流端到端通过）。

---

## 2. 最小可运行示例（15 行入手）

```php
require 'vendor/autoload.php';

use AIFlow\AIKernel;

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . __DIR__ . '/demo.sqlite'], // 生产建议 MySQL + InnoDB
    'cache'     => ['driver' => 'array'],                            // array | redis
    'migration' => ['auto' => true],                                // 自动建 11 张 ai_* 表，幂等
    'models'    => [                                                // OpenAI 兼容渠道（可选）
        'qwen' => ['driver' => 'openai',
                   'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                   'api_key'  => getenv('AIFLOW_QWEN_KEY'),
                   'model'    => 'qwen-plus'],
    ],
]);

// 到这里全部能力就绪（下方按需取用）
$cid = $kernel->chat()->startConversation('user_1', 'app:my_app');           // 对话：会话/消息落库
$fee = $kernel->credit()->calcAmount('qwen-plus', 1000000, 1000000);        // 积分：按 token 计价
$res = $kernel->gateway()->chat('qwen', [['role' => 'user', 'content' => '你好']]); // 模型调用
```

> 可运行版本见 `$env:TEMP\aiflow-host-demo\demo.php`（2026-10-21 path 仓库安装实测通过的宿主示例）。

---

## 3. 配置树速查

| 键 | 说明 | 默认 |
|----|------|------|
| `storage.dsn/user/pass` | PDO DSN；`mysql:` 或 `sqlite:` 均可 | 必填 |
| `cache.driver` | `array`（进程内）或 `redis`；ext-redis 缺失自动降级 array 并告警 | `array` |
| `cache.host/port/db` | redis 连接参数 | 127.0.0.1 / 6379 / 0 |
| `migration.auto` | boot 时自动应用 `migrations/`（幂等，已应用的跳过） | `false` |
| `migration.dir` | 自定义迁移目录 | 包内 `migrations/` |
| `models.{渠道名}` | `driver: openai` + `base_url/api_key/model`；`models.http_timeout` 全局超时（秒） | 无 |
| `access.user_provider` | 已实现 `UserProviderInterface` 的实例，直接注入 | FixedUserProvider |
| `access.roles_map` | `{userId: [role]}` 精确映射 | `[]` |
| `access.default_roles` | 未命中 userId 的兜底角色 | `['user']` |

服务访问器：`$kernel->storage()` / `cache()` / `gateway()` / `chat()` / `credit()` / `guard()` / `agent()` / `workflow()` / `migrate()` / `config()`。
全部惰性单例：boot 只装配存储与缓存，配置错误在首次使用时才暴露。

---

## 4. 当前可用能力（M4 完成，截至 2026-10-21）

| 能力 | 用法 | 状态 |
|------|------|------|
| 模型网关（OpenAI 兼容） | `gateway()->chat('qwen', $messages)` | ✅ 千问真实验收通过 |
| 流式对话（SSE） | `gateway()->chatStream(...)` + `SseEmitter` | ✅ |
| 会话/消息持久化 | `chat()->startConversation / appendMessage / history / listConversations` | ✅ |
| token 计价 | `credit()->setPriceRule / priceOf / calcAmount` | ✅ |
| 钱包两阶段记账 | `credit()->ensureWallet / grant / reserve / settle / release / entries` | ✅ |
| 计费编排（包任意模型调用） | `(new Billing($kernel->credit()))->run($walletId, $model, $refId, $call, $estIn)` | ✅ |
| RBAC 权限 | `guard()->check / guard`（deny 优先、`app:*`/`*:*` 通配） | ✅ |
| Agent + 工具调用（function-calling 循环 + 工具权限门） | `$kernel->agent()` + `ToolInterface`（见 examples/agent.php） | ✅ M3 |
| 工作流（task/decision/llm/parallel 四节点 + 全链路落库） | `$kernel->workflow()->run(new XxxWorkflow(), $input, $refId)`（见 examples/workflow.php） | ✅ M4 |
| 异步工作流（存储表队列） | `workflow()->dispatch(...)` 入队 + `consume()` 消费 | ✅ M4 |
| FMS 宿主接入（计费对话/余额拦截） | CLI ✅ 已验收；页面接入拟改插件方式 | 🔜 待定 |

---

## 5. 部署注意事项（宿主侧）

1. **PHP >= 7.3**：composer 平台校验已放宽到 7.3（PHP 7.3.4 真机全量 173 用例通过，CI 以 7.3/7.4/8.3 三版本矩阵守护）；PHP 7.3 已 EOL，仅为存量生产机（如 FMS）兼容保留，新项目建议 7.4+。
2. **MySQL 引擎**：phpstudy 等 MySQL 默认 MyISAM（无事务），内核 Migrator 会自动对建表语句追加 `ENGINE=InnoDB`；预算并发扣减依赖事务，勿手工改回 MyISAM。
3. **SSE 反代缓冲**：`SseEmitter` 已发 `X-Accel-Buffering: no` 并剥光全部 `ob` 层；若上层还有其他反代（如自建网关），需同样关闭响应缓冲。
4. **密钥管理**：`api_key` 一律走环境变量（`getenv`），不进仓库；FMS 配置中残留的明文回退 key 待挪移。
5. **建库**：MySQL 不允许连接不存在的库；FMS 引导脚本有 `CREATE DATABASE IF NOT EXISTS` 自愈，其他宿主首次接入前请先建库。不想用自动迁移的宿主可走[方式 E 手工灌库](#方式-e手工灌库快速安装dba--私有化部署)（`database/schema.mysql.sql`，11 张表一次到位）。

---

## 6. 验收记录（2026-10-21）

- `composer validate`：通过。
- 全新宿主项目（空目录 + `composer.json` 声明 path 仓库）`composer install`：成功安装 `yafex/aiflow dev-master`（junction 挂载 autoload）。
- 宿主侧 15 行脚本实跑：boot + 自动迁移（10 张表）+ 会话落库 + 计价（qwen-plus 1M+1M = 2800 分）全部通过。
- 结论：**「下载引用到项目即可使用」当前已成立**（path/VCS 方式）；Packagist 直装待 M5 发布。

---

## 7. 下一步（后续路线）

接入后的能力演进按里程碑推进，全量路线见 [design/2026-10-21-aiflow-llmops-framework-design.md](design/2026-10-21-aiflow-llmops-framework-design.md) §10：

- **M4 已完成**：引擎就绪，FMS 侧写好业务节点类（`TaskInterface`）+ `Workflow` 子类即可挂载财务流程
- **M5**：Packagist 发布（届时方式 A 生效）+ Python RAG 桥；FMS 页面/插件化接入待定