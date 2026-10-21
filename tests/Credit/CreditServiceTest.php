<?php
declare(strict_types=1);

namespace AIFlow\Tests\Credit;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Exception\AIFlowException;
use AIFlow\Exception\InsufficientCreditException;
use AIFlow\Exception\PermissionDeniedException;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class CreditServiceTest extends TestCase
{
    /** @var \AIFlow\Credit\CreditService */
    protected $credit;

    /** @var \AIFlow\Adapter\PdoStorage */
    protected $storage;

    protected function setUp(): void
    {
        $pdo = $this->makePdo();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->credit = new \AIFlow\Credit\CreditService($storage);
        $this->storage = $storage;
    }

    /** 本轮用例的底层连接（默认 SQLite 内存库；MySQL 契约轮子类覆盖此方法跑同套用例） */
    protected function makePdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }

    public function testExceptionsBelongToAiflowTree(): void
    {
        // 框架异常根为 marker 接口：宿主捕获该接口即拿到全部框架异常
        $insufficient = new InsufficientCreditException('余额不足', 100, 40);
        $this->assertInstanceOf(AIFlowException::class, $insufficient);
        $this->assertSame(100, $insufficient->need);
        $this->assertSame(40, $insufficient->have);

        $denied = new PermissionDeniedException('无权访问', 'u1', 'app:demo');
        $this->assertInstanceOf(AIFlowException::class, $denied);
        $this->assertSame('u1', $denied->userId);
        $this->assertSame('app:demo', $denied->resource);
    }

    public function testSetPriceRuleUpserts(): void
    {
        $this->credit->setPriceRule('qwen-plus', 800, 2000);
        $rule = $this->credit->priceOf('qwen-plus');
        $this->assertSame('qwen-plus', $rule['model']);
        $this->assertSame(800, (int)$rule['input_price_per_m']);
        $this->assertSame(2000, (int)$rule['output_price_per_m']);

        // 重复设置=覆盖（upsert 语义），不会出现两条
        $this->credit->setPriceRule('qwen-plus', 400, 1000, 0.5);
        $rule = $this->credit->priceOf('qwen-plus');
        $this->assertSame(400, (int)$rule['input_price_per_m']);
        $this->assertSame(1000, (int)$rule['output_price_per_m']);
        $this->assertEquals(0.5, (float)$rule['multiplier'], '', 0.0001);
    }

    public function testPriceOfUnknownModelIsNull(): void
    {
        $this->assertNull($this->credit->priceOf('no-such-model'));
    }

    /** qwen-plus 官方价约 ¥0.8/¥2 每百万 token → 分单位 800/2000 */
    public function testCalcAmount(): void
    {
        $this->credit->setPriceRule('qwen-plus', 800, 2000);

        // 1M in + 1M out = 800 + 2000 = 2800 分
        $this->assertSame(2800, $this->credit->calcAmount('qwen-plus', 1000000, 1000000));
        // 零 token 不扣
        $this->assertSame(0, $this->credit->calcAmount('qwen-plus', 0, 0));
        // 小数额四舍五入：18 in + 92 out ≈ 0.2 分 → 0
        $this->assertSame(0, $this->credit->calcAmount('qwen-plus', 18, 92));
        // 大额 out：2M out = 4000 分
        $this->assertSame(4000, $this->credit->calcAmount('qwen-plus', 0, 2000000));
    }

    public function testCalcAmountWithMultiplier(): void
    {
        $this->credit->setPriceRule('qwen-plus', 800, 2000, 0.5);
        $this->assertSame(1400, $this->credit->calcAmount('qwen-plus', 1000000, 1000000));
    }

    /** 未配置计价的模型不扣费（tokens 仍会入流水，计费可后补规则） */
    public function testCalcAmountWithoutRuleIsFree(): void
    {
        $this->assertSame(0, $this->credit->calcAmount('unknown-model', 999999, 999999));
    }

    public function testSetPriceRuleValidatesInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->credit->setPriceRule('m', -1, 100);
    }

    // ---- Task 2: 钱包（建钱包 / 查询 / 充值）----

    /** ensureWallet 幂等：同 owner 两次调用返回同一 id；不同 owner 类型互不干扰 */
    public function testEnsureWalletIsIdempotent(): void
    {
        $a = $this->credit->ensureWallet('user', 'u1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);

        $b = $this->credit->ensureWallet('user', 'u1');
        $this->assertSame($a, $b);

        // 同 ownerId 不同 ownerType 是不同钱包；不同 ownerId 也是不同钱包
        $c = $this->credit->ensureWallet('dept', 'u1');
        $d = $this->credit->ensureWallet('user', 'u2');
        $this->assertNotSame($a, $c);
        $this->assertNotSame($a, $d);
    }

    /** walletInfo：未建钱包返回 null；新建后余额为 0 */
    public function testWalletInfoUnknownOwnerIsNull(): void
    {
        $this->assertNull($this->credit->walletInfo('user', 'nobody'));

        $this->credit->ensureWallet('user', 'nobody');
        $info = $this->credit->walletInfo('user', 'nobody');
        $this->assertNotNull($info);
        $this->assertSame(0, $info['balance']);
        $this->assertSame(0, $info['frozen']);
    }

    /** grant 充值：balance 累加、ledger 出现 kind=grant 的流水；组合余额返回 */
    public function testGrantAddsBalanceAndLedgerEntry(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');

        $info = $this->credit->grant('user', 'u1', 10000);
        $this->assertSame($walletId, $info['id']);
        $this->assertSame(10000, $info['balance']);
        $this->assertSame(0, $info['frozen']);

        $this->credit->grant('user', 'u1', 500);
        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(10500, $info['balance']);

        $entries = $this->storage->query(
            'SELECT kind, amount, model FROM ai_ledger_entry WHERE wallet_id = ? ORDER BY created_at ASC, id ASC',
            [$walletId]
        );
        $this->assertCount(2, $entries);
        $this->assertSame('grant', $entries[0]['kind']);
        $this->assertSame(10000, (int)$entries[0]['amount']);
        $this->assertSame('grant', $entries[1]['kind']);
        $this->assertSame(500, (int)$entries[1]['amount']);
    }

    /** grant 未建钱包时自动建（宿主"先充后用"的一次调用路径） */
    public function testGrantCreatesWalletIfNeeded(): void
    {
        $info = $this->credit->grant('user', 'fresh', 200);
        $this->assertSame(200, $info['balance']);
        $this->assertSame('fresh', $info['owner_id']);
    }

    /** grant 参数校验：非正数金额拒绝 */
    public function testGrantRejectsNonPositiveAmount(): void
    {
        $this->credit->ensureWallet('user', 'u1');
        $this->expectException(\InvalidArgumentException::class);
        $this->credit->grant('user', 'u1', 0);
    }

    // ---- Task 3: reserve 预检冻结 ----

    /** 冻结成功：frozen 增加、balance 不动；可用 = balance - frozen */
    public function testReserveFreezesAvailableAmount(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 10000);

        $this->credit->reserve($walletId, 6000, 'ref-1');
        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(10000, $info['balance']);
        $this->assertSame(6000, $info['frozen']);

        // 流水 kind=reserve 记录预留额（不动 balance）
        $entries = $this->storage->query('SELECT kind, amount, ref_id FROM ai_ledger_entry WHERE wallet_id = ?', [$walletId]);
        $reserve = array_values(array_filter($entries, function ($e) { return $e['kind'] === 'reserve'; }));
        $this->assertCount(1, $reserve);
        $this->assertSame(6000, (int)$reserve[0]['amount']);
        $this->assertSame('ref-1', $reserve[0]['ref_id']);
    }

    /** 余额不足以覆盖预留：抛 InsufficientCreditException，携带 need/have，frozen 不变 */
    public function testReserveInsufficientThrowsWithContext(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 10000);
        $this->credit->reserve($walletId, 6000, 'ref-1');

        try {
            $this->credit->reserve($walletId, 5000, 'ref-2');
            $this->fail('应抛 InsufficientCreditException');
        } catch (InsufficientCreditException $e) {
            $this->assertSame(5000, $e->need);
            $this->assertSame(4000, $e->have); // 10000 - 6000
        }
        // 拒绝后冻结额与流水都不应变化
        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(6000, $info['frozen']);
        $entries = $this->storage->query('SELECT id FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'reserve']);
        $this->assertCount(1, $entries);
    }

    /** 幂等：同 refId 已有 reserve 流水则直接成功返回，不重复冻结（网络重试安全） */
    public function testReserveIsIdempotentByRefId(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 10000);

        $this->credit->reserve($walletId, 6000, 'ref-1');
        $this->credit->reserve($walletId, 6000, 'ref-1'); // 重试

        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(6000, $info['frozen']);
        $entries = $this->storage->query('SELECT id FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'reserve']);
        $this->assertCount(1, $entries);
    }

    /** 0/负额度无需冻结（未计价模型的估算额为 0） */
    public function testReserveZeroAmountIsNoop(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 1000);

        $this->credit->reserve($walletId, 0, 'ref-0');
        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(0, $info['frozen']);
        $this->assertCount(1, $this->storage->query('SELECT id FROM ai_ledger_entry WHERE wallet_id = ?', [$walletId])); // 仅 grant 一条
    }

    /** 钱包不存在：抛 InvalidArgumentException（与余额不足区分） */
    public function testReserveUnknownWalletThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->credit->reserve('deadbeef', 100, 'ref-x');
    }

    /** 耗尽后充值可再冻结 */
    public function testReserveAfterGrantRecovers(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 1000);
        try {
            $this->credit->reserve($walletId, 5000, 'ref-1');
            $this->fail('应抛 InsufficientCreditException');
        } catch (InsufficientCreditException $e) {
            // 预期拒绝
        }
        $this->credit->grant('user', 'u1', 9000);
        $this->credit->reserve($walletId, 5000, 'ref-2');
        $this->assertSame(5000, $this->credit->walletInfo('user', 'u1')['frozen']);
    }

    // ---- Task 4: settle 清算 + release 解冻 ----

    /** settle：按计价算实付扣 balance、解冻预留、流水带 token 用量 */
    public function testSettleDeductsActualAndUnfreezes(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 100);          // 余额 100 分
        $this->credit->reserve($walletId, 100, 'ref-1');  // 冻结 100
        // 计价：input 350000 分/百万 → 100 token = 35 分
        $this->credit->setPriceRule('m', 350000, 0);

        $settled = $this->credit->settle($walletId, 'ref-1', 'm', 100, 0, 100);
        $this->assertSame(35, $settled['amount']);
        $this->assertSame(65, $settled['balance']);

        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(65, $info['balance']);
        $this->assertSame(0, $info['frozen']);

        $entry = $this->storage->query(
            'SELECT kind, amount, model, input_tokens, output_tokens FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?',
            [$walletId, 'settle']
        );
        $this->assertCount(1, $entry);
        $this->assertSame(35, (int)$entry[0]['amount']);
        $this->assertSame('m', $entry[0]['model']);
        $this->assertSame(100, (int)$entry[0]['input_tokens']);
    }

    /** settle 幂等：同 refId 重复清算返回原金额且不再扣（网络重试安全） */
    public function testSettleIsIdempotentByRefId(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 100);
        $this->credit->reserve($walletId, 100, 'ref-1');
        $this->credit->setPriceRule('m', 350000, 0);

        $first = $this->credit->settle($walletId, 'ref-1', 'm', 100, 0, 100);
        $again = $this->credit->settle($walletId, 'ref-1', 'm', 100, 0, 100); // 重试

        $this->assertSame(35, $again['amount']);
        $this->assertNull($again['balance']); // 幂等命中返回原金额，不重读余额
        $this->assertSame(65, $this->credit->walletInfo('user', 'u1')['balance']);
        $n = $this->storage->query('SELECT id FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'settle']);
        $this->assertCount(1, $n);
    }

    /** 未计价模型：settle 实付 0，token 照入流水（用量可观测） */
    public function testSettleWithoutRuleRecordsTokensOnly(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 100);

        $settled = $this->credit->settle($walletId, 'ref-1', 'unpriced', 500, 200, 0);
        $this->assertSame(0, $settled['amount']);
        $this->assertSame(100, $settled['balance']);
        $entry = $this->storage->query('SELECT input_tokens, output_tokens, amount FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'settle']);
        $this->assertSame(500, (int)$entry[0]['input_tokens']);
        $this->assertSame(200, (int)$entry[0]['output_tokens']);
        $this->assertSame(0, (int)$entry[0]['amount']);
    }

    /** 实付 > 预留：允许扣成负数（欠费态，下次预检必然拦截）——真实用量如实扣 */
    public function testSettleActualExceedsReservedGoesNegative(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 100);
        $this->credit->reserve($walletId, 100, 'ref-1');
        $this->credit->setPriceRule('m', 1500000, 0); // 100 token = 150 分

        $settled = $this->credit->settle($walletId, 'ref-1', 'm', 100, 0, 100);
        $this->assertSame(150, $settled['amount']);
        $this->assertSame(-50, $settled['balance']);
        $this->assertSame(0, $this->credit->walletInfo('user', 'u1')['frozen']);
    }

    /** release：失败解冻，frozen 归零、余额不动；幂等同 refId */
    public function testReleaseUnfreezesWithoutCharging(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 10000);
        $this->credit->reserve($walletId, 6000, 'ref-1');

        $this->credit->release($walletId, 'ref-1', 6000);
        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(10000, $info['balance']);
        $this->assertSame(0, $info['frozen']);

        $this->credit->release($walletId, 'ref-1', 6000); // 幂等重试
        $this->assertSame(0, $this->credit->walletInfo('user', 'u1')['frozen']);
        $n = $this->storage->query('SELECT id FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'refund']);
        $this->assertCount(1, $n);
    }

    /** release 防御：解冻额超过当前 frozen 时清零（不抛错、余额不动） */
    public function testReleaseClampsToZero(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 10000);
        $this->credit->reserve($walletId, 3000, 'ref-1');

        $this->credit->release($walletId, 'ref-1', 9999);
        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(0, $info['frozen']);
        $this->assertSame(10000, $info['balance']);
    }

    /** entries：流水最近优先，limit 生效 */
    public function testEntriesListsRecentFirst(): void
    {
        $walletId = $this->credit->ensureWallet('user', 'u1');
        $this->credit->grant('user', 'u1', 10000);
        $this->credit->reserve($walletId, 3000, 'ref-1');
        $this->credit->release($walletId, 'ref-1', 3000);

        $entries = $this->credit->entries($walletId, 2);
        $this->assertCount(2, $entries);
        $this->assertSame('refund', $entries[0]['kind']); // 最近优先
        $this->assertSame('reserve', $entries[1]['kind']);
    }
}
