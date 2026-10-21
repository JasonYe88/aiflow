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

    public function driverName(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        return $stmt->rowCount();
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 按值类型绑定参数（整型必须 PDO::PARAM_INT）
     * 为什么不用 $stmt->execute($params)：它把所有参数绑成字符串；
     * SQLite 对"整型表达式 vs TEXT 参数"的跨存储类比较恒为假——列比较靠列 affinity
     * 转换参数侥幸可用，表达式（如 balance - frozen >= ?）没有 affinity 兜底，
     * 会静默 0 命中（M2 reserve 冻结 UPDATE 的实际事故）；MySQL 靠隐式转换掩盖了该问题。
     */
    private function bind(\PDOStatement $stmt, array $params): void
    {
        if ($params === []) {
            $stmt->execute();
            return;
        }
        foreach ($params as $i => $value) {
            if (is_int($value)) {
                $stmt->bindValue($i + 1, $value, PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($i + 1, (int)$value, PDO::PARAM_INT);
            } elseif (is_null($value)) {
                $stmt->bindValue($i + 1, $value, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($i + 1, (string)$value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
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
