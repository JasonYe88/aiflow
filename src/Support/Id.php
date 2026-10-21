<?php
declare(strict_types=1);

namespace AIFlow\Support;

/**
 * 32 位十六进制主键生成器（进程内严格单调递增）
 * 结构：[12 位毫秒时间戳 hex][14 位随机 hex][6 位序号 hex]
 * - 时间戳前缀：趋势递增、可肉眼读时间；跨毫秒天然有序
 * - 同毫秒内：随机段固定、末 6 位序号自增 → 字符串序 = 生成序
 * - 这保证 ORDER BY id ASC 即按生成顺序排序（会话活跃度/消息历史排序都依赖它）
 * 为什么不用 AUTO_INCREMENT：使建表 SQL 在 MySQL 与 SQLite 之间可移植
 */
final class Id
{
    /** @var int 上一个 id 的毫秒时间戳（0 = 尚未生成过） */
    private static $lastTs = 0;

    /** @var int 同毫秒内已用的序号（0xFFFFFF 回绕，见 gen()） */
    private static $lastSeq = 0;

    /** @var string 当前毫秒批次的随机段（14 hex） */
    private static $lastRand = '';

    public static function gen(): string
    {
        $ts = (int)(microtime(true) * 1000);
        if ($ts <= self::$lastTs) {
            // 同毫秒或时钟回拨：沿用毫秒与随机段，仅序号自增，保证严格递增
            $rand = self::$lastRand;
            self::$lastSeq++;
            if (self::$lastSeq > 0xFFFFFF) {
                // 24 位序号回绕（单进程单毫秒 1600 万次才会发生）：推进到下一毫秒重开一批
                self::$lastTs = $ts = self::$lastTs + 1;
                self::$lastSeq = 0;
                $rand = self::$lastRand = bin2hex(random_bytes(7));
            }
        } else {
            self::$lastTs = $ts;
            self::$lastRand = $rand = bin2hex(random_bytes(7));
            self::$lastSeq = 0;
        }
        return str_pad(dechex($ts), 12, '0', STR_PAD_LEFT)
            . $rand
            . str_pad(dechex(self::$lastSeq), 6, '0', STR_PAD_LEFT);
    }
}
