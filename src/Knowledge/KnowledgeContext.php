<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

/**
 * 把检索命中格式化为可注入 LLM / Agent 的上下文文本
 */
final class KnowledgeContext
{
    /**
     * @param array<int, array{content?:string,title?:string,score?:float,document_id?:string}> $hits
     */
    public static function toText(array $hits, string $heading = '参考资料'): string
    {
        if ($hits === []) {
            return '';
        }
        $lines = [$heading . '：'];
        foreach ($hits as $i => $hit) {
            $n = $i + 1;
            $title = trim((string)($hit['title'] ?? ''));
            $score = isset($hit['score']) ? ' score=' . $hit['score'] : '';
            $head = $title !== '' ? "[{$n}] {$title}{$score}" : "[{$n}]{$score}";
            $lines[] = $head;
            $lines[] = trim((string)($hit['content'] ?? ''));
            $lines[] = '';
        }
        return rtrim(implode("\n", $lines));
    }

    /**
     * @param array<int, array> $hits
     * @return array{role:string,content:string}|null 无命中返回 null
     */
    public static function asSystemMessage(array $hits, string $heading = '参考资料'): ?array
    {
        $text = self::toText($hits, $heading);
        if ($text === '') {
            return null;
        }
        return ['role' => 'system', 'content' => $text];
    }

    /**
     * 将资料作为 system 消息插到 messages 最前（已有 system 则拼到其后一条之前）
     *
     * @param array<int, array{role:string,content?:string}> $messages
     * @param array<int, array> $hits
     * @return array<int, array>
     */
    public static function prependToMessages(array $messages, array $hits, string $heading = '参考资料'): array
    {
        $sys = self::asSystemMessage($hits, $heading);
        if ($sys === null) {
            return $messages;
        }
        $out = [];
        $inserted = false;
        foreach ($messages as $msg) {
            if (!$inserted && (($msg['role'] ?? '') !== 'system')) {
                $out[] = $sys;
                $inserted = true;
            }
            $out[] = $msg;
        }
        if (!$inserted) {
            $out[] = $sys;
        }
        return $out;
    }
}
