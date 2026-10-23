<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

use AIFlow\Agent\ToolInterface;

/**
 * Agent 工具：按入口检索知识库（架子演示；权限门建议 tool:knowledge.retrieve）
 */
final class KnowledgeRetrieveTool implements ToolInterface
{
    /** @var KnowledgeService */
    private $knowledge;

    /** @var string 默认入口 */
    private $defaultEntryId;

    public function __construct(KnowledgeService $knowledge, string $defaultEntryId = 'app:demo')
    {
        $this->knowledge = $knowledge;
        $this->defaultEntryId = $defaultEntryId;
    }

    public function name(): string
    {
        return 'knowledge_retrieve';
    }

    public function description(): string
    {
        return '从已绑定的知识库检索相关段落，用于回答需要依据文档的问题。传入 query；可选 entry_id、top_k。';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => '检索问题或关键词',
                ],
                'entry_id' => [
                    'type' => 'string',
                    'description' => '业务入口 id，缺省用工具默认入口',
                ],
                'top_k' => [
                    'type' => 'integer',
                    'description' => '返回条数，默认 5',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args)
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('query 不能为空');
        }
        $entryId = trim((string)($args['entry_id'] ?? $this->defaultEntryId));
        $topK = (int)($args['top_k'] ?? 5);
        $hits = $this->knowledge->retrieve($entryId, $query, ['top_k' => $topK]);
        return [
            'entry_id' => $entryId,
            'count' => count($hits),
            'hits' => $hits,
        ];
    }
}