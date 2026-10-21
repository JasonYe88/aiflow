<?php
declare(strict_types=1);

namespace AIFlow\Support;

use AIFlow\Contract\StorageInterface;

/**
 * 极简迁移器：按文件名顺序执行 migrations/*.sql，已执行的记入 ai_migration
 * SQL 须为 MySQL/SQLite 双兼容（设计决策见计划文档）
 */
final class Migrator
{
    /** @var StorageInterface */
    private $storage;

    /** @var string */
    private $dir;

    public function __construct(StorageInterface $storage, string $dir)
    {
        $this->storage = $storage;
        $this->dir = rtrim($dir, '/\\');
    }

    /**
     * 执行未应用的迁移
     *
     * @return string[] 本次应用的迁移文件名（已全部应用过则返回空数组）
     */
    public function migrate(): array
    {
        $this->storage->execute(
            'CREATE TABLE IF NOT EXISTS ai_migration (
                version VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            )'
        );

        $done = array_column(
            $this->storage->query('SELECT version FROM ai_migration'),
            'version'
        );

        $applied = [];
        foreach ($this->sqlFiles() as $file) {
            $version = basename($file);
            if (in_array($version, $done, true)) {
                continue;
            }
            $this->storage->transaction(function (StorageInterface $s) use ($file, $version) {
                foreach ($this->statements(file_get_contents($file)) as $sql) {
                    $s->execute($sql);
                }
                $s->execute(
                    'INSERT INTO ai_migration (version, applied_at) VALUES (?, ?)',
                    [$version, date('Y-m-d H:i:s')]
                );
            });
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return string[] 按文件名排序的 SQL 文件绝对路径 */
    private function sqlFiles(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files);
        return $files;
    }

    /** 把 SQL 文本拆成单条语句（按分号换行边界切，已剔除注释） */
    private function statements(string $sql): array
    {
        $lines = preg_split('/\R/u', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '-') {
                continue; // 跳过空行与注释
            }
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);
        return array_filter(array_map('trim', explode(';', $sql)));
    }
}