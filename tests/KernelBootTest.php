<?php
declare(strict_types=1);

namespace AIFlow\Tests;

use AIFlow\AIKernel;
use PHPUnit\Framework\TestCase;

class KernelBootTest extends TestCase
{
    public function testBootReturnsKernelWithConfig(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
        ]);

        $this->assertInstanceOf(AIKernel::class, $kernel);
        $this->assertSame(['dsn' => 'sqlite::memory:'], $kernel->config()->get('storage'));
        $this->assertSame('array', $kernel->config()->get('cache.driver'));
        $this->assertNull($kernel->config()->get('not_exists'));
    }

    public function testBootWiresStorageAndCache(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
        ]);

        $this->assertInstanceOf(
            \AIFlow\Contract\StorageInterface::class,
            $kernel->storage()
        );
        $this->assertInstanceOf(
            \AIFlow\Contract\CacheInterface::class,
            $kernel->cache()
        );
    }

    public function testBootWiresMigrator(): void
    {
        $kernel = AIKernel::boot([
            'storage'   => ['dsn' => 'sqlite::memory:'],
            'cache'     => ['driver' => 'array'],
            'migration' => ['dir' => dirname(__DIR__) . '/migrations', 'auto' => true],
        ]);

        $this->assertNotEmpty($kernel->storage()->query('SELECT version FROM ai_migration'));
    }

    public function testBootWiresGatewayWithModelConfig(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
            'models'  => [
                'deepseek' => [
                    'driver'   => 'openai',
                    'base_url' => 'https://api.deepseek.com',
                    'api_key'  => 'sk-test',
                    'model'    => 'deepseek-chat',
                ],
            ],
        ]);

        $gateway = $kernel->gateway();
        $this->assertInstanceOf(\AIFlow\Gateway\ModelGateway::class, $gateway);
        $this->assertSame(['deepseek'], $gateway->channels());
        $this->assertSame($gateway, $kernel->gateway()); // 惰性单例
    }

    public function testBootWiresChatService(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
        ]);

        $chat = $kernel->chat();
        $this->assertInstanceOf(\AIFlow\Chat\ChatService::class, $chat);
        $this->assertSame($chat, $kernel->chat()); // 惰性单例
    }
}
