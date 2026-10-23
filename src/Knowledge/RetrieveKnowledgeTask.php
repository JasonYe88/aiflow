<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

use AIFlow\Workflow\TaskInterface;

/**
 * 工作流 task：按入口检索知识库，产物写入 knowledge_hits / knowledge_context
 *
 * 引擎以 new 无参实例化，故运行前须：
 *   RetrieveKnowledgeTask::useKnowledge($kernel->knowledge());
 *
 * 上下文约定：
 *   knowledge_entry_id | entry_id  业务入口
 *   knowledge_query | query        检索问句
 *   knowledge_top_k | top_k        可选，默认 5
 *   knowledge_env | env            可选，默认 prod
 */
final class RetrieveKnowledgeTask implements TaskInterface
{
    /** @var KnowledgeService|null */
    private static $knowledge;

    public static function useKnowledge(KnowledgeService $knowledge): void
    {
        self::$knowledge = $knowledge;
    }

    /** @internal 测试复位 */
    public static function reset(): void
    {
        self::$knowledge = null;
    }

    public function execute(array $input): array
    {
        if (self::$knowledge === null) {
            throw new \RuntimeException(
                'RetrieveKnowledgeTask 未绑定 KnowledgeService，请先 RetrieveKnowledgeTask::useKnowledge($kernel->knowledge())'
            );
        }
        $entryId = trim((string)($input['knowledge_entry_id'] ?? $input['entry_id'] ?? ''));
        $query = trim((string)($input['knowledge_query'] ?? $input['query'] ?? ''));
        if ($entryId === '' || $query === '') {
            throw new \InvalidArgumentException('上下文须提供 entry_id（或 knowledge_entry_id）与 query（或 knowledge_query）');
        }
        $topK = (int)($input['knowledge_top_k'] ?? $input['top_k'] ?? 5);
        $env = (string)($input['knowledge_env'] ?? $input['env'] ?? 'prod');
        $hits = self::$knowledge->retrieve($entryId, $query, [
            'top_k' => $topK,
            'env' => $env,
        ]);
        return [
            'knowledge_hits' => $hits,
            'knowledge_context' => KnowledgeContext::toText($hits),
            'knowledge_hit_count' => count($hits),
        ];
    }
}
