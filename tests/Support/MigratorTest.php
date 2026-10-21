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

    /** @var bool 当前测试是否跑在 MySQL 上（SQLite 内存库无需清理） */
    private $onMysql = false;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoStorage($pdo);
        $this->migrationsDir = dirname(__DIR__, 2) . '/migrations';
    }

    protected function tearDown(): void
    {
        if ($this->onMysql) {
            $this->dropAiTables();
        }
    }

    public function testMigrateAppliesAllAndIsIdempotent(): void
    {
        $migrator = new Migrator($this->storage, $this->migrationsDir);

        $applied = $migrator->migrate();
        $this->assertContains('001_init.sql', $applied);
        $this->assertContains('002_conversation_activity_seq.sql', $applied);
        $this->assertContains('003_workflow_queue.sql', $applied);

        // 幂等：再跑一遍不重复执行
        $this->assertSame([], $migrator->migrate());

        // 版本表已记录
        $versions = $this->storage->query('SELECT version FROM ai_migration');
        $this->assertNotEmpty($versions);

        // 11 张业务表全部存在（11 张 + 版本表）
        $tables = array_column(
            $this->storage->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%'"
            ),
            'name'
        );
        $this->assertCount(12, $tables);
        foreach (['ai_role', 'ai_resource', 'ai_role_resource', 'ai_wallet', 'ai_price_rule',
                  'ai_ledger_entry', 'ai_conversation', 'ai_message',
                  'ai_workflow_run', 'ai_workflow_run_log', 'ai_workflow_queue'] as $table) {
            $this->assertContains($table, $tables);
        }
    }

    /**
     * 环境变量开关的 MySQL 版迁移测试：验证 DDL 逐条执行（无事务包裹）在 MySQL 上真实可用
     * 设置 AIFLOW_TEST_MYSQL_DSN（可另设 AIFLOW_TEST_MYSQL_USER / AIFLOW_TEST_MYSQL_PASS）时生效，未设置则跳过
     */
    public function testMigrateOnMysqlWhenDsnProvided(): void
    {
        $dsn = getenv('AIFLOW_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('未设置 AIFLOW_TEST_MYSQL_DSN，跳过 MySQL 迁移测试');
        }
        $user = getenv('AIFLOW_TEST_MYSQL_USER');
        $pass = getenv('AIFLOW_TEST_MYSQL_PASS');
        $pdo = new \PDO(
            $dsn,
            $user === false ? '' : $user,
            $pass === false ? '' : $pass
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoStorage($pdo);
        $this->onMysql = true;

        // 自愈：清掉可能的残留 ai_* 表，保证可重复跑
        $this->dropAiTables();

        $migrator = new Migrator($this->storage, $this->migrationsDir);

        // 首跑：001 建 10 张表 + 002 加活跃序列列 + 003 工作流队列，全部在 MySQL 上执行成功
        $applied = $migrator->migrate();
        $this->assertContains('001_init.sql', $applied);
        $this->assertContains('002_conversation_activity_seq.sql', $applied);
        $this->assertContains('003_workflow_queue.sql', $applied);

        // 幂等：重跑跳过已应用版本，不报 table already exists / duplicate column
        $this->assertSame([], $migrator->migrate());

        // 版本记录已写入
        $versions = $this->storage->query('SELECT version FROM ai_migration');
        $this->assertSame(
            [
                ['version' => '001_init.sql'],
                ['version' => '002_conversation_activity_seq.sql'],
                ['version' => '003_workflow_queue.sql'],
            ],
            $versions
        );

        // 引擎断言：宿主 MySQL 可能默认 MyISAM（无事务），迁移必须强制 InnoDB，
        // 否则钱包两阶段记账（M2）等事务语义会静默失效
        $engines = $this->storage->query(
            "SELECT TABLE_NAME name, ENGINE engine FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'ai\\_%'"
        );
        $this->assertNotEmpty($engines);
        foreach ($engines as $row) {
            $this->assertSame('InnoDB', $row['engine'], "表 {$row['name']} 引擎必须为 InnoDB");
        }
    }

    /** MySQL 清场：DROP 全部 ai_* 表（含 ai_migration），供自愈与 tearDown 复用 */
    private function dropAiTables(): void
    {
        foreach ($this->storage->query("SHOW TABLES LIKE 'ai\\_%'") as $row) {
            $table = (string)reset($row);
            $this->storage->execute("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}