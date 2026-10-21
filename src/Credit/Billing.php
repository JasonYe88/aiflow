<?php
declare(strict_types=1);

namespace AIFlow\Credit;

use AIFlow\Support\ModelResult;

/**
 * 两阶段计费编排：估算 → reserve 预检 → 执行 callable → settle 清算（异常时 release 解冻）
 *
 * 包住任意模型调用（对话/Agent/M4 工作流 LLM 节点复用），不侵入 ChatService。
 * 幂等语义：refId 一次调用一个；网络重试复用同一 refId 不会重复冻结/扣账
 * （依赖 CreditService.reserve/settle 的 ref_id 幂等实现，见该类注释）。
 */
final class Billing
{
    /** 估算输出 token 上限缺省值（预检防"单次爆量"，真实用量以结算为准） */
    const DEFAULT_EST_OUTPUT_TOKENS = 1024;

    /** @var CreditService */
    private $credit;

    public function __construct(CreditService $credit)
    {
        $this->credit = $credit;
    }

    /** token 粗估启发式：字节数/4 向上取整（中英混排工程近似，宿主可传精确值覆盖） */
    public static function estimateTokens(string $text): int
    {
        return (int)ceil(strlen($text) / 4);
    }

    /**
     * 计费执行一次模型调用
     *
     * @param string   $walletId  钱包 id
     * @param string   $model     计价模型名（对应 ai_price_rule.model）
     * @param string   $refId     调用链幂等键（一次调用一个，重试复用）
     * @param callable $call      function(): ModelResult 真实模型调用
     * @param int      $estInput  预估输入 token（0 = 宿主不估，仅按输出上限预检）
     * @param int      $estOutput 预估输出 token 上限
     * @return array {result: ModelResult, amount: int 实付分, balance: int|null 剩余（幂等命中为 null）}
     * @throws \AIFlow\Exception\InsufficientCreditException 预检失败（callable 未执行）
     * @throws \Throwable callable 自身异常（已自动解冻后原样透传）
     */
    public function run(string $walletId, string $model, string $refId, callable $call, int $estInput = 0, int $estOutput = self::DEFAULT_EST_OUTPUT_TOKENS): array
    {
        // 阶段一：预检。未计价模型估算额为 0 → reserve 为 no-op（不拦截免费调用）
        $estAmount = $this->credit->calcAmount($model, $estInput, $estOutput);
        $this->credit->reserve($walletId, $estAmount, $refId);

        try {
            $result = call_user_func($call);
        } catch (\Throwable $e) {
            // 模型调用失败：解冻预留，异常原样透传（账不丢：下次重试同 refId 幂等）
            $this->credit->release($walletId, $refId, $estAmount);
            throw $e;
        }

        // 阶段二：按真实 token 用量清算
        $settled = $this->credit->settle($walletId, $refId, $model, (int)$result->inputTokens, (int)$result->outputTokens, $estAmount);
        return ['result' => $result, 'amount' => $settled['amount'], 'balance' => $settled['balance']];
    }
}