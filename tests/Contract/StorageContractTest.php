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
        $this->storage->execute(
            'CREATE TABLE contract_t (id VARCHAR(32) PRIMARY KEY, num INT NOT NULL, val TEXT)'
        );
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
}