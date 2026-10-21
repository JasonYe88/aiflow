<?php
declare(strict_types=1);

namespace AIFlow\Tests\Adapter;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Tests\Contract\StorageContractTest;

class PdoStorageTest extends StorageContractTest
{
    protected function makeStorage(): \AIFlow\Contract\StorageInterface
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new PdoStorage($pdo);
    }
}
