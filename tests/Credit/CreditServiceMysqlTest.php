<?php
declare(strict_types=1);

namespace AIFlow\Tests\Credit;

/**
 * CreditService MySQL 契约轮：SQLite 套件全量同构重跑 + MySQL 特有验收（InnoDB 引擎 / 跨连接原子性）
 *
 * 设置环境变量 AIFLOW_TEST_MYSQL_DSN（可另设 AIFLOW_TEST_MYSQL_USER / AIFLOW_TEST_MYSQL_PASS）
 * 时启用，未设置则跳过；每个用例前清掉 ai_* 残留表，tearDown 再清场
 */
class CreditServiceMysqlTest extends CreditServiceTest
{
    /** @var \PDO|null MySQL 连接（跳过时保持 null） */
    private $pdo;

    /** @var string|null DSN（跨连接原子性用例要用） */
    private $dsn;

    protected function makePdo(): \PDO
    {
        $dsn = getenv('AIFLOW_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('未设置 AIFLOW_TEST_MYSQL_DSN，跳过 MySQL 契约轮');
        }
        $this->dsn = $dsn;
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

    /** aiflow 业务表必须是 InnoDB：宿主 phpstudy MySQL 默认 MyISAM（无事务），两阶段记账强依赖事务 */
    public function testAiTablesAreInnoDB(): void
    {
        $rows = $this->storage->query(
            "SELECT table_name, engine FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE 'ai\\_%'"
        );
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('InnoDB', $row['engine'], "表 {$row['table_name']} 引擎须为 InnoDB");
        }
    }

    /**
     * 跨连接 reserve 原子性（M2 关键验收）：
     * 两个独立连接各持一个 CreditService（模拟两个并发 FPM 请求），
     * 可用余额只够一次冻结时，条件原子 UPDATE + 影响行数判定保证后到者必被拒、总冻结不超可用。
     * 这验证的不是"SQLite 库级锁的侥幸"，而是 InnoDB 行锁下的丢失更新防护。
     */
    public function testReserveAcrossConnectionsIsAtomic(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'concur');
        $this->credit->grant('user', 'concur', 1000);

        $user = getenv('AIFLOW_TEST_MYSQL_USER');
        $pass = getenv('AIFLOW_TEST_MYSQL_PASS');
        $pdoB = new \PDO($this->dsn, $user === false ? '' : $user, $pass === false ? '' : $pass);
        $pdoB->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $creditB = new \AIFlow\Credit\CreditService(new \AIFlow\Adapter\PdoStorage($pdoB));

        // 连接 A 冻结 800（余额 1000 → 可用 200）
        $this->credit->reserve($walletId, 800, 'conn-a');

        // 连接 B 再冻 800：必被拒，且拿到准确的 need/have
        try {
            $creditB->reserve($walletId, 800, 'conn-b');
            $this->fail('跨连接二次冻结应被拒');
        } catch (\AIFlow\Exception\InsufficientCreditException $e) {
            $this->assertSame(800, $e->need);
            $this->assertSame(200, $e->have);
        }

        // 冻结不被覆盖或丢失
        $info = $this->credit->walletInfo('user', 'concur');
        $this->assertSame(800, $info['frozen']);
        $this->assertSame(1000, $info['balance']);
    }

    /** 动态清全部 ai_*（含 004 编目 / 005 图 / 006 知识库），避免白名单漏表导致半截迁移 */
    private function dropAiTables(): void
    {
        foreach ($this->pdo->query("SHOW TABLES LIKE 'ai\\_%'")->fetchAll(\PDO::FETCH_NUM) as $row) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $row[0] . '`');
        }
    }
}