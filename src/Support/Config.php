<?php
declare(strict_types=1);

namespace AIFlow\Support;

/**
 * 配置只读包装
 * 支持点路径取值：get('cache.driver')
 */
final class Config
{
    /** @var array */
    private $items = [];

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    /**
     * 按点路径读取配置，未命中返回默认值
     *
     * @param string $path  点路径，如 "cache.driver"
     * @param mixed  $default 未命中时的默认值，默认 null
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
        $cur = $this->items;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return $default;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }
}
