<?php
declare(strict_types=1);

/**
 * M0 冒烟示例：boot 内核 + 迁移 + 清点表数量
 * 运行：php examples/boot.php
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\AIKernel;

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . sys_get_temp_dir() . '/aiflow_m0_demo.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
]);

$tables = $kernel->storage()->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%'"
);
$versions = $kernel->storage()->query('SELECT version FROM ai_migration');

echo "AIFlow M0 boot OK\n";
echo 'ai_* tables: ' . count($tables) . " (含 ai_migration)\n";
echo 'applied migrations: ' . implode(', ', array_column($versions, 'version')) . "\n";
