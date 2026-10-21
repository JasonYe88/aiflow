# AIFlow M3（AgentRuntime + 工具注册）实施记录

> **执行方式:** 逐 Task TDD（先红后绿）；提交由用户手动执行。
> **状态:** 2026-10-21 随会话直接实施（用户指示优先完成框架自身功能；FMS 页面类消费后续考虑插件化）。

**Goal:** 单 Agent + 工具调用闭环——工具以 PHP 类注册，运行时把工具 schema 喂给 LLM（OpenAI function-calling 协议），解析 tool_calls → 执行 → 结果回填 → 循环直至最终回答；最大轮数防死循环。

**设计依据:** design 文档 §4（AgentRuntime）、§7（异常树）；M2 已就位的 RBAC/计费原地复用。

## 已确认决策

1. **工具契约 `ToolInterface`**：`name()`（喂给模型的 function 名，英文 snake_case）/ `description()`（模型据此决策何时调用）/ `schema()`（JSON Schema 参数声明，associative array）/ `execute(array $args)`（返回 string 或 array，统一编 JSON 回填）
2. **协议走 OpenAI function-calling**：`tools=[{type:function,function:{name,description,parameters}}]` 透传；盘外不引入厂商私有协议，国产兼容渠道全部适用
3. **对话回填**：每轮把 assistant 的完整 message（含 `tool_calls`）与逐个 `role=tool`（带 `tool_call_id`）结果追加进 messages；`arguments` 非法 JSON 容错为 `[]`
4. **未知工具/执行异常**：作为错误性 tool 结果回填模型（模型可自纠 hallucination），**ToolExcution 异常不炸整个 run**
5. **工具权限门（复用 M2 RBAC）**：注入 AccessService + userId 时，每个 tool 执行前 `guard("tool:{$name}")`——拒绝则**抛 PermissionDeniedException 终止 run**（fail fast，特权语义不喂给模型）
6. **最大轮数**：`max_rounds` 可配默认 8，超限抛 `AgentRoundExhaustedException`（新增，挂 AIFlowException 树）
7. **返回 `AgentResult`**：字段与 `ModelResult` 鸭子兼容（content/model/inputTokens/outputTokens/elapsedMs），宿主可直接把 `AgentRuntime::run` 的 callable 丢进 `Billing::run` 按总量计费；另携带 `rounds`、`steps[]`（每轮工具/参数/输出摘要，可观测）
8. **Provider 透传**：`OpenAiCompatibleProvider::payload()` 白名单扩 `tools` / `tool_choice`（M1 白名单制的原地扩展）

## Task 分解（实施进度）

- Task 1 Provider 透传 tools/tool_choice（TDD）✅
- Task 2 ToolInterface + AgentResult + AgentRoundExhaustedException ✅
- Task 3 AgentRuntime 循环（ScriptedProvider 桩：零工具直答 / 循环至终答 / 未知工具自纠 / 轮数上限 / 权限门 / schema 喂模）✅
- Task 4 examples/agent.php 冒烟 + docs/README/todo 同步 ✅

## 验收

- 全部测试绿（SQLite 轮 + 本机 MySQL 契约轮）
- `php examples/agent.php` 冒烟（无 key 场景给出明确提示，不炸）