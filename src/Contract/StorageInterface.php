<?php
declare(strict_types=1);

namespace AIFlow\Contract;

/**
 * 存储适配器契约：内核所有表读写走此接口
 * 换存储实现须通过 tests/Contract/StorageContractTest.php 契约测试
 */
interface StorageInterface
{
    /**
     * 执行写语句（INSERT/UPDATE/DELETE/DDL）
     *
     * @param string $sql    SQL 语句，占位符用 ?
     * @param array  $params 绑定参数
     * @return int 影响行数
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * 执行读语句，返回关联数组列表
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array;

    /**
     * 事务包裹：$fn 内抛异常则回滚并重新抛出
     *
     * @param callable $fn function(StorageInterface $s): mixed
     * @return mixed $fn 的返回值
     */
    public function transaction(callable $fn);
}