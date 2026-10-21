<?php
declare(strict_types=1);

namespace AIFlow\Tests\Credit;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Credit\Billing;
use AIFlow\Exception\InsufficientCreditException;
use AIFlow\Support\ModelResult;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class BillingTest extends TestCase
{
    /** @var \AIFlow\Credit\CreditService */
    private $credit;

    /** @var Billing */
    private $billing;

    /** @var \AIFlow\Adapter\PdoStorage */
    private $storage;

    protected function setUp(): void
    {
        $pdo = $this->makePdo();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->storage = $storage;
        $this->credit = new \AIFlow\Credit\CreditService($storage);
        $this->billing = new Billing($this->credit);
    }

    /** 本轮用例的底层连接（默认 SQLite 内存库；MySQL 契约轮子类覆盖此方法跑同套用例） */
    protected function makePdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }

    /** 建钱包并充值，返回钱包 id */
    private function wallet(int $cents): string
    {
        $id = $this->credit->ensureWallet('user', 'u1');
        if ($cents > 0) {
            $this->credit->grant('user', 'u1', $cents);
        }
        return $id;
    }

    /** 成功路径：reserve → 执行 → settle，实付按真实 token 扣、返回 amount/balance */
    public function testRunSuccessPathChargesActual(): void
    {
        $walletId = $this->wallet(10000);
        // 计价：input 0.1 分/token，output 0 分（100000 分/百万）
        $this->credit->setPriceRule('m', 100000, 0);

        $called = 0;
        $out = $this->billing->run($walletId, 'm', 'ref-1', function () use (&$called): ModelResult {
            $called++;
            return new ModelResult('ok', 'm', 100, 0, 5);
        }, 50, 100); // 估算 5 分 → 冻结 5；实付 100 token = 10 分

        $this->assertSame(1, $called);
        $this->assertSame('ok', $out['result']->content);
        $this->assertSame(10, $out['amount']);
        $this->assertSame(9990, $out['balance']);

        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(9990, $info['balance']);
        $this->assertSame(0, $info['frozen']);
    }

    /** callable 抛异常：自动解冻（frozen 归零、余额不变），异常原样透传 */
    public function testRunReleasesOnCallbackThrow(): void
    {
        $walletId = $this->wallet(10000);
        $this->credit->setPriceRule('m', 100000, 0);

        try {
            $this->billing->run($walletId, 'm', 'ref-1', function (): ModelResult {
                throw new \RuntimeException('model down');
            }, 50, 100);
            $this->fail('callable 异常应透传');
        } catch (\RuntimeException $e) {
            $this->assertSame('model down', $e->getMessage());
        }

        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(10000, $info['balance']);
        $this->assertSame(0, $info['frozen']);
        $refund = $this->storage->query('SELECT id FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'refund']);
        $this->assertCount(1, $refund);
    }

    /** 余额不足：InsufficientCreditException 抛出且模型 callable 从未被调用 */
    public function testRunInsufficientNeverCallsModel(): void
    {
        $walletId = $this->wallet(10); // 只有 10 分
        // 计价：估算 50 in + 100 out = 5 + 20 = 25 分 > 10
        $this->credit->setPriceRule('m', 100000, 200000);

        $called = false;
        try {
            $this->billing->run($walletId, 'm', 'ref-1', function () use (&$called): ModelResult {
                $called = true;
                return new ModelResult('', 'm', 0, 0, 1);
            }, 50, 100);
            $this->fail('余额不足应抛 InsufficientCreditException');
        } catch (InsufficientCreditException $e) {
            $this->assertSame(25, $e->need);
            $this->assertSame(10, $e->have);
        }
        $this->assertFalse($called, '余额不足时模型 callable 不应被调用');

        $info = $this->credit->walletInfo('user', 'u1');
        $this->assertSame(10, $info['balance']);
        $this->assertSame(0, $info['frozen']);
        $settle = $this->storage->query('SELECT id FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'settle']);
        $this->assertCount(0, $settle);
    }

    /** 未计价模型：不扣费但 token 照入流水（用量可观测、规则可后补追溯） */
    public function testRunUnpricedModelStillRecordsUsage(): void
    {
        $walletId = $this->wallet(1000);

        $out = $this->billing->run($walletId, 'unpriced', 'ref-1', function (): ModelResult {
            return new ModelResult('hi', 'unpriced', 7777, 88, 3);
        });

        $this->assertSame(0, $out['amount']);
        $this->assertSame(1000, $out['balance']);
        $entry = $this->storage->query('SELECT input_tokens, output_tokens, amount FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'settle']);
        $this->assertSame(7777, (int)$entry[0]['input_tokens']);
        $this->assertSame(88, (int)$entry[0]['output_tokens']);
        $this->assertSame(0, (int)$entry[0]['amount']);
    }

    /** 同 refId 重跑（网络重试语义）：不重复扣账，金额返回原结果 */
    public function testRunRetrySameRefIdNotDoubleCharged(): void
    {
        $walletId = $this->wallet(10000);
        $this->credit->setPriceRule('m', 100000, 0);

        $this->billing->run($walletId, 'm', 'ref-1', function (): ModelResult {
            return new ModelResult('first', 'm', 100, 0, 5);
        }, 50, 100);

        // 重试：真实用量报得再大也不重复扣
        $again = $this->billing->run($walletId, 'm', 'ref-1', function (): ModelResult {
            return new ModelResult('retry', 'm', 999999, 999999, 5);
        }, 50, 100);

        $this->assertSame(10, $again['amount']); // 幂等：返回首次清算金额
        $this->assertNull($again['balance']);    // 幂等命中不重读余额
        $this->assertSame(9990, $this->credit->walletInfo('user', 'u1')['balance']);
        $settle = $this->storage->query('SELECT id FROM ai_ledger_entry WHERE wallet_id = ? AND kind = ?', [$walletId, 'settle']);
        $this->assertCount(1, $settle);
    }

    /** token 粗估启发式：字节数/4 向上取整 */
    public function testEstimateTokens(): void
    {
        $this->assertSame(0, Billing::estimateTokens(''));
        $this->assertSame(1, Billing::estimateTokens('abc'));
        $this->assertSame(3, Billing::estimateTokens('你好abc')); // 9 字节 / 4
        $this->assertSame(3, Billing::estimateTokens('abcdabcdabcd')); // 12 字节 / 4 = 3
    }
}