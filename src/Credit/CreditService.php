<?php
declare(strict_types=1);

namespace AIFlow\Credit;

use AIFlow\Contract\StorageInterface;
use AIFlow\Exception\InsufficientCreditException;
use AIFlow\Support\Id;

/**
 * 积分/计价服务（M2）
 * 消费 ai_price_rule 表：模型计价规则（upsert）+ 按 token 用量计费
 *
 * 钱包两阶段记账（reserve/settle/refund，ai_wallet + ai_ledger_entry）
 * 在本类上继续扩展：reserve() 预检冻结 → settle() 按实际用量结算 → 失败 refund()
 */
final class CreditService
{
    /** @var StorageInterface */
    private $storage;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * 设置模型计价规则（upsert 语义：同模型覆盖，不产生第二条）
     * 价格单位：分 / 每百万 token；multiplier 为计费倍率（折扣/加价），默认 1.0
     */
    public function setPriceRule(string $model, int $inputPricePerM, int $outputPricePerM, float $multiplier = 1.0): void
    {
        if ($model === '' || $inputPricePerM < 0 || $outputPricePerM < 0 || $multiplier < 0) {
            throw new \InvalidArgumentException('计价规则非法：model 非空，价格与倍率须非负');
        }
        $hit = $this->storage->query('SELECT id FROM ai_price_rule WHERE model = ?', [$model]);
        if ($hit !== []) {
            // 先查再改而非 UPDATE 看 rowCount：MySQL 对"值未变"的 UPDATE 返回 0 行，
            // 会误判为不存在而走 INSERT 撞唯一索引
            $this->storage->execute(
                'UPDATE ai_price_rule SET input_price_per_m = ?, output_price_per_m = ?, multiplier = ? WHERE model = ?',
                [$inputPricePerM, $outputPricePerM, $multiplier, $model]
            );
            return;
        }
        $this->storage->execute(
            'INSERT INTO ai_price_rule (id, model, input_price_per_m, output_price_per_m, multiplier, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [Id::gen(), $model, $inputPricePerM, $outputPricePerM, $multiplier, date('Y-m-d H:i:s')]
        );
    }

    /** 模型计价规则行（model/input_price_per_m/output_price_per_m/multiplier）；未配置返回 null */
    public function priceOf(string $model): ?array
    {
        $rows = $this->storage->query(
            'SELECT model, input_price_per_m, output_price_per_m, multiplier FROM ai_price_rule WHERE model = ?',
            [$model]
        );
        return $rows === [] ? null : $rows[0];
    }

    /**
     * 计算一次模型调用的费用（分），四舍五入取整
     * 未配置计价的模型返回 0：tokens 照常入流水（ai_ledger_entry），计费规则可后补追溯
     */
    public function calcAmount(string $model, int $inputTokens, int $outputTokens): int
    {
        $rule = $this->priceOf($model);
        if ($rule === null) {
            return 0;
        }
        // 先乘后除避免精度损失；金额上限远在 BIGINT 之内
        $raw = ($inputTokens * (int)$rule['input_price_per_m'] + $outputTokens * (int)$rule['output_price_per_m'])
            * (float)$rule['multiplier'] / 1000000;
        return (int)round($raw);
    }

    // ---- 钱包（Task 2）----

    /**
     * 建或取钱包 id（owner 唯一）；并发首建撞唯一索引时重查返回对方建的钱包
     * owner_type ∈ user|dept|app；ownerId 为宿主体系内的主体标识
     */
    public function ensureWallet(string $ownerType, string $ownerId): string
    {
        $walletId = $this->findWalletId($ownerType, $ownerId);
        if ($walletId !== null) {
            return $walletId;
        }
        $id = Id::gen();
        $now = date('Y-m-d H:i:s');
        try {
            $this->storage->execute(
                'INSERT INTO ai_wallet (id, owner_type, owner_id, balance, frozen, created_at, updated_at)
                 VALUES (?, ?, ?, 0, 0, ?, ?)',
                [$id, $ownerType, $ownerId, $now, $now]
            );
            return $id;
        } catch (\Throwable $ignored) {
            // 并发首建：唯一索引拦截 → 重查对手已建的钱包；仍查不到则异常按原样上抛
            $walletId = $this->findWalletId($ownerType, $ownerId);
            if ($walletId === null) {
                throw $ignored;
            }
            return $walletId;
        }
    }

    /**
     * 钱包信息 {id, owner_type, owner_id, balance, frozen}；未建返回 null
     * balance/frozen 已转 int（SQLite 对 BIGINT 列返回字符串）
     */
    public function walletInfo(string $ownerType, string $ownerId): ?array
    {
        $rows = $this->storage->query(
            'SELECT id, owner_type, owner_id, balance, frozen FROM ai_wallet WHERE owner_type = ? AND owner_id = ?',
            [$ownerType, $ownerId]
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $row['balance'] = (int)$row['balance'];
        $row['frozen'] = (int)$row['frozen'];
        return $row;
    }

    /**
     * 充值（分）：balance += amount（无钱包自动建），流水 kind=grant
     * 方向语义：grant 的正数 = 入账（与 reserve/settle 的正数扣费相反，靠 kind 区分）
     *
     * @return array 充值后的钱包信息（同 walletInfo 结构）
     * @throws \InvalidArgumentException amount <= 0
     */
    public function grant(string $ownerType, string $ownerId, int $amountCents): array
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("充值金额必须为正数（分）：{$amountCents}");
        }
        $walletId = $this->ensureWallet($ownerType, $ownerId);
        // 余额变动与流水同事务：崩溃时要么都生效要么都不生效，账实一致
        $this->storage->transaction(function () use ($walletId, $amountCents): void {
            $this->storage->execute(
                'UPDATE ai_wallet SET balance = balance + ?, updated_at = ? WHERE id = ?',
                [$amountCents, date('Y-m-d H:i:s'), $walletId]
            );
            $this->appendEntry($walletId, '', 'grant', $amountCents, Id::gen());
        });
        $info = $this->walletInfo($ownerType, $ownerId);
        // grant 内部已 ensureWallet，此处必然存在；防御性兜底
        if ($info === null) {
            throw new \RuntimeException("内部错误：充值后钱包不可见 owner={$ownerType}/{$ownerId}");
        }
        return $info;
    }

    /** @return string|null 钱包 id；未建返回 null */
    private function findWalletId(string $ownerType, string $ownerId): ?string
    {
        $rows = $this->storage->query(
            'SELECT id FROM ai_wallet WHERE owner_type = ? AND owner_id = ?',
            [$ownerType, $ownerId]
        );
        return $rows === [] ? null : (string)$rows[0]['id'];
    }

    /** 追加账本流水（账本只插入、永不修改） */
    private function appendEntry(string $walletId, string $model, string $kind, int $amount, string $refId, int $inputTokens = 0, int $outputTokens = 0): void
    {
        $this->storage->execute(
            'INSERT INTO ai_ledger_entry (id, wallet_id, model, input_tokens, output_tokens, amount, kind, ref_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Id::gen(), $walletId, $model, $inputTokens, $outputTokens, $amount, $kind, $refId, date('Y-m-d H:i:s')]
        );
    }

    // ---- 预检冻结（Task 3）----

    /**
     * 预检冻结：frozen += amount；可用余额（balance - frozen）不足抛 InsufficientCreditException
     *
     * 幂等：同 refId 已有 reserve 流水则直接成功返回（网络重试安全）；
     * 并发安全：条件原子 UPDATE 判影响行数（MySQL 行锁 / SQLite 库级写锁天然串行化），
     * 不用 SELECT...FOR UPDATE（MySQL 方言，SQLite 不支持）。
     * 冻结与流水同事务：中途崩溃要么都不发生（上层可安全重试），要么都生效。
     *
     * @param string $walletId  钱包 id
     * @param int    $amountCents 预留额（分）；<=0 直接返回（未计价模型估算为 0）
     * @param string $refId 调用链幂等键（一次模型调用一个）
     * @throws InsufficientCreditException 可用余额不足（need/have 供宿主引导充值）
     * @throws \InvalidArgumentException 钱包不存在
     */
    public function reserve(string $walletId, int $amountCents, string $refId): void
    {
        if ($amountCents <= 0) {
            return; // 0 额度无需冻结
        }
        if ($this->hasEntry($refId, 'reserve')) {
            return; // 幂等：同一调用链已冻结过，重试安全
        }
        $this->storage->transaction(function () use ($walletId, $amountCents, $refId): void {
            $affected = $this->storage->execute(
                'UPDATE ai_wallet SET frozen = frozen + ?, updated_at = ? WHERE id = ? AND balance - frozen >= ?',
                [$amountCents, date('Y-m-d H:i:s'), $walletId, $amountCents]
            );
            if ($affected > 0) {
                $this->appendEntry($walletId, '', 'reserve', $amountCents, $refId);
                return;
            }
            // 未命中：钱包不存在或可用不足 → 读现状定罪
            $rows = $this->storage->query('SELECT balance, frozen FROM ai_wallet WHERE id = ?', [$walletId]);
            if ($rows === []) {
                throw new \InvalidArgumentException("钱包不存在: {$walletId}");
            }
            throw new InsufficientCreditException(
                '余额不足',
                $amountCents,
                (int)$rows[0]['balance'] - (int)$rows[0]['frozen']
            );
        });
    }

    /** 同 refId+kind 的流水是否已存在（幂等判定） */
    private function hasEntry(string $refId, string $kind): bool
    {
        return $this->findEntry($refId, $kind) !== null;
    }

    /** @return array|null 同 refId+kind 的已有流水行（Task 4 settle 幂等复用）；无则 null */
    private function findEntry(string $refId, string $kind): ?array
    {
        $rows = $this->storage->query(
            'SELECT id, wallet_id, model, input_tokens, output_tokens, amount, ref_id FROM ai_ledger_entry WHERE ref_id = ? AND kind = ?',
            [$refId, $kind]
        );
        return $rows === [] ? null : $rows[0];
    }

    // ---- 清算/解冻（Task 4）----

    /**
     * 清算：按计价规则算实付 → balance -= 实付、frozen -= 预留 → 流水 kind=settle（带 token 用量）
     *
     * 幂等：同 refId 已有 settle 流水直接返回原 amount（balance 为 null 表示幂等命中未重读）；
     * 实付可大于预留（真实用量如实扣），允许扣成负数（欠费态，下次预检必然拦截，账不丢）；
     * 解冻预留、扣实付、写流水同事务，崩溃时要么整体生效要么整体未发生（上层按 refId 重试安全）。
     *
     * @return array{amount:int, balance:int|null} {实付分, 清算后余额}
     * @throws \InvalidArgumentException 钱包不存在
     */
    public function settle(string $walletId, string $refId, string $model, int $inputTokens, int $outputTokens, int $reservedCents): array
    {
        $existed = $this->findEntry($refId, 'settle');
        if ($existed !== null) {
            return ['amount' => (int)$existed['amount'], 'balance' => null];
        }
        $amount = $this->calcAmount($model, $inputTokens, $outputTokens);
        $now = date('Y-m-d H:i:s');

        $this->storage->transaction(function () use ($walletId, $refId, $model, $inputTokens, $outputTokens, $reservedCents, $amount, $now, &$balance): void {
            // 解冻预留：读现值后在 PHP 侧取 min（防解冻超额），避免 MAX() 方言差异（MySQL/SQLite 3.32 双参兼容性靠不住）
            $rows = $this->storage->query('SELECT balance, frozen FROM ai_wallet WHERE id = ?', [$walletId]);
            if ($rows === []) {
                throw new \InvalidArgumentException("钱包不存在: {$walletId}");
            }
            $unfreeze = max(0, min((int)$rows[0]['frozen'], $reservedCents));
            if ($unfreeze > 0) {
                $this->storage->execute(
                    'UPDATE ai_wallet SET frozen = frozen - ?, updated_at = ? WHERE id = ?',
                    [$unfreeze, $now, $walletId]
                );
            }
            $this->storage->execute(
                'UPDATE ai_wallet SET balance = balance - ?, updated_at = ? WHERE id = ?',
                [$amount, $now, $walletId]
            );
            $this->appendEntry($walletId, $model, 'settle', $amount, $refId, $inputTokens, $outputTokens);
            $balance = (int)$rows[0]['balance'] - $amount;
        });

        return ['amount' => $amount, 'balance' => isset($balance) ? $balance : null];
    }

    /**
     * 失败解冻：frozen -= amount（防御性清零），流水 kind=refund amount=0；幂等同 refId
     * refund 流水 amount 记 0：流水只表达"调用链已终止、无扣费"，金额语义不变（正=扣费/入账看 kind）
     *
     * @throws \InvalidArgumentException 钱包不存在
     */
    public function release(string $walletId, string $refId, int $reservedCents): void
    {
        if ($this->hasEntry($refId, 'refund')) {
            return; // 幂等：同一调用链已解冻过
        }
        $now = date('Y-m-d H:i:s');
        $this->storage->transaction(function () use ($walletId, $refId, $reservedCents, $now): void {
            $rows = $this->storage->query('SELECT frozen FROM ai_wallet WHERE id = ?', [$walletId]);
            if ($rows === []) {
                throw new \InvalidArgumentException("钱包不存在: {$walletId}");
            }
            $unfreeze = max(0, min((int)$rows[0]['frozen'], $reservedCents));
            if ($unfreeze > 0) {
                $this->storage->execute(
                    'UPDATE ai_wallet SET frozen = frozen - ?, updated_at = ? WHERE id = ?',
                    [$unfreeze, $now, $walletId]
                );
            }
            $this->appendEntry($walletId, '', 'refund', 0, $refId);
        });
    }

    /** 钱包流水（最近优先），含 kind/model/tokens/amount/ref_id/created_at */
    public function entries(string $walletId, int $limit = 50): array
    {
        return $this->storage->query(
            "SELECT kind, model, input_tokens, output_tokens, amount, ref_id, created_at
             FROM ai_ledger_entry WHERE wallet_id = ?
             ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit,
            [$walletId]
        );
    }
}