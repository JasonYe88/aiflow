<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

/**
 * 文本分段（对齐 Dify/Gaia「通用」模式最小集）
 *
 * 选项：separator / max_length / overlap / clean_whitespace / strip_urls
 * 父子分段、Q&A 分段后置。
 */
final class TextChunker
{
    /**
     * @param array{
     *   separator?:string,
     *   max_length?:int,
     *   overlap?:int,
     *   clean_whitespace?:bool,
     *   strip_urls?:bool
     * } $opt
     * @return string[]
     */
    public function split(string $text, array $opt = []): array
    {
        $separator = array_key_exists('separator', $opt) ? (string)$opt['separator'] : "\n\n";
        if ($separator === '') {
            $separator = "\n\n";
        }
        $max = max(32, (int)($opt['max_length'] ?? 500));
        $overlap = max(0, min($max - 1, (int)($opt['overlap'] ?? 50)));
        $cleanWs = !isset($opt['clean_whitespace']) || (bool)$opt['clean_whitespace'];
        $stripUrls = !empty($opt['strip_urls']);

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($cleanWs) {
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
            $text = preg_replace("/[ \t\f\x{00A0}]+/u", ' ', $text) ?? $text;
        }
        if ($stripUrls) {
            $text = preg_replace('~https?://\S+|www\.\S+|\b[\w.+-]+@[\w.-]+\.\w+\b~u', ' ', $text) ?? $text;
        }
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('入库文本不能为空');
        }

        $parts = $this->explodeBySeparator($text, $separator);
        $chunks = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (mb_strlen($part) <= $max) {
                $chunks[] = $part;
                continue;
            }
            foreach ($this->windowSlice($part, $max, $overlap) as $slice) {
                $chunks[] = $slice;
            }
        }
        return $chunks === [] ? [$text] : $chunks;
    }

    /** @return string[] */
    private function explodeBySeparator(string $text, string $separator): array
    {
        // 字面 \n / \n\n（表单常传来转义串）
        $sep = str_replace(['\\n', '\\t'], ["\n", "\t"], $separator);
        if ($sep === "\n\n") {
            return preg_split("/\n{2,}/", $text) ?: [$text];
        }
        if ($sep === "\n") {
            return preg_split("/\n+/", $text) ?: [$text];
        }
        if ($sep === '') {
            return [$text];
        }
        return explode($sep, $text);
    }

    /** @return string[] */
    private function windowSlice(string $part, int $max, int $overlap): array
    {
        $out = [];
        $len = mb_strlen($part);
        $step = max(1, $max - $overlap);
        for ($i = 0; $i < $len; $i += $step) {
            $slice = mb_substr($part, $i, $max);
            if ($slice === '') {
                break;
            }
            $out[] = $slice;
            if ($i + $max >= $len) {
                break;
            }
        }
        return $out;
    }
}
