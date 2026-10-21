<?php
declare(strict_types=1);

namespace AIFlow\Support;

use AIFlow\Contract\StorageInterface;

/**
 * 极简迁移器：按文件名顺序执行 migrations/*.sql，已执行的记入 ai_migration
 * SQL 须为 MySQL/SQLite 双兼容（设计决策见计划文档）
 *
 * 不用事务包裹：MySQL 的 DDL 会隐式提交，事务包裹形同虚设；
 * 改为逐条顺序执行，全部成功后才写版本记录，失败时异常携带定位信息便于手工修复后续跑
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
        $this->storage->execute($this->ddl(
            'CREATE TABLE IF NOT EXISTS ai_migration (
                version VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            )'
        ));

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
            $this->apply($file, $version);
            $applied[] = $version;
        }
        return $applied;
    }

    /**
     * 逐条顺序执行单个迁移文件的语句，全部成功后才写版本记录
     *
     * 不包事务：MySQL 的 DDL 隐式提交，事务回滚保不住已建的表；
     * 半途失败时抛出携带定位信息的异常（文件名 + 语句序号 + 语句前 80 字符），
     * 人工修复（如残留表先 DROP 或改 IF NOT EXISTS）后重跑 migrate() 即可续上，
     * 已应用的版本会按 ai_migration 记录跳过
     *
     * @param string $file 迁移文件绝对路径
     * @param string $version 版本名（文件名）
     * @throws \RuntimeException 任一语句执行失败时抛出
     */
    private function apply(string $file, string $version): void
    {
        $statements = $this->statements((string)file_get_contents($file));
        $total = count($statements);
        foreach ($statements as $index => $sql) {
            try {
                $this->storage->execute($this->ddl($sql));
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf(
                    '迁移 %s 第 %d/%d 条语句执行失败（%s: %s），语句前 80 字符：%s；'
                    . '已执行语句因 DDL 隐式提交不会回滚，请手工修复（残留表先 DROP 或改 IF NOT EXISTS）后重跑 migrate() 续上',
                    $version,
                    $index + 1,
                    $total,
                    get_class($e),
                    $e->getMessage(),
                    substr($sql, 0, 80)
                ), 0, $e);
            }
        }
        $this->storage->execute(
            'INSERT INTO ai_migration (version, applied_at) VALUES (?, ?)',
            [$version, date('Y-m-d H:i:s')]
        );
    }

    /**
     * MySQL 方言增强：CREATE TABLE 语句追加 ENGINE=InnoDB
     *
     * 为什么：宿主 MySQL 可能默认 MyISAM（无事务，如 phpstudy 的 5.7 配置），
     * 而内核的钱包两阶段记账、契约事务用例都强依赖事务；
     * SQLite 不认识 ENGINE 子句，故非 MySQL 驱动原样返回
     *
     * @param string $sql 单条语句（statements() 已去掉结尾分号）
     */
    private function ddl(string $sql): string
    {
        if (strpos($this->storage->driverName(), 'mysql') !== 0) {
            return $sql;
        }
        if (stripos(ltrim($sql), 'CREATE TABLE') !== 0) {
            return $sql; // 仅处理建表语句
        }
        if (stripos($sql, 'ENGINE=') !== false) {
            return $sql; // 已显式指定引擎，尊重之
        }
        return $sql . ' ENGINE=InnoDB';
    }

    /** @return string[] 按文件名排序的 SQL 文件绝对路径 */
    private function sqlFiles(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files);
        return $files;
    }

    /**
     * 把 SQL 文本拆成单条语句
     *
     * 硬约束（违反将导致拆句错误）：
     * - 字符串字面量内禁含分号（本拆分器不解析引号内的分号）；
     * - 注释必须独占一行（行首 -- 开头），行尾注释不支持。
     * 仅当分号位于行尾（其后至换行无有效内容）才视为语句结束，
     * 避免把字符串字面量或行内残留分号误当语句边界
     *
     * @param string $sql 迁移文件全文
     * @return string[] 语句列表（已剔除注释与空行、去掉结尾分号）
     */
    private function statements(string $sql): array
    {
        $lines = preg_split('/\R/u', $sql) ?: [];
        $statements = [];
        $buffer = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                continue; // 跳过空行与独占一行的注释
            }
            $endsWithSemicolon = substr($trimmed, -1) === ';';
            $buffer[] = $endsWithSemicolon ? substr($trimmed, 0, -1) : $trimmed;
            if ($endsWithSemicolon) {
                $statement = trim(implode("\n", $buffer));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = [];
            }
        }
        // 容错：文件末条语句漏写分号也照常执行
        if ($buffer !== []) {
            $statement = trim(implode("\n", $buffer));
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
        return $statements;
    }
}
