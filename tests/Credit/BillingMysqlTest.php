<?php
declare(strict_types=1);

namespace AIFlow\Tests\Credit;

/**
 * Billing MySQL 契约轮：/Credit/BillingTest 的全量用例在 MySQL（InnoDB）上同构重跑
 * 环境开关与清场逻辑同 CreditServiceMysqlTest
 */
class BillingMysqlTest extends BillingTest
{
    /** @var \PDO|null */
    private $pdo;

    protected function makePdo(): \PDO
    {
        $dsn = getenv('AIFLOW_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('未设置 AIFLOW_TEST_MYSQL_DSN，跳过 MySQL 契约轮');
        }
        $user = getenv('AIFLOW_TEST_MYSQL_USER');
        $pass = getenv('AIFLOW_TEST_MYSQL_PASS');
        $this->pdo = new \PDO(
            $dsn,
            $user === false ? '' : $user,
            $pass === false ? '' : $pass
        );
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->dropAiTables();
        return $this->pdo;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->dropAiTables();
            $this->pdo = null;
        }
    }

    private function dropAiTables(): void
    {
        $tables = [
            'ai_ledger_entry', 'ai_price_rule', 'ai_wallet',
            'ai_role_resource', 'ai_resource', 'ai_role',
            'ai_conversation', 'ai_message',
            'ai_workflow_run', 'ai_workflow_run_log', 'ai_workflow_queue', 'ai_migration',
        ];
        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
}