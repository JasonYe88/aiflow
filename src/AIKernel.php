<?php
declare(strict_types=1);

namespace AIFlow;

use AIFlow\Support\Config;

/**
 * 框架唯一入口
 * AIKernel::boot($config) 引导内核，装配适配器
 */
final class AIKernel
{
    /** @var Config */
    private $config;

    private function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 引导内核
     *
     * @param array $config 配置树，见 README
     */
    public static function boot(array $config): self
    {
        return new self(new Config($config));
    }

    public function config(): Config
    {
        return $this->config;
    }
}
