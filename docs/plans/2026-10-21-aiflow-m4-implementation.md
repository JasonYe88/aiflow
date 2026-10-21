# AIFlow M4（WorkflowEngine 工作流引擎）实施记录

> **执行方式:** 逐 Task TDD；提交由用户手动执行。
> **启动:** 2026-10-21 随会话实施（用户决策：异步队列默认存储表——RedQueue 后续做可插拔实现）。

**Goal:** 工作流 = 纯 PHP 类（编译期可校验、IDE 可跳转）；引擎负责四种内置节点的调度、重试、运行记录全链路落库；同步 `run()` + 异步 `dispatch()`。

**设计依据:** design 文档 §5（WorkflowEngine，核心里程碑）。

## 已确认决策（2026-10-21）

1. **节点必须有 key**：`Node::task('key', Class::class)`——决策跳转、日志定位、上下文产物命名都靠它；`ai_workflow_run_log.node_key` 直接消费
2. **数据流语义**：context 为 key-value 数组贯穿全程；task 节点 `execute($input): array` 收到的 $input 就是当前 context，返回数组 `array_merge` 合入 context（增量更新，产物互不覆盖）；每节点 input/output 快照均落 `run_log`
3. **decision 节点**：`Node::decision('key', 'context_field', [值=>目标key, ...])`——按 context 字段的实际取值跳转；未命中任何分支→顺序走下一节点；缺失字段视为 null
4. **task 节点**：业务类实现 `TaskInterface::execute(array $input): array`；节点构造参数可带 `['retries' => N]`，任务抛异常按 N 重试后仍失败→ run 置 failed 并抛 `NodeRetryExhaustedException`
5. **llm 节点**:Node::llm('key', $channel, $promptBuilder)——$promptBuilder 可为 `fn(array $ctx): string` 或 `fn(array $ctx): array(OpenAI messages)`；gateway 调用结果 content 存入 `context['key']`（token 用量带落 run_log）
6. **parallel 语义（FPM 生存术）**:Node::parallel('key', ['branchA' => Class|classes链, ...])——FPM 下顺序执行各分支，每分支收主 context 副本（相互隔离），分支产物合回 `context['key']['branchName']`；Swoole/Go 场景可换并发实现（引擎不封锁）
7. **运行记录**：run 启动即插一条 `ai_workflow_run`（running），每节点执行前后各落一条 `ai_workflow_run_log`；异常也如此（可观测优先）
8. **异步队列 = 存储表**：新增迁移 `003_workflow_queue.sql`（`ai_workflow_queue`：status/context/attempts/available_at 全生命周期）；`dispatch()` 入队返回队列 id；`consume($limit)` 供 cron/FPM 驱动逐条执行（带 status=queued → running → done/failed 的原子抢占）；Redis 队列后续以 `QueueInterface` 可插拔
9. **失败传播**：run() 重试耗尽后 run 行置 failed、异常抛给宿主；未捕获异常（非任务类）同样置 failed 后透传
10. **Kernel 装配**：`$kernel->workflow()` 惰性单例（挂 storage + gateway）

## Task 分解（实施进度）

- Task 1 Node/Workflow/TaskInterface/WorkflowContext 契约与值对象 ✅
- Task 2 WorkflowEngine 同步 run()：task + decision + 重试 + 全链路落库 ✅
- Task 3 llm 节点（gateway 包装）✅
- Task 4 parallel 节点（分支隔离与合并）✅
- Task 5 dispatch/consume 存储队列 + 003 迁移 ✅
- Task 6 Kernel workflow() + examples/workflow.php + docs 同步 ✅

## 验收

- 全部测试绿（含 003 迁移后 MySQL 契约轮）
- `php examples/workflow.php` 冒烟：无 key 走脚本桩渠道跑一条含 task/decision/llm/parallel 的完整工作流
- FMS 财务流程挂载（原 M4 核心验收）→ 待 FMS 侧消费（页面/插件方式另议）