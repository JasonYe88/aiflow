# aiflow 文档中心

> 可嵌入 PHP LLMOps 内核——权限、积分、对话、Agent、工作流五大能力标准件。
> 宿主项目 require 后经 `AIKernel::boot()` 接入；FMS 财务数字员工为第一个真实消费者。

## 目录结构

| 路径 | 内容 |
|------|------|
| [architecture.md](architecture.md) | 代码结构：分层架构图、目录树与文件职责、依赖方向约定 |
| [../database/schema.mysql.sql](../database/schema.mysql.sql) | 整库 MySQL DDL：11 张表终态一次灌库（快速安装/DBA 管控场景） |
| [integration.md](integration.md) | 宿主接入指南：Composer 安装三方式、15 行示例、配置速查、部署注意 |
| [design/](design/2026-10-21-aiflow-llmops-framework-design.md) | 总体设计：架构、五大模块、数据表、里程碑路线图（已逐节评审确认） |
| [plans/](plans/) | 各里程碑实施计划：任务分解、完整代码、验收用例 |
| [progress/todo.md](progress/todo.md) | 任务清单快照：M0-M5 全景与当前进度 |

## 里程碑状态（2026-10-21）

| 期 | 内容 | 状态 |
|----|------|------|
| M0 | Kernel 骨架 + Contract + MySQL/SQLite 适配器 + 迁移 | ✅ 完成 |
| M1 | 模型网关（OpenAI 兼容）+ ChatService + SSE 流式 + FMS 挂载 | ✅ 完成（千问真实验收通过） |
| M2 | CreditLedger 两阶段记账 + AccessControl RBAC | ✅ aiflow 侧完成（FMS 页面消费 Task 9-10 待定——页面接入拟改插件方式） |
| M3 | AgentRuntime + 工具注册 | ✅ 完成（function-calling 循环 + 工具权限门，132 用例绿） |
| M4 | WorkflowEngine + 4 种内置节点 + 存储表异步队列 | ✅ 完成（同步 run / 异步 dispatch+consume / 全链路落库） |
| M5 | 开源发布（Packagist `jasonye88/aiflow`） | ⬜ 待启动（下一步） |

## 快速上手

```php
$kernel = \AIFlow\AIKernel::boot([
    'storage'   => ['dsn' => 'mysql:host=127.0.0.1;dbname=aiflow_demo;charset=utf8mb4', 'user' => 'root', 'pass' => '...'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
]);

$kernel->gateway()->chatStream('qwen', [['role' => 'user', 'content' => '你好']], [], $onDelta);
```

FMS 宿主接入见 fms 仓库：`include/aiflowBootstrap.php` + `config/base/aiflow.php`。
