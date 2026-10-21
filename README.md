# AIFlow

可嵌入 PHP 项目的 LLMOps 内核：权限、积分、对话、Agent、工作流。

> 状态：M4 完成（网关/对话/积分/权限/Agent/工作流五大能力齐备，152 用例全绿）。
> 接入方式与最小示例见 [docs/integration.md](docs/integration.md)（path/VCS 仓库安装已实测可用；Packagist 直装 M5 发布后生效）。

## 环境要求

- PHP >= 7.3（生产机友好；全量测试在 7.3.4 / 7.4 / 8.x 通过，CI 三版本矩阵守护）
- ext-pdo、ext-json、ext-curl；可选：ext-redis

## 开发

composer install
composer test

## 快速开始

```bash
# 方式 A：Packagist 直装（M5 发布后）
composer require yafex/aiflow

# 方式 B：VCS 仓库（已可用）
# 宿主 composer.json: repositories 声明 vcs url 后 require yafex/aiflow

# 方式 C：path 仓库（本地联调，已实测）
# 宿主 composer.json: repositories 声明 { "type": "path", "url": "../aiflow" } 后 require yafex/aiflow: dev-master
```

```php
require 'vendor/autoload.php';

use AIFlow\AIKernel;

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'mysql:host=127.0.0.1;dbname=fms;charset=utf8mb4', 'user' => 'root', 'pass' => ''],
    'cache'     => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379],
    'migration' => ['auto' => true],
]);
```

## 能力矩阵（M0 = 骨架完成）

| 能力 | 状态 |
|------|------|
| Kernel 引导 + 配置 | ✅ M0 |
| 存储适配（MySQL/SQLite）| ✅ M0 |
| 缓存适配（Redis/Array）| ✅ M0 |
| 建表迁移（11 张 ai_* 表）| ✅ M0（003 队列表 M4 增）|
| 模型网关 + 对话 + SSE 流式 | ✅ M1（千问真实验收通过）|
| token 计价（PriceBook）| ✅ M2 |
| 钱包两阶段记账 + Billing 编排 | ✅ M2 |
| RBAC 权限（deny 优先/通配）| ✅ M2 |
| FMS 宿主接入（计费对话/余额拦截）| ✅ CLI 已验收；demo 页拟改插件方式 |
| Agent + 工具调用（function-calling）| ✅ M3 |
| 工作流引擎（task/decision/llm/parallel + 存储队列）| ✅ M4 |