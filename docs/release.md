# 发布清单（Packagist）

> aiflow **不代推 Git / 不代提交 Packagist**。本文件是你本地发版时的检查单。

## 发版前

- [ ] `composer validate --strict`
- [ ] `composer test`（可选设 `AIFLOW_TEST_MYSQL_DSN`）
- [ ] `php examples/boot.php` · `agent.php` · `workflow.php` · `knowledge.php`
- [ ] README / CHANGELOG 版本说明已对齐
- [ ] `LICENSE` 为 Apache-2.0

## 打 tag 并推送（由你执行）

```bash
git status          # 确认要提交的变更
# …你的 commit…
git tag -a v0.1.0 -m "aiflow 0.1.0"
git push origin master
git push origin v0.1.0
```

## 提交 Packagist

1. 打开 [packagist.org/packages/submit](https://packagist.org/packages/submit)
2. Repository URL：`https://github.com/JasonYe88/aiflow`
3. 提交后在包页开启 GitHub Service Hook / Packagist 应用，保证后续 tag 自动同步
4. 包名应为：`jasonye88/aiflow`

## 宿主验收（15 分钟）

```bash
mkdir /tmp/aiflow-host && cd /tmp/aiflow-host
composer require jasonye88/aiflow:^0.1
```

```php
<?php
require 'vendor/autoload.php';
$k = \AIFlow\AIKernel::boot([
    'storage' => ['dsn' => 'sqlite:' . __DIR__ . '/demo.sqlite'],
    'cache' => ['driver' => 'array'],
    'migration' => ['auto' => true],
]);
echo count($k->migrate() ?: ['already']) ? "ok\n" : "ok\n";
```

若 Packagist 尚未收录，临时用 VCS：

```json
{
  "repositories": [{"type": "vcs", "url": "https://github.com/JasonYe88/aiflow.git"}],
  "require": {"jasonye88/aiflow": "dev-master"}
}
```

## 可选：Python RAG 桥

```bash
python plugins/python-rag/server.py
# 另开终端
php examples/rag.php
```
