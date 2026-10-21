<?php
declare(strict_types=1);

namespace AIFlow\Support;

/**
 * 32 位十六进制主键生成器
 * 前 12 位为毫秒时间戳十六进制（趋势递增、可肉眼读时间），后 20 位随机
 * 为什么不用 AUTO_INCREMENT：使建表 SQL 在 MySQL 与 SQLite 之间可移植
 */
final class Id
{
    public static function gen(): string
    {
        $ts = dechex((int)(microtime(true) * 1000));
        $rand = bin2hex(random_bytes(10));
        return str_pad($ts, 12, '0', STR_PAD_LEFT) . $rand;
    }
}