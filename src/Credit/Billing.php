<?php
declare(strict_types=1);

namespace AIFlow\Credit;

use AIFlow\Support\ModelResult;

/**
 * 两阶段计费编排：reserve → 执行 callable → settle（异常自动 release）
 *
 * 独立于 ChatService：包任意"返回 ModelResult 的模型调用"——
 * 对话、Agent、M4 工作流的 LLM 节点都复用这一条编排路径，不侵入业务服务。
 *
 * 用法：
 *   $billed = (new Billing($kernel->credit()))->run($walletId, 'qwen-plus', $refId,
 *       function () use ($kernel) { return $kernel->gateway()->chat('qwen', $msgs); },
 *       Billing::estimateTokens($question));
 *   // $billed['result']->content / $billed['amount'] / $billed['balance']
 *
 * 幂等链路：refId 贯穿 reserve/settle/release（网络重试不重复冻结、不重复扣账）。
 */
final class Billing
{
    /** 未传估算时的默认输出上限：覆盖绝大多数单轮回复（预检防爆量的兜底，实付按真实用量） */
    const DEFAULT_EST_OUTPUT_TOKENS = 1024;

    /** @var CreditService */
    private $credit;

    public function __construct(CreditService $credit)
    {
        $this->credit = $credit;
    }

    /**
     * 粗估 token：字节数/4（中英混排工程近似，无需分词器）
     * 只是缺省启发式——宿主有 tokenizer 或已知长度时应传精确值
     */
    public static function estimateTokens(string $text): int
    {
        return (int)ceil(strlen($text) / 4);
    }

    /**
     * 两阶段计费地执行一次模型调用
     *
     * @param string   $walletId  钱包 id
     * @param string   $model     计价模型名（与计价规则的 model 键一致）
     * @param string   $refId     调用链幂等键（一次调用一个，Id::gen()）
     * @param callable $call      function(): ModelResult 真实模型调用
     * @param int      $estInput  输入 token 估算（0 = 不计输入预留）
     * @param int      $estOutput 输出 token 估算（默认 1024 兜底）
     *
     * @return array{result:ModelResult, amount:int, balance:int|null}
     *         {模型结果, 实付分, 清算后余额（幂等命中为 null）}
     * @throws \AIFlow\Exception\InsufficientCreditException 预检失败（callable 未执行）
     * @throws \Throwable callable 自身异常（已自动解冻后原样重抛）
     */
    public function run(string $walletId, string $model, string $refId, callable $call, int $estInput = 0, int $estOutput = self::DEFAULT_EST_OUTPUT_TOKENS): array
    {
        $estAmount = $this->credit->calcAmount($model, $estInput, $estOutput);
        $this->credit->reserve($walletId, $estAmount, $refId);

        try {
            $result = call_user_func($call);
        } catch (\Throwable $e) {
            // 模型失败/网络异常：释放冻结后原样重抛，宿主只管捕获业务异常
            $this->credit->release($walletId, $refId, $estAmount);
            throw $e;
        }

        $settled = $this->credit->settle(
            $walletId,
            $refId,
            $model,
            (int)$result->inputTokens,
            (int)$result->outputTokens,
            $estAmount
        );
        return ['result' => $result, 'amount' => $settled['amount'], 'balance' => $settled['balance']];
    }
}