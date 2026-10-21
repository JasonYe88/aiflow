# AIFlow 代码结构

> AIFlow 是可嵌入 PHP 项目的 LLMOps 内核：权限、积分、对话、Agent、工作流。
> 对标 [Dify](https://github.com/langgenius/dify) 的能力域，但形态不同——**它是一个 Composer 库，不是一个独立部署的平台**。宿主 PHP 项目 `composer` 安装后 boot 一次即可使用全部能力（安装方式与最小示例见 [integration.md](integration.md)）。

## 总体分层

```
                    ┌──────────────────────────────┐
                    │        AIKernel（门面）        │  唯一入口：boot() 装配一切
                    └──────────────────────────────┘
                          │            │           │
             ┌────────────┘            │           └────────────┐
             ▼                         ▼                        ▼
      ┌────────────┐          ┌──────────────┐          ┌─────────────┐
      │ Chat 会话   │          │ Credit 积分   │          │ Gateway 网关 │   服务层（业务语义）
      │ ChatService │          │ CreditService │          │ModelGateway │
      └─────┬──────┘          └──────┬───────┘          └──────┬──────┘
            │                        │                         │
            ▼                        ▼                         ▼
      ┌──────────────────────────────────────────────────────────────┐
      │                      Contract（接口契约层）                    │  面向接口编程，
      │  StorageInterface / CacheInterface / HttpClientInterface    │  所有外部依赖可替换
      │  ModelProviderInterface / UserProviderInterface             │
      └──────────────────────────────────────────────────────────────┘
            ▲                         ▲                         ▲
            │                         │                         │
      ┌─────┴──────┐          ┌──────┴───────┐          ┌──────┴───────┐
      │ Adapter 适配 │          │ Adapter 适配   │          │ Gateway 驱动  │   实现层
      │ PdoStorage  │          │ ArrayCache    │          │OpenAiCompat- │
      │             │          │ RedisCache    │          │ ibleProvider │
      └─────────────┘          └──────────────┘          └──────────────┘

      Support（横切支撑）：Config / Id / Migrator / ModelResult / HttpResponse / SseEmitter
      Exception（异常树）：AIFlowException（marker 接口）为根
```

依赖方向严格单向向下：`AIKernel → 服务 → Contract ← Adapter`，适配器实现契约，服务只依赖契约，测试用 Fake 替换实现（见 `tests/Support/FakeHttpClient.php`）。

## 目录树与文件职责

```
src/
├── AIKernel.php                     # 内核门面：boot(config) 装配存储/缓存/网关/服务，惰性单例
├── Contract/                        # 接口契约（全部对外可替换点）
│   ├── StorageInterface.php         #   存储契约：execute/query/transaction/driverName
│   ├── CacheInterface.php           #   缓存契约
│   ├── HttpClientInterface.php      #   HTTP 契约：request / requestStream（SSE 用）
│   ├── ModelProviderInterface.php   #   模型提供方契约：chat / chatStream
│   └── UserProviderInterface.php    #   宿主用户体系适配：把宿主用户映射为框架角色（M2 消费）
├── Adapter/                          # 契约的内置实现
│   ├── PdoStorage.php                #   MySQL/SQLite 通吃（PDO），事务支持
│   ├── ArrayCache.php                #   进程内缓存（开发/降级用）
│   ├── RedisCache.php                #   ext-redis 缓存
│   └── CurlHttpClient.php            #   ext-curl HTTP 客户端（含流式 WRITEFUNCTION 回调）
├── Gateway/                          # 模型接入层
│   ├── ModelGateway.php              #   渠道名 → Provider 注册表，业务统一入口
│   └── OpenAiCompatibleProvider.php  #   OpenAI 兼容协议：DeepSeek/通义/Kimi 等
├── Chat/                             # 对话域
│   ├── ChatService.php               #   会话/消息持久化（ai_conversation / ai_message）
│   └── SseEmitter.php                #   SSE 流式输出器（FPM 多层缓冲感知，CLI 自动降级）
├── Agent/                            # Agent 域（M3 ✅）
│   ├── AgentRuntime.php              #   function-calling 循环：注册工具 schema 喂模 → 解析
│   │                                 #   tool_calls →（可选权限门）执行 → 回填 → 循环至终答
│   ├── ToolInterface.php             #   工具契约：name/description/schema/execute
│   └── AgentResult.php               #   运行结果（与 ModelResult 鸽子兼容可入 Billing），
│                                     #   含 rounds 与 steps[] 工具轨迹
├── Workflow/                         # 工作流域（M4 ✅）
│   ├── WorkflowEngine.php            #   引擎：编译校验 → 调度 4 类节点 → 全链路落库；
│   │                                 #   同步 run() + 异步 dispatch()/consume()（存储表队列）
│   ├── Workflow.php                  #   基类：宿主业务流程声明为纯 PHP 类（nodes()）
│   ├── Node.php                      #   节点工厂：task（可重试）/ decision（字段值跳转）
│   │                                 #   / llm（prompt 构造器→gateway）/ parallel（分支隔离聚合）
│   ├── TaskInterface.php             #   task 节点业务类契约：execute($context): 增量产��
│   └── WorkflowRunResult.php         #   运行结果值对象（明细走 engine->logs()）
├── Credit/                           # 积分域（M2 ✅）
│   ├── CreditService.php             #   计价 PriceBook + 钱包（建/查/充值）+ 两阶段记账
│   │                                 #   （reserve 预检冻结 → settle 幂等清算 / release 解冻）
│   └── Billing.php                   #   两阶段计费编排：包住任意模型调用（幂等链路 refId 贯穿）
├── Access/                           # 权限域（M2 ✅）
│   ├── AccessService.php             #   扁平 RBAC：ensureRole/ensureResource/grant/revoke
│   │                                 #   + check/guard（deny 优先、`app:*`/`*:*` 通配）
│   └── FixedUserProvider.php         #   默认用户→角色映射（roles_map 精确 + default_roles 兜底）
├── Support/                          # 横切支撑
│   ├── Config.php                    #   点路径只读配置（get('cache.driver')）
│   ├── Id.php                        #   32 位 hex 单调主键：时间戳前缀+随机段+毫秒内序号
│   ├── Migrator.php                  #   可移植 SQL 迁移器（MySQL/SQLite 双兼容，含 InnoDB 修正）
│   ├── ModelResult.php               #   模型调用结果值对象（content/tokens/耗时/raw）
│   └── HttpResponse.php             #   HTTP 响应值对象
├── Exception/                        # 异常树
│   ├── AIFlowException.php           #   marker 接口（根）：catch 一个类型拿到全部框架异常
│   ├── ModelCallException.php        #   模型调用失败（携带厂商原始状态码+响应体）
│   ├── HttpException.php             #   网络层异常（连接失败/超时）
│   ├── InsufficientCreditException.php  # 余额不足（need/have，供引导充值）
│   ├── PermissionDeniedException.php    # 权限拒绝（userId/resource，供审计）
│   ├── AgentRoundExhaustedException.php # Agent 循环达 max_rounds（rounds/toolCalls）
│   ├── WorkflowDefinitionException.php  # 工作流定义非法（编译期 fail fast）
│   └── NodeRetryExhaustedException.php  # task 节点重试耗尽（nodeKey/retries）
migrations/                           # 版本化 SQL 迁移（按文件名序执行，记入 ai_migration）
├── 001_init.sql                      #   10 张核心 ai_* 表
├── 002_conversation_activity_seq.sql #   会话活跃序列列（毫秒级排序修复）
└── 003_workflow_queue.sql            #   工作流异步队列（存储表实现，M4）
examples/                            # 可运行示例
├── boot.php                          #   boot + 迁移冒烟（php examples/boot.php）
├── agent.php                         #   Agent function-calling 冒烟（无 key 走脚本桩）
├── workflow.php                      #   工作流四类节点 + 异步队列冒烟（无 key 走脚本桩）
└── httpEcho.php                      #   本地 echo 服务（供 HTTP live 测试）
tests/                                # PHPUnit 9.6，契约测试驱动
├── Contract/StorageContractTest.php  #   存储契约套件（SQLite/MySQL 两个实现共用）
├── Adapter/                          #   适配器实现测试（MySQL/Redis 为 live，无服务则 skip）
├── Gateway/                          #   网关测试（FakeHttpClient，不打真 API）
├── Chat/                             #   ChatService + SseEmitter
├── Credit/                           #   CreditService（先红后绿的 TDD）
├── Support/                          #   Id/Migrator + FakeHttpClient
└── KernelBootTest.php / SmokeTest.php
```

## 数据模型（migrations/，11 张 ai_* 表）

| 表 | 域 | 用途 | 里程碑 |
|---|---|---|---|
| `ai_role` | 权限 | 角色定义（code 唯一） | M2 ✅ 已消费 |
| `ai_resource` | 权限 | 资源登记（app/workflow/tool） | M2 ✅ 已消费 |
| `ai_role_resource` | 权限 | 角色-资源授权（allow/deny，deny 优先） | M2 ✅ 已消费 |
| `ai_wallet` | 积分 | 钱包（user/dept/app 三种 owner，余额+冻结，单位分） | M2 ✅ 已消费 |
| `ai_price_rule` | 积分 | 模型计价（每百万 token 单价 + 倍率） | M2 ✅ 已消费 |
| `ai_ledger_entry` | 积分 | 计量流水（grant/reserve/settle/refund，ref_id 幂等） | M2 ✅ 已消费 |
| `ai_conversation` | 对话 | 会话（updated_seq 单调活跃序列） | M1 ✅ 已消费 |
| `ai_message` | 对话 | 消息（role/content/tokens/耗时） | M1 ✅ 已消费 |
| `ai_workflow_run` | 工作流 | 运行实例（状态+上下文 JSON，ref_id 业务锚点） | M4 ✅ 已消费 |
| `ai_workflow_run_log` | 工作流 | 节点级执行日志（input/output 快照 + skipped 日记） | M4 ✅ 已消费 |
| `ai_workflow_queue` | 工作流 | 异步队列（queued→running→done/failed，原子抢占） | M4 ✅ 已消费 |
| `ai_migration` | 框架 | 迁移版本记录（Migrator 自建） | M0 ✅ |

约定：主键为 `Id::gen()` 32 位 hex 字符串（无 AUTO_INCREMENT，保证 SQL 在 MySQL/SQLite 间可移植）；金额单位为分；token 数量为 BIGINT。

## 关键设计决策

1. **库而非平台**：Dify 是独立部署的完整平台（Python/FastAPI + Next.js + Docker），AIFlow 是嵌进宿主 PHP 项目的内核库。宿主保留自己的用户体系、路由、前端，只把 AI 能力外包给 AIFlow。
2. **契约先行**：每个外部依赖（存储/缓存/HTTP/模型厂商/宿主用户）都有接口，内置实现只是默认值。测试全部用 Fake 打桩，不打真网真库。
3. **可移植 SQL**：迁移与业务 SQL 均为 MySQL/SQLite 双兼容；不用 AUTO_INCREMENT/ENGINE/内联 KEY，二级索引独立 `CREATE INDEX`；Migrator 对 MySQL 自动补 `ENGINE=InnoDB`（宿主 MyISAM 默认会破坏事务）。
4. **单调 Id**：`[12位毫秒时间戳hex][14位随机hex][6位毫秒内序号hex]`，字符串序 = 生成序，`ORDER BY id ASC` 即业务时序，免掉"同秒排序抖动"问题（消息历史、会话活跃度都依赖它）。
5. **异常树**：marker 接口 `AIFlowException` 为根，宿主 catch 一个类型即可覆盖全部框架异常；具体异常携带结构化上下文（`need/have`、`userId/resource`、厂商原始响应）。
6. **SSE 的 FPM 生存术**：`SseEmitter::start()` 剥掉全部 `ob` 层 + `X-Accel-Buffering: no` 提示 nginx 不缓冲；流式解析跨 chunk 缓冲按行切割，坏行容错不炸流。
7. **惰性单例**：gateway/chat/credit 均惰性创建，boot 只装配存储/缓存，配置错在首次使用时才暴露，缩短启动路径。

## 配置树（AIKernel::boot）

```php
$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'mysql:host=127.0.0.1;dbname=fms;charset=utf8mb4', 'user' => 'root', 'pass' => ''],
    'cache'     => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379, 'db' => 0],  // array|redis
    'migration' => ['auto' => true],       // boot 即迁移；dir 缺省为包内 migrations/
    'models'    => [                       // 渠道名 → 提供方（driver=openai）
        'deepseek' => ['driver' => 'openai', 'base_url' => 'https://api.deepseek.com/v1',
                        'api_key' => 'sk-...', 'model' => 'deepseek-chat'],
        'qwen'     => ['driver' => 'openai', 'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                        'api_key' => 'sk-...', 'model' => 'qwen-plus',
                        'http_timeout' => 120],
    ],
]);
```

服务访问器：`$kernel->storage()` / `cache()` / `gateway()` / `chat()` / `credit()` / `guard()` / `agent()` / `migrate()` / `config()`。