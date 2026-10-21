<?php
declare(strict_types=1);

namespace AIFlow\Tests\Access;

use AIFlow\Access\AccessService;
use AIFlow\Access\FixedUserProvider;
use AIFlow\Adapter\PdoStorage;
use AIFlow\Exception\PermissionDeniedException;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class AccessServiceTest extends TestCase
{
    /** @var AccessService */
    private $access;

    /** @var FixedUserProvider */
    private $users;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        // roles_map：u1 → [user, restricted]（多角色并集）；未知用户兜底 ['user']
        $this->users = new FixedUserProvider(['u1' => ['user', 'restricted']], ['user']);
        $this->access = new AccessService($storage, $this->users);
    }

    /** 角色 allow → check 通过、guard 放行；资源 upsert 幂等 */
    public function testCheckPassesWithAllowGrant(): void
    {
        $role = $this->access->ensureRole('user', '用户');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $role);
        $this->assertSame($role, $this->access->ensureRole('user', '用户2')); // 幂等（名字不重复建）

        $res = $this->access->ensureResource('app', 'ai_chat_demo', 'AI 对话 demo');
        $this->assertSame($res, $this->access->ensureResource('app', 'ai_chat_demo'));

        $this->access->grant('user', 'app', 'ai_chat_demo');
        $this->assertTrue($this->access->check('u1', 'app:ai_chat_demo'));
        $this->assertNull($this->access->guard('u1', 'app:ai_chat_demo')); // 放行无异常
    }

    /** 不同资源互不影响：授权是精确到资源维度的 */
    public function testCheckFailsForUngrantedResource(): void
    {
        $this->access->ensureRole('user', '用户');
        $this->access->ensureResource('app', 'ai_chat_demo');
        $this->access->grant('user', 'app', 'ai_chat_demo');

        $this->assertFalse($this->access->check('u1', 'app:other_app'));
        $this->assertFalse($this->access->check('u1', 'workflow:ai_chat_demo')); // 类型不同也不行
    }

    /** deny 优先于 allow：任一角色 deny 即拒绝 */
    public function testDenyOverridesAllowAcrossRoles(): void
    {
        $this->access->ensureRole('user', '用户');
        $this->access->ensureRole('restricted', '受限');
        $this->access->ensureResource('app', 'ai_chat_demo');
        $this->access->grant('user', 'app', 'ai_chat_demo', 'allow');
        $this->access->grant('restricted', 'app', 'ai_chat_demo', 'deny');

        $this->assertFalse($this->access->check('u1', 'app:ai_chat_demo'));
    }

    /** 同一角色对同一资源重复授权 = 覆盖 effect（upsert），allow 被改 deny 后拒绝 */
    public function testGrantUpsertsEffect(): void
    {
        $this->access->ensureRole('user', '用户');
        $this->access->ensureResource('app', 'ai_chat_demo');
        $this->access->grant('user', 'app', 'ai_chat_demo', 'allow');
        $this->assertTrue($this->access->check('u1', 'app:ai_chat_demo'));

        $this->access->grant('user', 'app', 'ai_chat_demo', 'deny'); // 同授权点改判
        $this->assertFalse($this->access->check('u1', 'app:ai_chat_demo'));
    }

    /** 通配资源：`app:*` 授权对该类型任意资源生效；跨类型通配 `*:*` 生效于一切 */
    public function testWildcardGrants(): void
    {
        $this->access->ensureRole('user', '用户');
        $this->access->grant('user', 'app', '*', 'allow'); // auto-ensure 资源 app:*
        $this->assertTrue($this->access->check('u1', 'app:anything'));
        $this->assertFalse($this->access->check('u1', 'workflow:anything')); // 管不到别的类型

        $this->access->ensureRole('admin', '管理员');
        $this->access->ensureResource('*', '*', '超管通配');
        $this->access->grant('admin', '*', '*', 'allow');
        // admin 并未挂到 u1 → u1 依旧只在 app 域
        $this->assertFalse($this->access->check('u1', 'tool:pick'));
    }

    /** 超管模型：角色挂上 `*:*` 通配授权后全资源放行（扁平模型无隐式超管，一切靠显式授权） */
    public function testSuperAdminByExplicitWildcard(): void
    {
        $this->users = new FixedUserProvider(['boss' => ['admin']], ['user']);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $access = new AccessService($storage, $this->users);

        $access->ensureRole('admin', '管理员');
        // 未授权时 admin 也拒绝
        $this->assertFalse($access->check('boss', 'app:anything'));

        $access->ensureResource('*', '*', '超管通配');
        $access->grant('admin', '*', '*', 'allow');
        foreach (['app:x', 'workflow:y', 'tool:z'] as $res) {
            $this->assertTrue($access->check('boss', $res));
        }
    }

    /** 未挂任何角色 = 全拒绝；guard 抛 PermissionDeniedException 携带 userId/resource */
    public function testGuardThrowsWithContext(): void
    {
        $rolesNone = new FixedUserProvider([], []);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $access = new AccessService($storage, $rolesNone);

        $this->assertFalse($access->check('stranger', 'app:x'));
        try {
            $access->guard('stranger', 'app:x');
            $this->fail('应抛 PermissionDeniedException');
        } catch (PermissionDeniedException $e) {
            $this->assertSame('stranger', $e->userId);
            $this->assertSame('app:x', $e->resource);
        }
    }

    /** revoke：回收授权后回到拒绝；回收不存在的授权不报错 */
    public function testRevokeRemovesGrant(): void
    {
        $this->access->ensureRole('user', '用户');
        $this->access->ensureResource('app', 'ai_chat_demo');
        $this->access->grant('user', 'app', 'ai_chat_demo');
        $this->assertTrue($this->access->check('u1', 'app:ai_chat_demo'));

        $this->access->revoke('user', 'app', 'ai_chat_demo');
        $this->assertFalse($this->access->check('u1', 'app:ai_chat_demo'));

        $this->access->revoke('user', 'app', 'ai_chat_demo'); // 幂等
        $this->access->revoke('ghost-role', 'app', 'ai_chat_demo');
        $this->assertFalse($this->access->check('u1', 'app:ai_chat_demo'));
    }

    /** 资源标识格式：缺冒号抛 InvalidArgumentException（fail fast，防静默全拒） */
    public function testCheckRejectsMalformedResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->access->check('u1', 'no-colon-here');
    }

    /** FixedUserProvider：roles_map 精确匹配优先，default_roles 兜底 */
    public function testFixedUserProviderResolution(): void
    {
        $provider = new FixedUserProvider(['10' => ['vip'], 20 => ['svip']], ['user', 'guest']);

        $this->assertSame(['vip'], $provider->rolesOf(10));      // int userId 命中字符串键
        $this->assertSame(['svip'], $provider->rolesOf('20'));   // string userId 命中数字键
        $this->assertSame(['user', 'guest'], $provider->rolesOf('unknown')); // 兜底
    }
}