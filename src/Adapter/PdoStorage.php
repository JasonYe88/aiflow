<?php
declare(strict_types=1);

namespace AIFlow\Adapter;

use AIFlow\Contract\StorageInterface;
use PDO;

/**
 * 基于 PDO 的存储适配器（MySQL / SQLite 通吃）
 * 通过 tests/Adapter/PdoStorageTest.php 接受契约验证
 */
final class PdoStorage implements StorageInterface
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->rollBack();
            } catch (\Throwable $ignored) {
                // 回滚失败不掩盖原始异常
            }
            throw $e;
        }
    }
}
