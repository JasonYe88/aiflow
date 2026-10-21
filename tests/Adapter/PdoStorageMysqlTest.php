<?php
declare(strict_types=1);

namespace AIFlow\Tests\Adapter;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Tests\Contract\StorageContractTest;

/**
 * MySQL 版存储契约测试
 * 设置环境变量 AIFLOW_TEST_MYSQL_DSN（如 mysql:host=127.0.0.1;dbname=aiflow_m0_test;charset=utf8mb4，
 * 可另设 AIFLOW_TEST_MYSQL_USER / AIFLOW_TEST_MYSQL_PASS）时在临时库跑契约，未设置则跳过；
 * setUp 前自愈残留表，tearDown 时 DROP TABLE contract_t 清理现场
 */
class PdoStorageMysqlTest extends StorageContractTest
{
    /** @var \PDO|null MySQL 连接，未设置 DSN 跳过时保持 null */
    private $pdo;

    protected function makeStorage(): \AIFlow\Contract\StorageInterface
    {
        $dsn = getenv('AIFLOW_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('未设置 AIFLOW_TEST_MYSQL_DSN，跳过 MySQL 契约测试');
        }
        $user = getenv('AIFLOW_TEST_MYSQL_USER');
        $pass = getenv('AIFLOW_TEST_MYSQL_PASS');
        $this->pdo = new \PDO(
            $dsn,
            $user === false ? '' : $user,
            $pass === false ? '' : $pass
        );
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // 自愈：清掉上次可能残留的契约表，保证可重复跑
        $this->pdo->exec('DROP TABLE IF EXISTS contract_t');
        return new PdoStorage($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS contract_t');
        }
    }
}
