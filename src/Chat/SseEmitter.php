<?php
declare(strict_types=1);

namespace AIFlow\Chat;

/**
 * SSE（Server-Sent Events）输出器：把增量帧推给浏览器
 * - frame() 是纯函数（可单测），负责拼帧格式
 * - start/send/close 负责 FPM 场景的头与多层输出缓冲冲刷
 * - CLI（示例/队列消费者）下自动跳过头，只保证文本输出
 *
 * 帧格式：
 *   event: delta\n
 *   data: 文本\n      （多行 data 会拆成多行 data:）
 *   \n
 */
final class SseEmitter
{
    /** 拼一帧 SSE 文本；$event 为空串时省略 event 行（纯函数，便于测试） */
    public static function frame(string $event, string $data): string
    {
        $out = '';
        if ($event !== '') {
            $out .= "event: {$event}\n";
        }
        // 统一换行为 \n 后逐行 data:，避免数据内换行破坏帧边界
        $data = str_replace("\r\n", "\n", $data);
        foreach (explode("\n", $data) as $line) {
            $out .= "data: {$line}\n";
        }
        return $out . "\n";
    }

    /** 流开始：发 SSE 头并剥掉全部输出缓冲（FPM 层层 ob 会攒住流式输出） */
    public static function start(): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no'); // 提示 nginx 反代不要缓冲本响应
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
    }

    /** 发一帧并尽力冲刷到客户端 */
    public static function send(string $event, string $data): void
    {
        echo self::frame($event, $data);
        self::flush();
    }

    /** 冲刷所有输出缓冲层 + 内核级 flush（注意：ob_flush 只冲内容不弹层，须按层数有界循环） */
    public static function flush(): void
    {
        for ($i = ob_get_level(); $i > 0; $i--) {
            ob_flush();
        }
        flush();
    }

    /** 流结束：发 done 帧收尾（data 可携带会话 id / 用量等摘要） */
    public static function close(string $data = ''): void
    {
        self::send('done', $data);
    }
}
