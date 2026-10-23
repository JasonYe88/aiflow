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

    public function testBootWiresCreditService(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
        ]);

        $credit = $kernel->credit();
        $this->assertInstanceOf(\AIFlow\Credit\CreditService::class, $credit);
        $this->assertSame($credit, $kernel->credit()); // 惰性单例
    }

    /** guard()：默认 FixedUserProvider（roles_map + default_roles 配置驱动） */
    public function testBootWiresGuardWithDefaultUserProvider(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
            'migration' => ['auto' => true],
            'access'  => [
                'roles_map'     => ['vip_user' => ['vip']],
                'default_roles' => ['user'],
            ],
        ]);

        $guard = $kernel->guard();
        $this->assertInstanceOf(\AIFlow\Access\AccessService::class, $guard);
        $this->assertSame($guard, $kernel->guard()); // 惰性单例

        // 授权后的资源放行、未授权拒绝（Progressive：FixedUserProvider 兜底 ['user']）
        $guard->grant('user', 'app', 'demo');
        $this->assertTrue($guard->check('anyone', 'app:demo'));
        $this->assertNull($guard->guard('anyone', 'app:demo'));
        try {
            $guard->guard('anyone', 'workflow:demo');
            $this->fail('应抛 PermissionDeniedException');
        } catch (\AIFlow\Exception\PermissionDeniedException $e) {
            $this->assertSame('workflow:demo', $e->resource);
        }
    }

    /** guard()：宿主注入自定义 UserProviderInterface 实例（M3+ 接真实登录态的路径） */
    public function testBootWiresGuardWithCustomUserProvider(): void
    {
        $provider = new class implements \AIFlow\Contract\UserProviderInterface {
            public function rolesOf($userId): array
            {
                return $userId === 'boss' ? ['admin'] : [];
            }
        };

        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
            'migration' => ['auto' => true],
            'access'  => ['user_provider' => $provider],
        ]);

        $guard = $kernel->guard();
        $guard->grant('admin', 'app', 'console');
        $this->assertTrue($guard->check('boss', 'app:console'));
        $this->assertFalse($guard->check('someone-else', 'app:console')); // 未挂角色全拒绝
    }

    public function testBootWiresAgentRuntime(): void
    {
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
        ]);

        $agent = $kernel->agent();
        $this->assertInstanceOf(\AIFlow\Agent\AgentRuntime::class, $agent);
        $this->assertSame($agent, $kernel->agent()); // 惰性单例
        $this->assertSame([], $agent->toolNames());  // 裸内核：零工具，宿主按需注册
    }

    public function testBootWiresKnowledgeService(): void
    {
        $kernel = AIKernel::boot([
            'storage'   => ['dsn' => 'sqlite::memory:'],
            'cache'     => ['driver' => 'array'],
            'migration' => ['auto' => true],
        ]);

        $kb = $kernel->knowledge();
        $this->assertInstanceOf(\AIFlow\Knowledge\KnowledgeService::class, $kb);
        $this->assertSame($kb, $kernel->knowledge());
        $baseId = $kb->createBase('boot.kb', 'Kernel接线', 'u1');
        $this->assertNotSame('', $baseId);
        $this->assertInstanceOf(
            \AIFlow\Knowledge\SimpleKeywordRetriever::class,
            $kb->retriever()
        );
    }

    public function testBootWiresHttpRagRetrieverWhenConfigured(): void
    {
        $fake = new \AIFlow\Tests\Support\FakeHttpClient();
        $kernel = AIKernel::boot([
            'storage' => ['dsn' => 'sqlite::memory:'],
            'cache'   => ['driver' => 'array'],
            'knowledge' => [
                'retriever' => 'http',
                'rag_url'   => 'http://rag.test',
                'http'      => $fake,
            ],
        ]);
        $this->assertInstanceOf(
            \AIFlow\Knowledge\HttpRagRetriever::class,
            $kernel->knowledge()->retriever()
        );
    }
}
