# AIFlow M0（内核骨架）实施计划

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 从零建立 `aiflow` 独立仓库，完成 M0：Kernel 引导骨架 + Contract 接口 + MySQL/Redis 适配器 + 建表迁移，`AIKernel::boot()` 可跑通、CI 绿。

**Architecture:** 单 Composer 库包，PSR-4 自动加载，`AIKernel::boot(array $config)` 为唯一入口；所有外部依赖（存储/缓存/用户体系）通过 `Contract/` 接口抽象，默认适配器基于 PDO 与 Redis 扩展。测试使用 SQLite 内存库，保证 `composer test` 一条命令可跑。

**Tech Stack:** PHP 7.4+（严禁 8.x 专属语法）、PHPUnit 9.6、PDO（MySQL/SQLite）、phpredis（可选）、GitHub Actions。

**设计依据:** `docs/plans/2026-10-21-aiflow-llmops-framework-design.md`（先读它）

**关键实现决策（M0 范围内）:**

1. **主键统一为框架生成的 32 位十六进制字符串**（`Support\Id::gen()`），不用数据库 AUTO_INCREMENT——使迁移 SQL 在 MySQL 与 SQLite（测试）之间完全可移植。
2. **迁移 SQL 用 ANSI 可移植写法**：不用 `ENGINE=`、不用内联 `KEY idx`（MySQL 专属），二级索引统一 `CREATE INDEX` 语句（两边通用）。
3. **金额/余额一律存整数（分）**，token 数量为 BIGINT。
4. **PHP 7.4 兼容红线**：不用 enum、readonly、named arguments、match、union types、constructor promotion；类型化属性可用。

**环境约定:**

- 仓库位置：`D:\phpstudy_pro\WWW\aiflow`（与 fms 同级的独立仓库，**不是** fms 子目录）
- Windows PowerShell：命令连接用 `;` 不用 `&&`
- 若 `php` 不在 PATH，用 phpstudy 的完整路径（如 `D:\phpstudy_pro\Extensions\php\php7.4.x\php.exe`，版本以实际为准，先执行 `php -v` 验证 ≥7.4）
- 所有 `git commit` 在 `D:\phpstudy_pro\WWW\aiflow` 下执行

---

### Task 1: 初始化仓库与 Composer 骨架

**Files:**
- Create: `D:\phpstudy_pro\WWW\aiflow\composer.json`
- Create: `D:\phpstudy_pro\WWW\aiflow\phpunit.xml`
- Create: `D:\phpstudy_pro\WWW\aiflow\.gitignore`
- Create: `D:\phpstudy_pro\WWW\aiflow\LICENSE`（Apache 2.0 全文，从 https://www.apache.org/licenses/LICENSE-2.0.txt 取）
- Create: `D:\phpstudy_pro\WWW\aiflow\README.md`（骨架）

**Step 1: 建目录并初始化 git**

```powershell
mkdir D:\phpstudy_pro\WWW\aiflow
cd D:\phpstudy_pro\WWW\aiflow
git init
```

**Step 2: 写 `composer.json`**

```json
{
    "name": "jasonye88/aiflow",
    "description": "可嵌入 PHP 项目的 LLMOps 内核：权限、积分、对话、Agent、工作流",
    "type": "library",
    "license": "Apache-2.0",
    "keywords": ["llm", "llmops", "agent", "workflow", "dify", "chatbot", "credits"],
    "require": {
        "php": ">=7.4",
        "ext-pdo": "*",
        "ext-json": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6"
    },
    "autoload": {
        "psr-4": { "AIFlow\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "AIFlow\\Tests\\": "tests/" }
    },
    "scripts": {
        "test": "phpunit --colors=always"
    }
}
```

**Step 3: 写 `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**Step 4: 写 `.gitignore`**

```
/vendor/
/composer.lock
.phpunit.result.cache
.idea/
.vscode/
*.log
```

**Step 5: 写 `README.md` 骨架**

```markdown
# AIFlow

可嵌入 PHP 项目的 LLMOps 内核：权限、积分、对话、Agent、工作流。

> 状态：M0 开发中（内核骨架），未发布。设计文档见 docs/plans/。

## 环境要求

- PHP >= 7.4
- ext-pdo、ext-json；可选：ext-redis、Swoole

## 开发

composer install
composer test
```

**Step 6: 安装依赖并验证**

Run: `composer install`
Expected: 生成 `vendor/`，无错误。

Run: `composer validate`
Expected: `./composer.json is valid`

**Step 7: Commit**

```powershell
git add -A; git commit -m "chore: init repo with composer skeleton, phpunit, apache2 license"
```

---

### Task 2: 自动加载冒烟测试（第一个红灯）

**Files:**
- Create: `tests/SmokeTest.php`
- Create: `src/Kernel.php`（先建空类让红灯具体化）

**Step 1: 写失败测试**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests;

use AIFlow\AIKernel;
use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    public function testKernelClassExists(): void
    {
        $this->assertTrue(class_exists(AIKernel::class));
    }
}
```

**Step 2: 跑测试确认失败**

Run: `composer test`
Expected: FAIL——`Error: Class "AIFlow\AIKernel" not found`（自动加载通了但类不存在，这是我们要的红灯）

**Step 3: 建最小类**

```php
<?php
declare(strict_types=1);

namespace AIFlow;

final class AIKernel
{
}
```

**Step 4: 跑测试确认通过**

Run: `composer test`
Expected: PASS（1 test, 1 assertion）

**Step 5: Commit**

```powershell
git add -A; git commit -m "feat: kernel class with autoload smoke test"
```

---

### Task 3: Kernel::boot + Config（TDD）

**Files:**
- Create: `src/Support/Config.php`
- Modify: `src/Kernel.php`
- Test: `tests/KernelBootTest.php`

**Step 1: 写失败测试**

```php
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
}
```

**Step 2: 跑测试确认失败**

Run: `composer test`
Expected: FAIL——`Class "AIFlow\Support\Config" not found` 或方法不存在

**Step 3: 实现 Config 与 boot**

`src/Support/Config.php`：

```php
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
```

`src/Kernel.php`（覆盖）：

```php
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
```

**Step 4: 跑测试确认通过**

Run: `composer test`
Expected: PASS（2 tests）

**Step 5: Commit**

```powershell
git add -A; git commit -m "feat: kernel boot with dot-path config"
```

---

### Task 4: Contract 接口 + Storage 契约测试套件

**Files:**
- Create: `src/Contract/StorageInterface.php`
- Create: `src/Contract/CacheInterface.php`
- Create: `src/Contract/UserProviderInterface.php`
- Create: `tests/Contract/StorageContractTest.php`

**Step 1: 定义三个接口（机械任务，无 TDD）**

`src/Contract/StorageInterface.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Contract;

/**
 * 存储适配器契约：内核所有表读写走此接口
 * 换存储实现须通过 tests/Contract/StorageContractTest.php 契约测试
 */
interface StorageInterface
{
    /**
     * 执行写语句（INSERT/UPDATE/DELETE/DDL）
     *
     * @param string $sql    SQL 语句，占位符用 ?
     * @param array  $params 绑定参数
     * @return int 影响行数
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * 执行读语句，返回关联数组列表
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array;

    /**
     * 事务包裹：$fn 内抛异常则回滚并重新抛出
     *
     * @param callable $fn function(StorageInterface $s): mixed
     * @return mixed $fn 的返回值
     */
    public function transaction(callable $fn);
}
```

`src/Contract/CacheInterface.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Contract;

/**
 * 缓存适配器契约（M0 用于迁移版本表加速，M1+ 用于会话热点缓存）
 */
interface CacheInterface
{
    /** @return string|null 命中返回字符串，未命中返回 null */
    public function get(string $key);

    /** @param int $ttl 过期秒数，0 表示永不过期 */
    public function set(string $key, string $value, int $ttl = 0): bool;

    public function delete(string $key): bool;
}
```

`src/Contract/UserProviderInterface.php`：

```php
declare(strict_types=1);

namespace AIFlow\Contract;

/**
 * 宿主用户体系适配器：把宿主用户 ID 映射为框架角色
 * FMS 等宿主项目实现此接口后注入内核（M2 AccessControl 消费，M0 先立契约）
 */
interface UserProviderInterface
{
    /**
     * 查询用户挂载的角色标识列表
     *
     * @param mixed $userId 宿主项目的用户 ID（int 或 string 均可能）
     * @return string[] 角色标识，如 ["admin"]
     */
    public function rolesOf($userId): array;
}
```

**Step 2: 写 Storage 契约测试套件（抽象基类，供各实现继承）**

`tests/Contract/StorageContractTest.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Contract;

use AIFlow\Contract\StorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * Storage 契约测试套件
 * 任何 StorageInterface 实现继承本类并实现 makeStorage() 即可接受契约验证
 */
abstract class StorageContractTest extends TestCase
{
    /** @var StorageInterface */
    protected $storage;

    /** 各实现返回全新实例，测试前会执行建表 */
    abstract protected function makeStorage(): StorageInterface;

    protected function setUp(): void
    {
        $this->storage = $this->makeStorage();
        $this->storage->execute(
            'CREATE TABLE contract_t (id VARCHAR(32) PRIMARY KEY, num INT NOT NULL, val TEXT)'
        );
    }

    public function testExecuteAndQuery(): void
    {
        $n = $this->storage->execute(
            'INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)',
            ['id001', 1, 'hello']
        );
        $this->assertSame(1, $n);

        $rows = $this->storage->query('SELECT * FROM contract_t WHERE id = ?', ['id001']);
        $this->assertCount(1, $rows);
        $this->assertSame('hello', $rows[0]['val']);
    }

    public function testUpdateAffectsRows(): void
    {
        $this->storage->execute('INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)', ['a', 1, 'x']);
        $n = $this->storage->execute('UPDATE contract_t SET val = ? WHERE num = ?', ['y', 1]);
        $this->assertSame(1, $n);
        $this->assertSame('y', $this->storage->query('SELECT val FROM contract_t WHERE id = ?', ['a'])[0]['val']);
    }

    public function testTransactionCommits(): void
    {
        $this->storage->transaction(function (StorageInterface $s) {
            $s->execute('INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)', ['b', 2, 'ok']);
        });
        $this->assertCount(1, $this->storage->query('SELECT * FROM contract_t WHERE id = ?', ['b']));
    }

    public function testTransactionRollsBackOnThrow(): void
    {
        try {
            $this->storage->transaction(function (StorageInterface $s) {
                $s->execute('INSERT INTO contract_t (id, num, val) VALUES (?, ?, ?)', ['c', 3, 'no']);
                throw new \RuntimeException('boom');
            });
            $this->fail('异常应被重新抛出');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertCount(0, $this->storage->query('SELECT * FROM contract_t WHERE id = ?', ['c']));
    }
}
```

**Step 3: 确认套件本身是"绿灯守卫"**

Run: `composer test`
Expected: PASS（契约套件无实现类继承时不会被执行，现有测试不变绿即可）

**Step 4: Commit**

```powershell
git add -A; git commit -m "feat: storage/cache/user-provider contracts with storage contract test suite"
```

---

### Task 5: PdoStorage 适配器（TDD 过契约）

**Files:**
- Create: `src/Adapter/PdoStorage.php`
- Test: `tests/Adapter/PdoStorageTest.php`

**Step 1: 写失败测试（继承契约套件）**

`tests/Adapter/PdoStorageTest.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Adapter;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Tests\Contract\StorageContractTest;

class PdoStorageTest extends StorageContractTest
{
    protected function makeStorage(): \AIFlow\Contract\StorageInterface
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new PdoStorage($pdo);
    }
}
```

**Step 2: 跑测试确认失败**

Run: `composer test`
Expected: FAIL——`Class "AIFlow\Adapter\PdoStorage" not found`

**Step 3: 实现 PdoStorage**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Adapter;

use AIFlow\Contract\StorageInterface;
use PDO;

/**
 * 基于 PDO 的存储适配器（MySQL / SQLite 通吃）
 * 通过 tests/Adapter/PdoStorageTest.php 接受契约验证
 */
final class PdoStorage implements StorageInterface
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
```

**Step 4: 跑测试确认通过**

Run: `composer test`
Expected: PASS（契约 4 条 + 之前全部，约 7 tests）

**Step 5: Commit**

```powershell
git add -A; git commit -m "feat: pdo storage adapter passing contract suite"
```

---

### Task 6: Id 生成器 + 缓存适配器（ArrayCache TDD + RedisCache）

**Files:**
- Create: `src/Support/Id.php`
- Create: `src/Adapter/ArrayCache.php`
- Create: `src/Adapter/RedisCache.php`
- Test: `tests/Support/IdTest.php`
- Test: `tests/Adapter/ArrayCacheTest.php`
- Test: `tests/Adapter/RedisCacheTest.php`

**Step 1: 写失败测试**

`tests/Support/IdTest.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Support;

use AIFlow\Support\Id;
use PHPUnit\Framework\TestCase;

class IdTest extends TestCase
{
    public function testGenReturns32HexChars(): void
    {
        $id = Id::gen();
        $this->assertSame(32, strlen($id));
        $this->assertRegExp('/^[0-9a-f]{32}$/', $id);
    }

    public function testGenUnique(): void
    {
        $this->assertNotSame(Id::gen(), Id::gen());
    }
}
```

`tests/Adapter/ArrayCacheTest.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Adapter;

use AIFlow\Adapter\ArrayCache;
use PHPUnit\Framework\TestCase;

class ArrayCacheTest extends TestCase
{
    /** @var ArrayCache */
    private $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    public function testGetSetDelete(): void
    {
        $this->assertNull($this->cache->get('k'));
        $this->assertTrue($this->cache->set('k', 'v'));
        $this->assertSame('v', $this->cache->get('k'));
        $this->assertTrue($this->cache->delete('k'));
        $this->assertNull($this->cache->get('k'));
    }

    public function testTtlExpires(): void
    {
        $this->cache->set('k', 'v', 1);
        $this->cache->rewind(2); // 测试钩子：把时钟拨快 2 秒
        $this->assertNull($this->cache->get('k'));
    }
}
```

`tests/Adapter/RedisCacheTest.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Adapter;

use AIFlow\Adapter\RedisCache;
use PHPUnit\Framework\TestCase;

class RedisCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis 未安装');
        }
    }

    public function testGetSetDelete(): void
    {
        $redis = new \Redis();
        $this->assertTrue(
            $redis->connect('127.0.0.1', 6379),
            '本机 Redis 不可用，跳过前请确认 phpstudy Redis 已启动'
        );
        $redis->select(15); // 测试专用库，避免污染

        $cache = new RedisCache($redis);
        $cache->delete('aiflow:test:k');
        $this->assertNull($cache->get('aiflow:test:k'));
        $this->assertTrue($cache->set('aiflow:test:k', 'v'));
        $this->assertSame('v', $cache->get('aiflow:test:k'));
        $this->assertTrue($cache->delete('aiflow:test:k'));
        $this->assertNull($cache->get('aiflow:test:k'));
    }
}
```

**Step 2: 跑测试确认失败**

Run: `composer test`
Expected: FAIL——`Id` / `ArrayCache` / `RedisCache` 类不存在（RedisCacheTest 可能 skipped，正常）

**Step 3: 实现三个类**

`src/Support/Id.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Support;

/**
 * 32 位十六进制主键生成器
 * 前 12 位为毫秒时间戳十六进制（趋势递增、可肉眼读时间），后 20 位随机
 * 为什么不用 AUTO_INCREMENT：使建表 SQL 在 MySQL 与 SQLite 之间可移植
 */
final class Id
{
    public static function gen(): string
    {
        $ts = dechex((int)(microtime(true) * 1000));
        $rand = bin2hex(random_bytes(10));
        return str_pad($ts, 12, '0', STR_PAD_LEFT) . $rand;
    }
}
```

`src/Adapter/ArrayCache.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Adapter;

use AIFlow\Contract\CacheInterface;

/**
 * 进程内数组缓存：默认实现与测试用；FPM 每请求即弃，Swoole 常驻需换 RedisCache
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{0: string, 1: int}> [value, expireAt]，expireAt=0 表示不过期 */
    private $store = [];

    /** @var int 可拨动的时钟偏移（秒），仅测试用 */
    private $clockOffset = 0;

    public function get(string $key)
    {
        if (!isset($this->store[$key])) {
            return null;
        }
        list($value, $expireAt) = $this->store[$key];
        if ($expireAt > 0 && $this->now() >= $expireAt) {
            unset($this->store[$key]);
            return null;
        }
        return $value;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->store[$key] = [$value, $ttl > 0 ? $this->now() + $ttl : 0];
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    /** 测试钩子：把逻辑时钟拨快 $seconds 秒 */
    public function rewind(int $seconds): void
    {
        $this->clockOffset += $seconds;
    }

    private function now(): int
    {
        return time() + $this->clockOffset;
    }
}
```

`src/Adapter/RedisCache.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Adapter;

use AIFlow\Contract\CacheInterface;
use Redis;

/**
 * 基于 phpredis 的缓存适配器；未装扩展时内核自动降级 ArrayCache
 */
final class RedisCache implements CacheInterface
{
    /** @var Redis */
    private $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function get(string $key)
    {
        $value = $this->redis->get($key);
        return $value === false ? null : (string)$value;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return $ttl > 0 ? $this->redis->setex($key, $ttl, $value)
                        : $this->redis->set($key, $value);
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($key) >= 0;
    }
}
```

**Step 4: 跑测试确认通过**

Run: `composer test`
Expected: PASS（RedisCacheTest 若本机无 Redis/扩展则 skipped，其余全绿）

**Step 5: Commit**

```powershell
git add -A; git commit -m "feat: hex id generator, array/redis cache adapters"
```

---

### Task 7: Migrator + 001_init.sql 建表迁移（TDD）

**Files:**
- Create: `src/Support/Migrator.php`
- Create: `migrations/001_init.sql`
- Test: `tests/Support/MigratorTest.php`

**Step 1: 写失败测试**

`tests/Support/MigratorTest.php`：

```php
<?php
declare(strict_types=1);

namespace AIFlow\Tests\Support;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class MigratorTest extends TestCase
{
    /** @var PdoStorage */
    private $storage;

    /** @var string */
    private $migrationsDir;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoStorage($pdo);
        $this->migrationsDir = dirname(__DIR__, 2) . '/migrations';
    }

    public function testMigrateAppliesAllAndIsIdempotent(): void
    {
        $migrator = new Migrator($this->storage, $this->migrationsDir);

        $applied = $migrator->migrate();
        $this->assertContains('001_init.sql', $applied);

        // 幂等：再跑一遍不重复执行
        $this->assertSame([], $migrator->migrate());

        // 版本表已记录
        $versions = $this->storage->query('SELECT version FROM ai_migration');
        $this->assertNotEmpty($versions);

        // 10 张业务表全部存在（10 张 + 版本表）
        $tables = array_column(
            $this->storage->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%'"
            ),
            'name'
        );
        $this->assertCount(11, $tables);
        foreach (['ai_role', 'ai_resource', 'ai_role_resource', 'ai_wallet', 'ai_price_rule',
                  'ai_ledger_entry', 'ai_conversation', 'ai_message',
                  'ai_workflow_run', 'ai_workflow_run_log'] as $table) {
            $this->assertContains($table, $tables);
        }
    }
}
```

**Step 2: 跑测试确认失败**

Run: `composer test`
Expected: FAIL——`Class "AIFlow\Support\Migrator" not found`

**Step 3: 写 `migrations/001_init.sql`（ANSI 可移植，MySQL/SQLite 通吃）**

```sql
-- AIFlow 初始结构：10 张核心表
-- 约定：主键为框架生成的 32 位十六进制字符串；金额/余额单位为分；token 数量为 BIGINT
-- 可移植性：不用 AUTO_INCREMENT / ENGINE / 内联 KEY；二级索引统一 CREATE INDEX

CREATE TABLE ai_role (
    id         VARCHAR(32)  NOT NULL PRIMARY KEY,
    code       VARCHAR(64)  NOT NULL,
    name       VARCHAR(128) NOT NULL,
    created_at DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_role_code ON ai_role (code);

CREATE TABLE ai_resource (
    id         VARCHAR(32)  NOT NULL PRIMARY KEY,
    type       VARCHAR(16)  NOT NULL, -- app | workflow | tool
    identifier VARCHAR(128) NOT NULL,
    name       VARCHAR(128) NOT NULL,
    created_at DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uk_ai_resource_ident ON ai_resource (type, identifier);

CREATE TABLE ai_role_resource (
    id          VARCHAR(32) NOT NULL PRIMARY KEY,
    role_id     VARCHAR(32) NOT NULL,
    resource_id VARCHAR(32) NOT NULL,
    effect      VARCHAR(8)  NOT NULL, -- allow | deny，deny 优先
    created_at  DATETIME    NOT NULL
);
CREATE UNIQUE INDEX uk_ai_role_res ON ai_role_resource (role_id, resource_id);

CREATE TABLE ai_wallet (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    owner_type VARCHAR(16) NOT NULL, -- user | dept | app
    owner_id   VARCHAR(32) NOT NULL,
    balance    BIGINT      NOT NULL DEFAULT 0, -- 余额（分）
    frozen     BIGINT      NOT NULL DEFAULT 0, -- 预检冻结（分）
    created_at DATETIME    NOT NULL,
    updated_at DATETIME    NOT NULL
);
CREATE UNIQUE INDEX uk_ai_wallet_owner ON ai_wallet (owner_type, owner_id);

CREATE TABLE ai_price_rule (
    id                VARCHAR(32)   NOT NULL PRIMARY KEY,
    model             VARCHAR(64)   NOT NULL,
    input_price_per_m  BIGINT       NOT NULL, -- 每百万 input token 单价（分）
    output_price_per_m BIGINT       NOT NULL, -- 每百万 output token 单价（分）
    multiplier        DECIMAL(10,4)  NOT NULL DEFAULT 1.0000,
    created_at        DATETIME      NOT NULL
);
CREATE UNIQUE INDEX uk_ai_price_model ON ai_price_rule (model);

CREATE TABLE ai_ledger_entry (
    id            VARCHAR(32) NOT NULL PRIMARY KEY,
    wallet_id     VARCHAR(32) NOT NULL,
    model         VARCHAR(64) NOT NULL,
    input_tokens  BIGINT      NOT NULL DEFAULT 0,
    output_tokens BIGINT      NOT NULL DEFAULT 0,
    amount        BIGINT      NOT NULL, -- 正=扣费，负=冲正
    kind          VARCHAR(8)  NOT NULL, -- reserve | settle | refund
    ref_id        VARCHAR(64) NOT NULL, -- 调用链 ID，幂等键
    created_at    DATETIME    NOT NULL
);
CREATE INDEX idx_ai_ledger_wallet ON ai_ledger_entry (wallet_id);
CREATE INDEX idx_ai_ledger_ref    ON ai_ledger_entry (ref_id);

CREATE TABLE ai_conversation (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    user_id    VARCHAR(32) NOT NULL,
    app_id     VARCHAR(32) NOT NULL,
    title      VARCHAR(255),
    created_at DATETIME    NOT NULL,
    updated_at DATETIME    NOT NULL
);
CREATE INDEX idx_ai_conv_user ON ai_conversation (user_id);

CREATE TABLE ai_message (
    id              VARCHAR(32) NOT NULL PRIMARY KEY,
    conversation_id VARCHAR(32) NOT NULL,
    role            VARCHAR(16) NOT NULL, -- user | assistant | system | tool
    content         MEDIUMTEXT,
    input_tokens    BIGINT      NOT NULL DEFAULT 0,
    output_tokens   BIGINT      NOT NULL DEFAULT 0,
    elapsed_ms      BIGINT      NOT NULL DEFAULT 0,
    created_at      DATETIME    NOT NULL
);
CREATE INDEX idx_ai_msg_conv ON ai_message (conversation_id);

CREATE TABLE ai_workflow_run (
    id          VARCHAR(32) NOT NULL PRIMARY KEY,
    workflow_id VARCHAR(64)  NOT NULL, -- 工作流标识
    status      VARCHAR(16)  NOT NULL, -- running | success | failed
    context     TEXT,                  -- 运行上下文 JSON
    ref_id      VARCHAR(64),
    started_at  DATETIME    NOT NULL,
    finished_at DATETIME
);
CREATE INDEX idx_ai_run_wf ON ai_workflow_run (workflow_id);

CREATE TABLE ai_workflow_run_log (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    run_id     VARCHAR(32) NOT NULL,
    node_key   VARCHAR(64)  NOT NULL,
    node_index INT          NOT NULL,
    status     VARCHAR(16)  NOT NULL, -- running | success | failed | skipped
    input      MEDIUMTEXT,
    output     MEDIUMTEXT,
    created_at DATETIME     NOT NULL
);
CREATE INDEX idx_ai_runlog_run ON ai_workflow_run_log (run_id);
```

**Step 4: 实现 Migrator**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Support;

use AIFlow\Contract\StorageInterface;

/**
 * 极简迁移器：按文件名顺序执行 migrations/*.sql，已执行的记入 ai_migration
 * SQL 须为 MySQL/SQLite 双兼容（设计决策见计划文档）
 */
final class Migrator
{
    /** @var StorageInterface */
    private $storage;

    /** @var string */
    private $dir;

    public function __construct(StorageInterface $storage, string $dir)
    {
        $this->storage = $storage;
        $this->dir = rtrim($dir, '/\\');
    }

    /**
     * 执行未应用的迁移
     *
     * @return string[] 本次应用的迁移文件名（已全部应用过则返回空数组）
     */
    public function migrate(): array
    {
        $this->storage->execute(
            'CREATE TABLE IF NOT EXISTS ai_migration (
                version VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            )'
        );

        $done = array_column(
            $this->storage->query('SELECT version FROM ai_migration'),
            'version'
        );

        $applied = [];
        foreach ($this->sqlFiles() as $file) {
            $version = basename($file);
            if (in_array($version, $done, true)) {
                continue;
            }
            $this->storage->transaction(function (StorageInterface $s) use ($file, $version) {
                foreach ($this->statements(file_get_contents($file)) as $sql) {
                    $s->execute($sql);
                }
                $s->execute(
                    'INSERT INTO ai_migration (version, applied_at) VALUES (?, ?)',
                    [$version, date('Y-m-d H:i:s')]
                );
            });
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return string[] 按文件名排序的 SQL 文件绝对路径 */
    private function sqlFiles(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files);
        return $files;
    }

    /** 把 SQL 文本拆成单条语句（按分号换行边界切，已剔除注释） */
    private function statements(string $sql): array
    {
        $lines = preg_split('/\R/u', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '-') {
                continue; // 跳过空行与注释
            }
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);
        return array_filter(array_map('trim', explode(';', $sql)));
    }
}
```

**Step 5: 跑测试确认通过**

Run: `composer test`
Expected: PASS（新增 1 test，11 张表断言通过）

**Step 6: Commit**

```powershell
git add -A; git commit -m "feat: migrator with portable ansi sql, 10 core tables in 001_init"
```

---

### Task 8: Kernel 装配 storage/cache + 示例脚本（TDD）

**Files:**
- Modify: `src/Kernel.php`
- Create: `examples/boot.php`

**Step 1: 写失败测试（追加到 `tests/KernelBootTest.php`）**

```php
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
```

**Step 2: 跑测试确认失败**

Run: `composer test`
Expected: FAIL——`storage()` 方法不存在

**Step 3: 扩展 Kernel（装配逻辑）**

```php
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
        if ($this->config->get('cache.driver') === 'redis' && class_exists(Redis::class)) {
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
```

注意：`RedisCacheTest` 的 `select(15)` 与此处 `select(0)` 不同属正常——生产默认 0 库，测试用 15 库。

**Step 4: 跑测试确认通过**

Run: `composer test`
Expected: PASS（全部测试约 11 tests）

**Step 5: 写 `examples/boot.php`（示例即冒烟）**

```php
<?php
declare(strict_types=1);

/**
 * M0 冒烟示例：boot 内核 + 迁移 + 清点表数量
 * 运行：php examples/boot.php
 */

require __DIR__ . '/../vendor/autoload.php';

use AIFlow\AIKernel;

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'sqlite:' . sys_get_temp_dir() . '/aiflow_m0_demo.sqlite'],
    'cache'     => ['driver' => 'array'],
    'migration' => ['auto' => true],
]);

$tables = $kernel->storage()->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%'"
);
$versions = $kernel->storage()->query('SELECT version FROM ai_migration');

echo "AIFlow M0 boot OK\n";
echo 'ai_* tables: ' . count($tables) . " (含 ai_migration)\n";
echo 'applied migrations: ' . implode(', ', array_column($versions, 'version')) . "\n";
```

Run: `php examples/boot.php`
Expected: 输出 `AIFlow M0 boot OK`、`ai_* tables: 11`、`applied migrations: 001_init.sql`

**Step 6: Commit**

```powershell
git add -A; git commit -m "feat: kernel wiring storage/cache/migrator with boot example"
```

---

### Task 9: CI 工作流 + README 快速开始

**Files:**
- Create: `.github/workflows/ci.yml`
- Modify: `README.md`

**Step 1: 写 CI（7.4 / 8.3 双版本矩阵）**

```yaml
name: CI

on:
  push:
    branches: [main, master]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4', '8.3']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo, pdo_sqlite, redis
      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist
      - name: Run smoke example
        run: php examples/boot.php
      - name: Run tests
        run: composer test
```

**Step 2: README 增加"快速开始"与"当前能力矩阵"**

在 `README.md` 追加：

```markdown
## 快速开始（M0）

```bash
composer require jasonye88/aiflow
```

```php
require 'vendor/autoload.php';

use AIFlow\AIKernel;

$kernel = AIKernel::boot([
    'storage'   => ['dsn' => 'mysql:host=127.0.0.1;dbname=fms;charset=utf8mb4', 'user' => 'root', 'pass' => ''],
    'cache'     => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379],
    'migration' => ['auto' => true],
]);
```

## 能力矩阵（M0 = 骨架完成）

| 能力 | 状态 |
|------|------|
| Kernel 引导 + 配置 | ✅ M0 |
| 存储适配（MySQL/SQLite）| ✅ M0 |
| 缓存适配（Redis/Array）| ✅ M0 |
| 建表迁移（10 张 ai_* 表）| ✅ M0 |
| 模型网关 + 对话 | 🔜 M1 |
| 积分/权限 | 🔜 M2 |
| Agent + 工具 | 🔜 M3 |
| 工作流引擎 | 🔜 M4 |
```

**Step 3: 本地全量验证**

Run: `composer test; php examples/boot.php`
Expected: 测试全绿 + 冒烟输出 11 张表

**Step 4: Commit**

```powershell
git add -A; git commit -m "ci: matrix test on php 7.4/8.3, readme quickstart with capability matrix"
```

---

## M0 验收清单（全部满足即 M0 完成）

- [ ] `composer test` 本地全绿（含 Storage 契约 4 条、Migrator、Kernel 装配）
- [ ] `php examples/boot.php` 输出 11 张 `ai_*` 表
- [ ] `composer validate` 通过、无 PHP 8.x 专属语法（7.4 跑得过即证明）
- [ ] CI 工作流已推送，GitHub Actions 双版本矩阵绿
- [ ] git 历史按任务分小步提交（约 9 个 commit）

## M1 预告（不在本计划内）

模型网关 `ModelProvider` 契约 + `OpenAICompatibleProvider`（DeepSeek 首发）+ ChatService 会话/消息 + `StreamTransport` 流式。