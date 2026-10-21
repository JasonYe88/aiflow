<?php
declare(strict_types=1);

namespace AIFlow\Tests\Support;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class MigratorTest extends TestCase
{
    /** @var PdoStorage */
    private $storage;

    /** @var string */
    private $migrationsDir;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoStorage($pdo);
        $this->migrationsDir = dirname(__DIR__, 2) . '/migrations';
    }

    public function testMigrateAppliesAllAndIsIdempotent(): void
    {
        $migrator = new Migrator($this->storage, $this->migrationsDir);

        $applied = $migrator->migrate();
        $this->assertContains('001_init.sql', $applied);

        // 幂等：再跑一遍不重复执行
        $this->assertSame([], $migrator->migrate());

        // 版本表已记录
        $versions = $this->storage->query('SELECT version FROM ai_migration');
        $this->assertNotEmpty($versions);

        // 10 张业务表全部存在（10 张 + 版本表）
        $tables = array_column(
            $this->storage->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%'"
            ),
            'name'
        );
        $this->assertCount(11, $tables);
        foreach (['ai_role', 'ai_resource', 'ai_role_resource', 'ai_wallet', 'ai_price_rule',
                  'ai_ledger_entry', 'ai_conversation', 'ai_message',
                  'ai_workflow_run', 'ai_workflow_run_log'] as $table) {
            $this->assertContains($table, $tables);
        }
    }
}