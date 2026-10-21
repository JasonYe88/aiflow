<?php
declare(strict_types=1);

namespace AIFlow\Credit;

use AIFlow\Contract\StorageInterface;
use AIFlow\Exception\InsufficientCreditException;
use AIFlow\Support\Id;

/**
 * 积分服务：Token 钱包 + 计价规则 + 不可变账本
 *
 * 两阶段记账（M2 关键设计）：
 *   reserve 预检冻结（余额不足直接拒绝，模型调用不发生）
 *   → 模型调用 → settle 按真实 token 实付清算 / release 失败解冻
 *
 * 金额单位一律为"分"（整数）；并发安全依赖条件原子 UPDATE + 影响行数判定
 * （MySQL InnoDB 行锁 / SQLite 库级锁下语义一致，可移植不依赖 FOR UPDATE 方言）
 * 幂等键：ref_id + kind——网络重试不会重复冻结/扣账
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
     * 计价规则 upsert（model 唯一键）：单价 = 分/百万 token
     * 重复设置 = 覆盖旧规则
     */
    public function setPriceRule(string $model, int $inputPerM, int $outputPerM, float $multiplier = 1.0): void
    {
        if ($inputPerM < 0 || $outputPerM < 0 || $multiplier < 0) {
            throw new \InvalidArgumentException('单价与倍率不能为负数');
        }
        $this->storage->transaction(function () use ($model, $inputPerM, $outputPerM, $multiplier) {
            // 先删后插：无 ON DUPLICATE KEY 的可移植 upsert
            $this->storage->execute('DELETE FROM ai_price_rule WHERE model = ?', [$model]);
            $this->storage->execute(
                'INSERT INTO ai_price_rule (id, model, input_price_per_m, output_price_per_m, multiplier, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [Id::gen(), $model, $inputPerM, $outputPerM, $multiplier, date('Y-m-d H:i:s')]
            );
        });
    }

    /**
     * @return array|null {id, model, input_price_per_m, output_price_per_m, multiplier}
     */
    public function priceOf(string $model): ?array
    {
        $rows = $this->storage->query('SELECT * FROM ai_price_rule WHERE model = ?', [$model]);
        return isset($rows[0]) ? $rows[0] : null;
    }

    /**
     * 按规则算金额（分）：四舍五入，最低 0
     * 未配置规则的模型返回 0（不扣费；tokens 仍入流水，计费可后补规则）
     */
    public function calcAmount(string $model, int $inputTokens, int $outputTokens): int
    {
        $rule = $this->priceOf($model);
        if ($rule === null) {
            return 0;
        }
        $in = $inputTokens * (int)$rule['input_price_per_m'] * (float)$rule['multiplier'] / 1000000;
        $out = $outputTokens * (int)$rule['output_price_per_m'] * (float)$rule['multiplier'] / 1000000;
        return (int)round($in + $out);
    }

    /**
     * 建或取钱包 id（owner_type + owner_id 唯一）
     * 并发首建撞唯一键时捕获异常重查，保证幂等
     */
    public function ensureWallet(string $ownerType, string $ownerId): string
    {
        $rows = $this->storage->query(
            'SELECT id FROM ai_wallet WHERE owner_type = ? AND owner_id = ?',
            [$ownerType, $ownerId]
        );
        if (isset($rows[0])) {
            return (string)$rows[0]['id'];
        }

        $id = Id::gen();
        $now = date('Y-m-d H:i:s');
        try {
            $this->storage->execute(
                'INSERT INTO ai_wallet (id, owner_type, owner_id, balance, frozen, created_at, updated_at) VALUES (?, ?, ?, 0, 0, ?, ?)',
                [$id, $ownerType, $ownerId, $now, $now]
            );
        } catch (\PDOException $e) {
            // 并发首建被唯一键拦下：重查拿先建者的 id
            $rows = $this->storage->query(
                'SELECT id FROM ai_wallet WHERE owner_type = ? AND owner_id = ?',
                [$ownerType, $ownerId]
            );
            if (!isset($rows[0])) {
                throw $e;
            }
            return (string)$rows[0]['id'];
        }
        return $id;
    }

    /**
     * 钱包信息（余额/冻结）
     * @return array|null {id, owner_type, owner_id, balance, frozen}
     */
    public function walletInfo(string $ownerType, string $ownerId): ?array
    {
        $rows = $this->storage->query(
            'SELECT id, owner_type, owner_id, balance, frozen FROM ai_wallet WHERE owner_type = ? AND owner_id = ?',
            [$ownerType, $ownerId]
        );
        if (!isset($rows[0])) {
            return null;
        }
        // PDO 依驱动/配置可能返回字符串数字：金额字段统一转 int 出参
        $info = $rows[0];
        $info['balance'] = (int)$info['balance'];
        $info['frozen'] = (int)$info['frozen'];
        return $info;
    }

    /**
     * 充值（分）：balance += amount，流水 kind=grant；未建钱包自动建
     * @return array 充值后的钱包信息（含 balance/frozen）
     */
    public function grant(string $ownerType, string $ownerId, int $amountCents): array
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('充值金额必须为正数（单位：分）');
        }
        $walletId = $this->ensureWallet($ownerType, $ownerId);
        $this->storage->execute(
            'UPDATE ai_wallet SET balance = balance + ?, updated_at = ? WHERE id = ?',
            [$amountCents, date('Y-m-d H:i:s'), $walletId]
        );
        $this->appendEntry($walletId, '', 'grant', $amountCents, '');
        return $this->walletInfo($ownerType, $ownerId);
    }

    /**
     * 预检冻结：frozen += amount，余额不足抛 InsufficientCreditException
     * 幂等：同 refId 已有 reserve 流水则直接成功（网络重试安全）；
     * 0/负额度为 no-op（未计价模型估算额为 0，不占冻结也不写流水）
     */
    public function reserve(string $walletId, int $amountCents, string $refId): void
    {
        if ($amountCents <= 0) {
            return;
        }
        if ($this->findEntry($refId, 'reserve') !== null) {
            return; // 幂等：已冻结过
        }

        // 条件原子 UPDATE：可用余额（balance - frozen）不足时影响行数为 0
        $affected = $this->storage->execute(
            'UPDATE ai_wallet SET frozen = frozen + ?, updated_at = ? WHERE id = ? AND balance - frozen >= ?',
            [$amountCents, date('Y-m-d H:i:s'), $walletId, $amountCents]
        );
        if ($affected === 0) {
            $rows = $this->storage->query('SELECT balance, frozen FROM ai_wallet WHERE id = ?', [$walletId]);
            if (empty($rows)) {
                throw new \InvalidArgumentException("钱包不存在：{$walletId}");
            }
            throw new InsufficientCreditException(
                '余额不足',
                $amountCents,
                (int)$rows[0]['balance'] - (int)$rows[0]['frozen']
            );
        }
        $this->appendEntry($walletId, '', 'reserve', $amountCents, $refId);
    }

    /**
     * 清算：按计价规则算实付 → 解冻预留、balance 扣实付 → 流水 kind=settle（带 token 用量）
     *
     * 幂等：同 refId 已有 settle 流水直接返回原金额（balance=null 表示未重读）
     * 实付可大于预留（真实用量如实扣，允许扣成负数欠费态——下次预检必然拦截，账不丢）
     * @return array {amount: int 实付分, balance: int|null 清算后余额（幂等命中为 null）}
     */
    public function settle(string $walletId, string $refId, string $model, int $inputTokens, int $outputTokens, int $reservedCents): array
    {
        if ($existed = $this->findEntry($refId, 'settle')) {
            return ['amount' => (int)$existed['amount'], 'balance' => null]; // 幂等命中
        }

        $amount = $this->calcAmount($model, $inputTokens, $outputTokens);

        $settled = $this->storage->transaction(function () use ($walletId, $refId, $model, $inputTokens, $outputTokens, $reservedCents, $amount) {
            $rows = $this->storage->query('SELECT id, balance, frozen FROM ai_wallet WHERE id = ?', [$walletId]);
            if (empty($rows)) {
                throw new \InvalidArgumentException("钱包不存在：{$walletId}");
            }
            // 解冻预留：PHP 侧钳到 0（防 MAX() 方言差异），InnoDB 行锁下读改写在事务内安全
            $unfrozen = max(0, (int)$rows[0]['frozen'] - $reservedCents);
            $this->storage->execute(
                'UPDATE ai_wallet SET frozen = ?, balance = balance - ?, updated_at = ? WHERE id = ?',
                [$unfrozen, $amount, date('Y-m-d H:i:s'), $walletId]
            );
            $this->appendEntry($walletId, $model, 'settle', $amount, $refId, $inputTokens, $outputTokens);

            $after = $this->storage->query('SELECT balance FROM ai_wallet WHERE id = ?', [$walletId]);
            return ['amount' => $amount, 'balance' => (int)$after[0]['balance']];
        });

        return $settled;
    }

    /**
     * 失败解冻：frozen -= amount（钳到 0），余额不动，流水 kind=refund
     * 幂等：同 refId 已有 refund 流水则 no-op；0 额度 no-op（本就没冻结）
     */
    public function release(string $walletId, string $refId, int $reservedCents): void
    {
        if ($reservedCents <= 0) {
            return;
        }
        if ($this->findEntry($refId, 'refund') !== null) {
            return; // 幂等：已解冻过
        }

        $rows = $this->storage->query('SELECT frozen FROM ai_wallet WHERE id = ?', [$walletId]);
        if (empty($rows)) {
            throw new \InvalidArgumentException("钱包不存在：{$walletId}");
        }
        $unfrozen = max(0, (int)$rows[0]['frozen'] - $reservedCents);
        $this->storage->execute(
            'UPDATE ai_wallet SET frozen = ?, updated_at = ? WHERE id = ?',
            [$unfrozen, date('Y-m-d H:i:s'), $walletId]
        );
        $this->appendEntry($walletId, '', 'refund', 0, $refId);
    }

    /** 账本流水（最近优先；依赖 Id 单调递增 = 生成顺序） */
    public function entries(string $walletId, int $limit = 50): array
    {
        return $this->storage->query(
            'SELECT kind, model, amount, input_tokens, output_tokens, ref_id, created_at FROM ai_ledger_entry WHERE wallet_id = ? ORDER BY id DESC LIMIT ' . (int)$limit,
            [$walletId]
        );
    }

    /**
     * 查询指定幂等键 + 类型的流水
     * @return array|null 命中的流水行
     */
    private function findEntry(string $refId, string $kind): ?array
    {
        $rows = $this->storage->query(
            'SELECT * FROM ai_ledger_entry WHERE ref_id = ? AND kind = ? LIMIT 1',
            [$refId, $kind]
        );
        return isset($rows[0]) ? $rows[0] : null;
    }

    /** 追加一条不可变流水（只写不改） */
    private function appendEntry(string $walletId, string $model, string $kind, int $amount, string $refId, int $inputTokens = 0, int $outputTokens = 0): void
    {
        $this->storage->execute(
            'INSERT INTO ai_ledger_entry (id, wallet_id, model, input_tokens, output_tokens, amount, kind, ref_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Id::gen(), $walletId, $model, $inputTokens, $outputTokens, $amount, $kind, $refId, date('Y-m-d H:i:s')]
        );
    }
}