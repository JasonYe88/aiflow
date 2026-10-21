<?php
declare(strict_types=1);

namespace AIFlow;

use AIFlow\Adapter\ArrayCache;
use AIFlow\Adapter\PdoStorage;
use AIFlow\Adapter\RedisCache;
use AIFlow\Contract\CacheInterface;
use AIFlow\Contract\StorageInterface;
use AIFlow\Support\Config;
use AIFlow\Support\Migrator;
use PDO;
use Redis;

/**
 * 框架唯一入口
 * AIKernel::boot($config) 引导内核，装配适配器
 *
 * 配置树：
 *   storage:   { dsn, user?, pass? }        PDO DSN，如 mysql:... 或 sqlite::memory:
 *   cache:     { driver: array|redis, host?, port?, db? }
 *   migration: { dir?, auto?: bool }         dir 缺省为包内 migrations/
 */
final class AIKernel
{
    /** @var Config */
    private $config;

    /** @var StorageInterface */
    private $storage;

    /** @var CacheInterface */
    private $cache;

    private function __construct(Config $config)
    {
        $this->config = $config;
        $this->storage = $this->makeStorage();
        $this->cache = $this->makeCache();
        if ($config->get('migration.auto', false)) {
            $this->migrate();
        }
    }

    public static function boot(array $config): self
    {
        return new self(new Config($config));
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function storage(): StorageInterface
    {
        return $this->storage;
    }

    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    /** 执行迁移，返回应用的迁移版本 */
    public function migrate(): array
    {
        return (new Migrator(
            $this->storage,
            (string)$this->config->get('migration.dir', dirname(__DIR__) . '/migrations')
        ))->migrate();
    }

    private function makeStorage(): StorageInterface
    {
        $dsn = (string)$this->config->get('storage.dsn');
        $pdo = new PDO(
            $dsn,
            (string)$this->config->get('storage.user'),
            (string)$this->config->get('storage.pass')
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return new PdoStorage($pdo);
    }

    private function makeCache(): CacheInterface
    {
        if ($this->config->get('cache.driver') === 'redis') {
            if (!class_exists(Redis::class)) {
                trigger_error(
                    'aiflow: ext-redis 未安装，cache 降级为 ArrayCache（进程内不共享，Swoole 场景慎用）',
                    E_USER_WARNING
                );
                return new ArrayCache();
            }
            $redis = new Redis();
            $redis->connect(
                (string)$this->config->get('cache.host', '127.0.0.1'),
                (int)$this->config->get('cache.port', 6379)
            );
            $redis->select((int)$this->config->get('cache.db', 0));
            return new RedisCache($redis);
        }
        return new ArrayCache();
    }
}