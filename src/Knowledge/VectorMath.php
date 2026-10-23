<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

/**
 * 向量工具：余弦相似度（纯 PHP，无扩展依赖）
 */
final class VectorMath
{
    /**
     * @param float[] $a
     * @param float[] $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float)$a[$i];
            $y = (float)$b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * @param float[] $vector
     */
    public static function encode(array $vector): string
    {
        $json = json_encode(array_values($vector));
        if ($json === false) {
            throw new \InvalidArgumentException('向量 JSON 编码失败');
        }
        return $json;
    }

    /**
     * @return float[]
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || $data === []) {
            return [];
        }
        $out = [];
        foreach ($data as $v) {
            $out[] = (float)$v;
        }
        return $out;
    }
}
