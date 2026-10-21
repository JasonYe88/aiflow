<?php
declare(strict_types=1);

namespace AIFlow\Tests\Workflow;

/**
 * MySQL 契约轮：复用同套引擎用例（test all the things，双库一致性）
 * 设 AIFLOW_TEST_MYSQL_DSN（可另设 *_USER / *_PASS）时生效，未设则跳过
 */
class WorkflowEngineMysqlTest extends WorkflowEngineTest
{
    protected function makePdo(): \PDO
    {
        $dsn = getenv('AIFLOW_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('未设置 AIFLOW_TEST_MYSQL_DSN，跳过 MySQL 契约轮');
        }
        $user = getenv('AIFLOW_TEST_MYSQL_USER');
        $pass = getenv('AIFLOW_TEST_MYSQL_PASS');
        return new \PDO(
            (string)$dsn,
            $user === false ? '' : (string)$user,
            $pass === false ? '' : (string)$pass
        );
    }

    protected function setUp(): void
    {
        if (getenv('AIFLOW_TEST_MYSQL_DSN')) {
            // 共享测试库：动态清掉全部 ai_* 残留（其他契约套件可能留过表），保证用例可重复跑
            $pdo = $this->makePdo();
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach ($pdo->query("SHOW TABLES LIKE 'ai\\_%'")->fetchAll(\PDO::FETCH_NUM) as $row) {
                $pdo->exec('DROP TABLE IF EXISTS `' . $row[0] . '`');
            }
        }
        parent::setUp();
    }

    /** MySQL 专属：队列表与运行表引擎必须 InnoDB（抢占 UPDATE 依赖行锁） */
    public function testWorkflowTablesAreInnoDB(): void
    {
        if (!getenv('AIFLOW_TEST_MYSQL_DSN')) {
            $this->markTestSkipped('MySQL 专属用例');
        }
        $engines = $this->storage->query(
            "SELECT TABLE_NAME name, ENGINE engine FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ai_workflow_run','ai_workflow_run_log','ai_workflow_queue')"
        );
        $this->assertCount(3, $engines);
        foreach ($engines as $row) {
            $this->assertSame('InnoDB', $row['engine'], "表 {$row['name']} 引擎必须为 InnoDB");
        }
    }
}