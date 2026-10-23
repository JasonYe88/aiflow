# AIFlow —— 可嵌入 PHP LLMOps 内核 · 设计文档

> 日期：2026-10-21
> 状态：设计已确认；M0–M5 代码侧已实施（2026-10-22）。M4 的 FMS 财务流程挂载仍待宿主消费；Packagist 收录需所有者推送 tag
> 对标：Dify（https://github.com/langgenius/dify）——差异化定位见下文

---

## 0. 项目定位（一句话）

**一个 PHP 优先的可嵌入 LLMOps 内核**：权限、积分、对话、Agent、工作流五大能力做成标准件，宿主项目 `composer require` 后通过一个 `Kernel` 引导实例接入；FMS 的财务数字员工场景作为第一个真实消费者验证设计。

### 已确认的核心决策

| 决策点 | 选择 | 说明 |
|--------|------|------|
| 产品形态 | 嵌入式 SDK 优先 | Composer 包，别的项目 require 即用；不做独立平台优先 |
| 语言策略 | PHP 内核保底可用 | Go/Python 全部做成可选增强组件，不装也能跑全核心功能 |
| 工作流定义 | 纯代码定义 | 工作流就是 PHP 类：编译期可校验、可单测、IDE 可跳转 |
| 权限模型 | 扁平 RBAC | 角色 → 应用/工作流/工具 三类资源授权 |
| 积分模型 | Token 钱包 | 模型网关计量，预检额度 + 事后扣账 |
| Agent 深度 | 单 Agent + 工具调用 | 第一期不做多 Agent 协作 |
| PHP 版本 | 7.4+ 兼容 | FMS 是 7.4+，插件必须能挂进自己项目；兼顾国内存量项目 |
| 开源协议 | Apache 2.0 | 含专利授权保护，企业采用顾虑最小 |

### 两条铁律

1. **内核自包含**——不装任何 Go/Python 也能跑全部核心功能
2. **内核不侵入宿主**——用户体系、存储、缓存全部通过适配器接口注入，默认给 MySQL/Redis 适配器

### 明确不做（YAGNI，进远期 backlog）

可视化拖拽编辑器（DSL 仅作导出/交换格式）、多 Agent 协作、多租户、模型训练/微调、知识库托管——等社区需求驱动。

---

## 1. 总体架构

```
宿主项目（FMS 或任何 PHP 项目）
 └─ AIKernel（引导：加载配置、注册用户体系适配器）
     ├─ ModelGateway   模型网关：多厂商接入（DeepSeek/OpenAI/…），token 计量
     ├─ ChatService     对话：会话/消息管理、流式输出
     ├─ AgentRuntime    Agent：工具注册表 + function-calling 循环
     ├─ WorkflowEngine  工作流：PHP 类定义、节点注册、成熟业务流程挂载
     ├─ AccessControl   权限：扁平 RBAC（角色→应用/工作流/工具）
     └─ CreditLedger    积分：额度预检、用量记账、单价倍率、对账
（可选增强）
 ├─ Go sidecar   高并发 SSE 流式网关 / 计量账本（未安装则 PHP 原生实现）
 └─ Python 桥    RAG/算法生态适配（未安装则纯 PHP 实现降级）
```

---

## 2. 模块设计：权限（AccessControl）

**扁平 RBAC，三张表：**

- `ai_role`：角色（内置 `admin` / `developer` / `user`，可自定义）
- `ai_resource`：资源，三种类型 `app`（对话应用）/ `workflow`（工作流）/ `tool`（工具），资源 ID 即宿主注册时的标识
- `ai_role_resource`：角色 ↔ 资源授权（allow/deny，**deny 优先**）

**接入方式**：宿主通过 `UserIdentityProvider` 适配器把自己的用户 ID 映射成框架角色。FMS 中：财务数字员工"用户"挂到某角色，角色授权了「亚马逊营收核算」工作流即可调用；未授权返回 403。

**API 形态**（装饰器风格，所有入口自动拦截）：

```php
$kernel->guard()->check($userId, 'workflow:profit_sku_sync');
```

---

## 3. 模块设计：积分（CreditLedger）

**Token 钱包，三张核心表：**

- `ai_wallet`：钱包（主体可为用户/部门/应用），`balance`（当前余额，分为单位存整数）
- `ai_price_rule`：计价规则，`model` + `input_price_per_m` + `output_price_per_m`（每百万 token 单价）+ 可选倍率
- `ai_ledger_entry`：账本流水（**不可变，只追加**）：`wallet_id`、`model`、`input_tokens`、`output_tokens`、`amount`、`ref_id`（关联调用链）、`created_at`

**两阶段记账（关键设计）：**

1. **预检（Pre-check）**：调用前 `reserve($walletId, $estimateAmount)`——余额不足抛 `InsufficientCreditException`；预扣估算额度并冻结，防单次调用爆量
2. **清算（Settle）**：拿到真实 token 用量后按计价规则算实付，解冻估算额、扣实付、写流水；失败重试按 `ref_id` 幂等（防重复扣账）

余额不足、并发扣减冲突由数据库乐观锁 / `FOR UPDATE` 保证正确性。流水表按月分表预留。

---

## 4. 模块设计：对话（ChatService）+ Agent（AgentRuntime）

### ChatService

- 两张表：`ai_conversation`（会话）+ `ai_message`（消息：role、内容、token 用量、耗时）
- 统一接口 `ModelProvider`：`chat(array $messages, array $options): ModelResponse`
- 第一期实现 `OpenAICompatibleProvider`——DeepSeek、通义、Kimi 等 99% 国产/开源模型均兼容 OpenAI 协议，一个类吃下大半市场
- **流式输出**：`StreamTransport` 接口，默认 `FpmSseTransport`（SSE 逐块 flush）；宿主有 Swoole 时自动切换 Swoole 实现

### AgentRuntime（单 Agent + 工具调用）

- 工具 = PHP 类实现 `ToolInterface`：`name()`、`description()`、`schema()`（JSON Schema 参数声明）、`execute(array $args)`
- 运行时把注册工具的 schema 喂给 LLM，解析 function call → 权限检查 → 执行 → 结果回填 LLM → 循环，直到给出最终答案（最大轮数可配，防死循环）
- 工具可直接包工作流——"成熟业务流程"既能被 AI 自主决策调用，也能被宿主代码直接调用

---

## 5. 模块设计：工作流引擎（WorkflowEngine，核心）

**工作流就是 PHP 类：**

```php
class ProfitSkuSyncWorkflow extends Workflow
{
    public function nodes(): array
    {
        return [
            Node::task(ExtractParamsNode::class),
            Node::task(PermissionCheckNode::class),
            Node::decision('need_retry', [true => 'retry', false => 'next']),
            Node::llm('summarize', 'deepseek-chat'),                    // 内置 LLM 节点
            Node::parallel([ReportNode::class, NotifyNode::class]),      // 并行分支
        ];
    }
}
```

- **四种内置节点类型**：`task`（业务 PHP 类）/ `decision`（条件分支）/ `llm`（模型调用）/ `parallel`（并行）；开发者自己写节点，引擎负责调度、超时、重试、失败回滚钩子
- **执行记录（可观测性内建）**：`ai_workflow_run` 记录每次运行，每个节点输入输出落 `ai_workflow_run_log`——与 FMS 的 MQ `_batchId` 全链路追踪同款思路
- **同步/异步**：`->run()` 同步；`->dispatch()` 投队列（默认 Redis 队列适配器），适配长任务场景

---

## 6. 包结构

Monorepo，一个 Composer 主包（独立仓库，不混在 FMS 里）：

```
aiflow/                        ← 开源项目
├─ src/
│  ├─ Kernel.php               ← 唯一入口：AIKernel::boot($config)
│  ├─ Contract/                ← 全部适配器接口（Storage/UserProvider/Queue/StreamTransport…）
│  ├─ Gateway/                 ← 模型网关 + Provider
│  ├─ Chat/                    ← 会话/消息/流式
│  ├─ Agent/                   ← Agent 运行时 + ToolInterface
│  ├─ Workflow/                ← 工作流引擎 + 节点
│  ├─ Access/                  ← RBAC
│  ├─ Credit/                  ← 钱包/账本
│  ├─ Adapter/                 ← 默认实现（MySQL/Redis/FPM-SSE）
│  └─ Support/                 ← 工具函数
├─ migrations/                 ← 建表 SQL（ai_* 前缀，8 张核心表）
├─ tests/                      ← PHPUnit
├─ examples/                   ← 各场景最小可运行示例
└─ plugins/                    ← 可选增强
   ├─ go-gateway/              ← Go SSE 网关（编译后一个二进制）
   └─ python-rag/              ← Python RAG 桥（HTTP 协议交互）
```

**版本策略**：主包 PHP 7.4+；Go/Python 插件与主包版本解耦，通过 HTTP 协议对接而非进程内调用。

---

## 7. 错误处理

统一异常树，根为 `AIFlowException`，下分：

- `PermissionDeniedException`
- `InsufficientCreditException`
- `ModelCallException`（含厂商原始错误）
- `WorkflowRuntimeException`
- `NodeRetryExhaustedException`

所有异常带 `context`（调用链 ID），宿主按需捕获或放行到自己的错误日志体系（可对接 FMS 的错误 MD 日志 papi）。

---

## 8. 测试策略

- **单元测试**：引擎调度、RBAC 判定、积分幂等清算用 SQLite 内存库跑，`composer test` 一条命令
- **Contract 测试**：每个 `Contract/` 接口配一套契约测试套件——第三方想换存储实现？跑契约测试通过即可
- **示例即冒烟**：`examples/` 下每个示例可 `php examples/xx.php` 直跑，CI 全量执行防退化

---

## 9. 开源策略

- **命名**：`aiflow`（占位名）；Packagist 命名空间如 `jasonye88/aiflow`
- **协议**：Apache 2.0
- **README 即门面**：三段式——10 秒装包示例（`composer require` + 15 行代码跑通第一个 Agent）→ 架构图 → 能力矩阵
- **差异化定位（对外叙事）**：Dify/One-API 都是"独立平台"，需要用户迁就其部署；`aiflow` 是"嵌进现有 PHP 项目的 LLMOps 内核"——存量项目不改架构即可上 AI 能力，属社区空白
- **FMS 双重角色**：既是第一个真实用户（财务数字员工场景），又是 showcase——`docs/` 里的 FMS 接入案例即最有说服力的文档

---

## 10. 里程碑路线图

按可发布切片切分，每期都能用：

| 期 | 内容 | 验收标准 | 状态 |
|----|------|----------|------|
| **M0** | Kernel 骨架 + Contract 接口 + MySQL/Redis 适配器 + 建表迁移 | `AIKernel::boot()` 可跑通，CI 绿 | ✅ |
| **M1** | 模型网关（OpenAI 兼容协议）+ ChatService + 流式 | FMS 里跑通一个真实模型对话页面 | ✅ 千问非流式 + 流式 + 落库 |
| **M2** | CreditLedger 两阶段记账 + AccessControl RBAC | 对话自动计费、按角色限流可用 | ✅ 2026-10-21：Billing 预检/清算 + RBAC；独立 demo 与 fms 薄挂载实测扣费、402、403 |
| **M3** | AgentRuntime + 工具注册 | 一个 Agent 用工具完成真实查询任务 | ✅ 引擎与单测；真实模型工具调用待宿主场景 |
| **M4** | WorkflowEngine + 4 种内置节点 + 运行追踪 | **FMS 一条成熟财务流程以工作流挂载跑通（核心验收）** | ⚠️ 引擎完成；FMS 财务流程挂载未做 |
| **M5** | 开源发布：README/示例/文档站 + Python RAG 桥 | Packagist 就绪 + 桥可跑；收录待推 tag | ✅ 代码侧 |

---

## 11. 核心数据表汇总（ai_* 前缀，共 10 张）

| 表 | 所属模块 |
|----|----------|
| `ai_role` / `ai_resource` / `ai_role_resource` | AccessControl |
| `ai_wallet` / `ai_price_rule` / `ai_ledger_entry` | CreditLedger |
| `ai_conversation` / `ai_message` | ChatService |
| `ai_workflow_run` / `ai_workflow_run_log` | WorkflowEngine |