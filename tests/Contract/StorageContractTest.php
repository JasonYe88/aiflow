<?php
declare(strict_types=1);

namespace AIFlow\Tests\Contract;

use AIFlow\Contract\StorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * Storage 契约测试套件
 * 任何 StorageInterface 实现继承本类并实现 makeStorage() 即可接受契约验证
 */
abstract class StorageContractTest extends TestCase
{
    /** @var StorageInterface */
    protected $storage;

    /** 各实现返回全新实例，测试前会执行建表 */
    abstract protected function makeStorage(): StorageInterface;

    protected function setUp(): void
    {
        $this->storage = $this->makeStorage();
        $this->createTestTable();
    }

    /**
     * 建契约测试表
     * 默认 ANSI 可移植写法；MySQL 驱动自动追加 ENGINE=InnoDB
     * （宿主 MySQL 可能默认 MyISAM——无事务，事务契约用例会失败）；
     * 其它方言实现可覆写本方法定制 DDL
     */
    protected function createTestTable(): void
    {
        $sql = 'CREATE TABLE contract_t (id VARCHAR(32) PRIMARY KEY, num INT NOT NULL, val TEXT)';
        if (strpos($this->storage->driverName(), 'mysql') === 0) {
            $sql .= ' ENGINE=InnoDB';
        }
        $this->storage->execute($sql);
    }

    public function testExecuteAndQuery(): void
    {
        $n = $this->storage->execute(
            'INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)',
            ['id001', 1, 'hello']
        );
        $this->assertSame(1, $n);

        $rows = $this->storage->query('SELECT * FROM contract_t WHERE id = ?', ['id001']);
        $this->assertCount(1, $rows);
        $this->assertSame('hello', $rows[0]['val']);
    }

    public function testUpdateAffectsRows(): void
    {
        $this->storage->execute('INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)', ['a', 1, 'x']);
        $n = $this->storage->execute('UPDATE contract_t SET val = ? WHERE num = ?', ['y', 1]);
        $this->assertSame(1, $n);
        $this->assertSame('y', $this->storage->query('SELECT val FROM contract_t WHERE id = ?', ['a'])[0]['val']);
    }

    public function testTransactionCommits(): void
    {
        $this->storage->transaction(function (StorageInterface $s) {
            $s->execute('INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)', ['b', 2, 'ok']);
        });
        $this->assertCount(1, $this->storage->query('SELECT * FROM contract_t WHERE id = ?', ['b']));
    }

    public function testTransactionRollsBackOnThrow(): void
    {
        try {
            $this->storage->transaction(function (StorageInterface $s) {
                $s->execute('INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)', ['c', 3, 'no']);
                throw new \RuntimeException('boom');
            });
            $this->fail('异常应被重新抛出');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertCount(0, $this->storage->query('SELECT * FROM contract_t WHERE id = ?', ['c']));
    }

    /**
     * 整型表达式与参数比较必须命中（回归用例）
     * 背景：SQLite"整型表达式 vs TEXT 参数"跨存储类比较恒为假——列比较靠列 affinity 转
     * 换参数侥幸可用，表达式（如 balance - frozen >= ?）无 affinity 兜底，必须是整型绑定；
     * MySQL 靠隐式转换掩盖了该问题。M2 reserve 的冻结 UPDATE 曾因此 0 命中。
     */
    public function testIntegerParamBindsAsIntInExpressionCompare(): void
    {
        $this->storage->execute('INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)', ['n1', 5, 'low']);
        $this->storage->execute('INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)', ['n2', 50, 'high']);

        $n = $this->storage->execute('UPDATE contract_t SET val = ? WHERE num * 10 >= ?', ['hit', 100]);
        $this->assertSame(1, $n, '整数参数在表达式比较中必须正常命中');

        $rows = $this->storage->query('SELECT id FROM contract_t WHERE num * 10 = ?', [500]);
        $this->assertCount(1, $rows);
        $this->assertSame('n2', $rows[0]['id']);
    }
}
