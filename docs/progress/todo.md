# aiflow 任务清单（快照）

> 更新于：2026-10-21 14:40
> 说明：本文件是会话任务清单的定期快照；最新进度以对话为准。提交一律由仓库所有者手动执行。

## M0：内核骨架 ✅ 完成（12 commits）

- Kernel boot + Config 点路径 ✅
- Contract 接口（Storage/Cache/UserProvider/HttpClient）✅
- PdoStorage（MySQL/SQLite 双兼容）+ InnoDB 强制 ✅
- Id 生成器（单调递增）+ ArrayCache/RedisCache ✅
- Migrator + `001_init.sql`（10 张 ai_* 表）+ CI ✅

## M1：模型网关 + 对话 + 流式 ✅ 完成

- Task 1+2 HTTP 层（契约 + curl）+ 模型层契约与值类 ✅
- Task 3 FakeHttpClient + Provider 非流式（TDD）✅
- Task 4 Provider 流式 SSE 解析（TDD）✅
- Task 5 ModelGateway + Kernel 装配（TDD）✅
- Task 6 ChatService（TDD；排序确定性修复：单调 Id + updated_seq）✅
- Task 7 SseEmitter + chat() 访问器（CLI 示例暂缓）✅
- Task 8 FMS 挂载（配置 + SPL 引导 + 建库自愈，php7.3.4 实测）✅
- Task 9 FMS 对话 demo 页 + SSE 端点（CLI 直调实测 + 浏览器确认）✅
- Task 10 端到端验收：通义千问真实对话（非流式 + 流式 + 落库核对）✅

## M2：积分 + 权限 🚧 进行中

计划文档：[plans/2026-10-21-aiflow-m2-implementation.md](../plans/2026-10-21-aiflow-m2-implementation.md)

- 计划文档编写 ✅（待用户终审）
- 接入指南落地 ✅（docs/integration.md；path 仓库「下载引用即用」实测通过 2026-10-21）
- Task 1 异常树 + 计价规则 PriceBook ✅（测试绿：setPriceRule upsert + calcAmount 已实现）
- Task 2 钱包 ensureWallet / walletInfo / grant ✅（TDD：5 用例；并发首建重查、充值+流水同事务、kind=grant 入账）
- Task 3 预检 reserve（原子 UPDATE + InsufficientCreditException）✅（TDD：6 用例；**顺带修复 PdoStorage 整型参数绑定 bug**——SQLite 表达式 vs TEXT 参数跨类比较恒假，契约套件已加回归用例）
- Task 4 settle 幂等清算 + release 解冻 ✅（TDD：7 用例；实付可超额→负余额、防御性解冻、流水最近优先）
- Task 5 Billing 两阶段编排 ✅（TDD：6 用例；异常自动解冻透传、余额不足模型零调用、refId 重试不重复扣账）
- Task 6 AccessService RBAC + FixedUserProvider ✅（TDD：10 用例一次全绿——deny 优先、通配 `app:*`/`*:*`、超管=显式通配、未挂角色全拒绝、guard 异常上下文）
- Task 7 Kernel credit() / guard() 装配 ✅（TDD：3 用例；guard 惰性单例、access.user_provider 自定义注入 & FixedUserProvider 配置驱动双形态）
- Task 8 MySQL 契约轮 + FMS CLI（wallet / access / price / 计费对话）✅
  - aiflow：`CreditServiceMysqlTest`（+2 专属用例：全表 InnoDB 校验、跨连接 reserve 原子性）+ `BillingMysqlTest`；MySQL 轮 121 用例全绿（1403 断言）
  - fms：CLI 新增 wallet/access/price 三动作；chat/chatStream 接 guard + Billing；config 加 access 段；**php7.3.4 实跑验收通过**：
    未授权拦截 → 授权 → 充值 1 元 → 计费对话（reserve 2 分预估/settle 0 分实付、流水核对一致）→ 天价规则耗尽拦截（need=1026000/have=100，模型零调用）→ 恢复价格后流式计费对话正常
- Task 9 FMS demo 页余额展示 + 计费拦截 ⬜
- Task 10 端到端验收 + 收尾 ⬜

## M3：Agent 平台 ✅ 完成

计划文档：[plans/2026-10-21-aiflow-m3-implementation.md](../plans/2026-10-21-aiflow-m3-implementation.md)（本日随会话实施）

- Provider 透传 tools/tool_choice（OpenAI function-calling 协议）✅
- ToolInterface + AgentResult + AgentRoundExhaustedException（异常树新增）✅
- AgentRuntime 循环（9 用例：零工具直答 / 循环至终答 / 单轮多调用 / 未知工具自纠 / 工具异常回填 / 非法 JSON 容错 / max_rounds 防死循环 / 权限门 deny 中止+allow 放行）✅
- Kernel agent() 访问器 + examples/agent.php 冒烟（无 key 走脚本桩，全量 132 用例绿）✅
- 待办：接 FMS 侧消费（demo/插件方式由用户定）；真实模型验证待 AIFLOW_QWEN_KEY 场景实跑 examples/agent.php

## M4：工作流引擎 ✅ 完成

计划文档：[plans/2026-10-21-aiflow-m4-implementation.md](../plans/2026-10-21-aiflow-m4-implementation.md)（本日随会话实施）

- Task 1 契约与值对象：Workflow 基类 / Node 四工厂 / TaskInterface / WorkflowRunResult / 异常树（WorkflowDefinitionException、NodeRetryExhaustedException）✅
- Task 2 引擎同步 run()：task + decision + retries + 全链路落库（run/每节点 run_log/skipped 日记/编译期校验）✅
- Task 3 llm 节点：prompt 构造器（string / OpenAI messages 双形态）→ gateway → 回复入 context、用量入日志 ✅
- Task 4 parallel 节点：分支隔离（主上下文副本）+ 产物聚合（只合回新增/变更键）✅
- Task 5 异步队列：`003_workflow_queue.sql` + dispatch()/consume()（原子抢占、坏行不中断、run_id 回填）✅ —— 用户拍板：存储表默认队列，Redis 后续 QueueInterface 可插拔
- Task 6 Kernel workflow() 访问器 + examples/workflow.php 冒烟 + CI 挂载 ✅
- MySQL 契约轮：`WorkflowEngineMysqlTest` 复用同套用例 + InnoDB 专属校验（三表）+ 共享库动态清场 ✅
- 测试抓到并修复 1 个真 bug：retry 元数据 `_retries` 曾污染业务上下文（改为只入日志）
- 全量：173 用例 / 1642 断言全绿（3 skip 为 live HTTP/Redis；MySQL 契约轮实库通过；PHP 7.3.4 真机同套全绿）
- 待办：FMS 一条成熟财务流程以工作流挂载（原 M4 核心验收，待 FMS 侧消费）；真实模型验证待 `AIFLOW_QWEN_KEY` 场景实跑 examples/workflow.php

## M5：开源发布 ⬜ 下一步

README（10 秒装包示例）+ examples + 文档站 + Python RAG 桥；Packagist 发布 `jasonye88/aiflow`（Apache 2.0）。

---

## 备忘

- 测试：aiflow 根目录 `php vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php tests`；MySQL 契约轮需设 `AIFLOW_TEST_MYSQL_DSN`（本机 `aiflow_test` 库，root/root）
- FMS CLI 用 `D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe`（带 redis/pdo_mysql/curl；aiflow src 已过 7.3 语法检查）
- FMS demo：`http://fms.lol.yafex.cn/index.php?c=demo_jasonYe_aiChatDemo&a=index`（本地 vhost）；CLI：`ctrl/demo/jasonYe/aiflowCli.php`
- 千问 key 环境变量：`AIFLOW_QWEN_KEY`（当前在 fms `config/base/aiflow.php` 有明文回退，待挪移）
- **git 提交一律由用户手动执行**（`.cursor/rules/git-discipline.mdc`）
