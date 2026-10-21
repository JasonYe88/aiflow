# AIFlow

可嵌入 PHP 项目的 LLMOps 内核：权限、积分、对话、Agent、工作流。

> 状态：M0 开发中（内核骨架），未发布。设计文档见 docs/plans/。

## 环境要求

- PHP >= 7.4
- ext-pdo、ext-json；可选：ext-redis、Swoole

## 开发

composer install
composer test

## 快速开始（M0）

```bash
composer require yafex/aiflow
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
| 建表迁移（10 张 ai_* 表）| ✅ M0 |
| 模型网关 + 对话 | 🔜 M1 |
| 积分/权限 | 🔜 M2 |
| Agent + 工具 | 🔜 M3 |
| 工作流引擎 | 🔜 M4 |