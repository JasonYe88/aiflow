# AIFlow 宿主接入指南

> 目标读者：想在**自己已有的 PHP 项目**里用上 AI 能力（对话、计费、权限、Agent、工作流）的开发者。
> 一句话：你的项目不做架构改造，`composer` 装一下、boot 一次，五大能力按需取用。
> 状态：五种接入方式已就绪；**M5** 起 Packagist 包名 `jasonye88/aiflow`（需仓库所有者推送 tag 并在 packagist.org 提交，见 [release.md](release.md)）。
> 与 Dify 的形态差异见 [architecture.md](architecture.md)「关键设计决策」：Dify 是独立部署平台，AIFlow 是嵌进宿主项目的 Composer 库。

---

## 1. 接入方式（按场景选一种）

### 方式 A：Packagist 直装（推荐）

```bash
composer require jasonye88/aiflow
# 或锁定小版本：composer require jasonye88/aiflow:^0.1
```

> 若尚未出现在 Packagist，先走方式 B，并按 [release.md](release.md) 完成收录。

### 方式 B：VCS 仓库（✅ 已实测：GitHub 仓库已上线）

宿主项目 `composer.json` 声明仓库地址后 require（默认分支为 `master`，版本号写 `dev-master`）：

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/JasonYe88/aiflow.git" }
    ],
    "require": { "jasonye88/aiflow": "dev-master" }
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
    "require": { "jasonye88/aiflow": "dev-master" }
}
```

```bash
composer install
# Installing jasonye88/aiflow (dev-master): Junctioning from ../aiflow
```

### 方式 D：SPL 引导（无 Composer 环境的老宿主）

FMS 现行方式（`fms/include/aiflowBootstrap.php`）：SPL autoload 直挂 `aiflow/src/`，不依赖 Composer。
首次接触的新宿主不建议再用这种方式，仅作为存量项目的参照实现。

### 方式 E：手工灌库快速安装（DBA / 私有化部署）

不想让框架在 boot 时自动建表（如 DBA 统一管控 DDL、私有化交付），直接执行整库 DDL：

```bash
# MySQL 5.7/8.0：21 张 ai_* 业务表一次到位（= 001–006 迁移终态，含知识库架子，InnoDB/utf8mb4）
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
    'migration' => ['auto' => true],                                // 自动建 001–006 终态表，幂等
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
| `knowledge.retriever` | `simple` / `http` / `embedding` / `hybrid` | `simple` |
| `knowledge.hybrid_keyword_weight` | hybrid 时关键词权重 | `0.4` |
| `knowledge.hybrid_embed_weight` | hybrid 时向量权重 | `0.6` |
| `knowledge.rag_url` | HTTP 桥 Base URL | `http://127.0.0.1:8765` |
| `knowledge.rag_token` | 可选 Bearer Token | 空 |
| `knowledge.embed_channel` | embeddings 渠道名（须在 `models` 注册且支持 /embeddings） | 空 |
| `knowledge.embed_batch` | 建索引批大小 | `16` |
| `knowledge.embed_min_score` | 余弦下限 | `0` |
| `knowledge.retriever_instance` | 直接注入 `RetrieverInterface` | 无 |

服务访问器：`$kernel->storage()` / `cache()` / `gateway()` / `chat()` / `credit()` / `guard()` / `agent()` / `workflow()` / `graph()` / `knowledge()` / `migrate()` / `config()`。
全部惰性单例：boot 只装配存储与缓存，配置错误在首次使用时才暴露。

---

## 4. 当前可用能力（截至 2026-10-22）

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
| 知识库（建库/文本·文件入库/绑定/检索） | `$kernel->knowledge()` + CatalogConsole「知识库」页（列表→文档/召回/设置） | ✅ P0；对话只消费绑定 |
| Python RAG 桥（可选 TF-IDF / 可换向量后端） | `knowledge.retriever=http` + `plugins/python-rag`（见 examples/rag.php） | ✅ M5 |
| FMS 宿主接入（计费对话/余额拦截） | CLI ✅ 已验收；页面接入拟改插件方式 | 🔜 待定 |

### 4.1 知识库架子（最小闭环）

不做真实 Embedding / 上传 UI；默认 `SimpleKeywordRetriever`，可换 `RetrieverInterface` 实现。

```php
$kb = $kernel->knowledge();
$baseId = $kb->createBase('demo.faq', '演示FAQ', 'u1', '仅架子');
$docId  = $kb->ingestText($baseId, '演示文档', "第一段\n\n第二段");
$kb->setDocumentEnabled($docId, true);
$kb->bind('app:demo', $baseId, 'prod', 'u1');
$hits = $kb->retrieve('app:demo', '用户问题', ['top_k' => 5]);
// Agent：$kernel->agent()->register(new \AIFlow\Knowledge\KnowledgeRetrieveTool($kb, 'app:demo'));
```

冒烟：`php examples/knowledge.php`。制度与运营边界见 [knowledge/ops-policy.md](knowledge/ops-policy.md)；架子 Todo 见 [knowledge/skeleton-todo.md](knowledge/skeleton-todo.md)。

### 4.2 Python RAG 桥（M5 可选）

```bash
python plugins/python-rag/server.py
```

```php
$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . __DIR__ . '/demo.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
    'knowledge' => [
        'retriever' => 'http',
        'rag_url'   => 'http://127.0.0.1:8765',
        // 'rag_token' => 'secret',
    ],
]);
$kb = $kernel->knowledge();
// … ingestText + setDocumentEnabled …
$retriever = $kb->retriever(); // HttpRagRetriever
if ($retriever instanceof \AIFlow\Knowledge\HttpRagRetriever) {
    $retriever->client()->index($baseId, $kb->exportChunks($baseId), true);
}
$hits = $kb->retrieveBase($baseId, '用户问题', ['top_k' => 5]);
```

协议：[../plugins/python-rag/protocol.md](../plugins/python-rag/protocol.md)。未启桥时默认 `simple` 关键词检索，主包无 Python 依赖。

### 4.3 Embedding 检索（OpenAI 兼容 /embeddings）

```php
$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . __DIR__ . '/demo.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
    'models'    => [
        'emb' => [
            'driver'   => 'openai',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'api_key'  => getenv('AIFLOW_QWEN_KEY'),
            'model'    => 'text-embedding-v3',
        ],
    ],
    'knowledge' => [
        'retriever'     => 'embedding',
        'embed_channel' => 'emb',
    ],
]);
$kb = $kernel->knowledge();
// … ingestText + setDocumentEnabled …
$kernel->embeddingIndexer()->indexBase($baseId); // 写入 embedding_json
$hits = $kb->retrieveBase($baseId, '用户问题', ['top_k' => 5]);
```

冒烟：`php examples/embedding.php`（内置假 HTTP）。迁移 `007_knowledge_embedding.sql` 为 chunk 增加 `embedding_json`。

### 4.4 工作流注入检索上下文

```php
use AIFlow\Knowledge\RetrieveKnowledgeTask;
use AIFlow\Knowledge\KnowledgeContext;
use AIFlow\Workflow\Node;

RetrieveKnowledgeTask::useKnowledge($kernel->knowledge());

// 节点：先 rag 再 llm；llm prompt 可读 $ctx['knowledge_context']
Node::task('rag', RetrieveKnowledgeTask::class);
Node::llm('answer', 'qwen', function (array $ctx) {
    return ($ctx['knowledge_context'] ?? '') . "\n\n问：" . $ctx['query'];
});

// Agent 侧也可用：
$messages = KnowledgeContext::prependToMessages($messages, $hits);
```

冒烟：`php examples/knowledge_workflow.php`。

### 4.5 文件入库与混合检索

```php
$docId = $kb->ingestFile($baseId, __DIR__ . '/faq.txt'); // 纯文本；source_type=file
$kb->updateBase($baseId, ['name' => '客服FAQ', 'description' => '…']);

// 关键词 + 向量加权（须先 embeddingIndexer()->indexBase）
$kernel = AIKernel::boot([
    // …
    'knowledge' => [
        'retriever' => 'hybrid',
        'embed_channel' => 'emb',
        'hybrid_keyword_weight' => 0.4,
        'hybrid_embed_weight' => 0.6,
    ],
]);
```

---

## 5. 部署注意事项（宿主侧）

1. **PHP >= 7.3**：composer 平台校验已放宽到 7.3（PHP 7.3.4 真机全量 173 用例通过，CI 以 7.3/7.4/8.3 三版本矩阵守护）；PHP 7.3 已 EOL，仅为存量生产机（如 FMS）兼容保留，新项目建议 7.4+。
2. **MySQL 引擎**：phpstudy 等 MySQL 默认 MyISAM（无事务），内核 Migrator 会自动对建表语句追加 `ENGINE=InnoDB`；预算并发扣减依赖事务，勿手工改回 MyISAM。
3. **SSE 反代缓冲**：`SseEmitter` 已发 `X-Accel-Buffering: no` 并剥光全部 `ob` 层；若上层还有其他反代（如自建网关），需同样关闭响应缓冲。
4. **密钥管理**：`api_key` 一律走环境变量（`getenv`），不进仓库；FMS 配置中残留的明文回退 key 待挪移。
5. **建库**：MySQL 不允许连接不存在的库；FMS 引导脚本有 `CREATE DATABASE IF NOT EXISTS` 自愈，其他宿主首次接入前请先建库。不想用自动迁移的宿主可走[方式 E 手工灌库](#方式-e手工灌库快速安装dba--私有化部署)（`database/schema.mysql.sql`，21 张业务表一次到位）。

---

## 6. 验收记录（2026-10-21）

- `composer validate`：通过。
- 全新宿主项目（空目录 + `composer.json` 声明 path 仓库）`composer install`：成功安装 `jasonye88/aiflow dev-master`（junction 挂载 autoload）。
- 宿主侧 15 行脚本实跑：boot + 自动迁移（10 张表）+ 会话落库 + 计价（qwen-plus 1M+1M = 2800 分）全部通过。
- 结论：**「下载引用到项目即可使用」已成立**（path/VCS）；Packagist 直装在推送 `v0.1.0` 并完成 [release.md](release.md) 提交后生效。

---

## 7. 下一步（后续路线）

接入后的能力演进按里程碑推进，全量路线见 [design/2026-10-21-aiflow-llmops-framework-design.md](design/2026-10-21-aiflow-llmops-framework-design.md) §10：

- **M2–M4 已完成**：计费 / RBAC / Agent / 工作流
- **M5 已完成（代码侧）**：Packagist 就绪 + Python RAG 桥；**你方**推送 tag 并在 packagist.org 点 Submit
- **之后**：真实 Embedding 后端、FMS 插件化消费、Gaia 内容运营（另排期）