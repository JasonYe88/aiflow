<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

use AIFlow\Contract\StorageInterface;
use AIFlow\Support\Id;

/**
 * 知识库架子服务：建库 / 文档入库分段 / 启停 / 入口绑定 / 检索
 *
 * 不做上传 UI、真实 Embedding；ingestText 按空行拆 chunk，检索默认 SimpleKeywordRetriever
 */
final class KnowledgeService
{
    /** @var StorageInterface */
    private $storage;

    /** @var RetrieverInterface */
    private $retriever;

    public function __construct(StorageInterface $storage, ?RetrieverInterface $retriever = null)
    {
        $this->storage = $storage;
        $this->retriever = $retriever ?: new SimpleKeywordRetriever($storage);
    }

    /**
     * @param array $meta 分段/索引/检索配置（写入 meta_json）
     * @return string base id
     */
    public function createBase(string $code, string $name, string $ownerId, string $description = '', string $retriever = 'simple', array $meta = []): string
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException('知识库 code 不能为空');
        }
        $existing = $this->storage->query('SELECT id FROM ai_knowledge_base WHERE code = ?', [$code]);
        if ($existing !== []) {
            throw new \InvalidArgumentException("知识库 code 已存在：{$code}");
        }
        $retriever = $this->normalizeRetriever($retriever);
        $id = Id::gen();
        $now = $this->now();
        $metaJson = json_encode($meta === [] ? new \stdClass() : $meta, JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) {
            $metaJson = '{}';
        }
        $this->storage->execute(
            'INSERT INTO ai_knowledge_base (id, code, name, description, owner_id, status, retriever, meta_json, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$id, $code, $name, $description, $ownerId, 'active', $retriever, $metaJson, $now, $now]
        );
        return $id;
    }

    public function archiveBase(string $baseId): void
    {
        $this->requireBase($baseId);
        $this->storage->execute(
            'UPDATE ai_knowledge_base SET status = ?, updated_at = ? WHERE id = ?',
            ['archived', $this->now(), $baseId]
        );
    }

    /**
     * 更新知识库元数据（允许 name / description / retriever / meta；status 仅 active|draft）
     *
     * @param array{name?:string,description?:string,retriever?:string,status?:string,meta?:array} $fields
     */
    public function updateBase(string $baseId, array $fields): void
    {
        $this->requireBase($baseId);
        $sets = [];
        $params = [];
        foreach (['name', 'description'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = ?";
                $params[] = (string)$fields[$key];
            }
        }
        if (array_key_exists('retriever', $fields)) {
            $sets[] = 'retriever = ?';
            $params[] = $this->normalizeRetriever((string)$fields['retriever']);
        }
        if (array_key_exists('meta', $fields) && is_array($fields['meta'])) {
            $metaJson = json_encode($fields['meta'], JSON_UNESCAPED_UNICODE);
            if ($metaJson === false) {
                $metaJson = '{}';
            }
            $sets[] = 'meta_json = ?';
            $params[] = $metaJson;
        }
        if (array_key_exists('status', $fields)) {
            $status = (string)$fields['status'];
            if (!in_array($status, ['active', 'draft'], true)) {
                throw new \InvalidArgumentException('status 仅支持 active|draft（归档请用 archiveBase）');
            }
            $sets[] = 'status = ?';
            $params[] = $status;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = ?';
        $params[] = $this->now();
        $params[] = $baseId;
        $this->storage->execute(
            'UPDATE ai_knowledge_base SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    public function getBase(string $baseId): ?array
    {
        $rows = $this->storage->query('SELECT * FROM ai_knowledge_base WHERE id = ?', [$baseId]);
        return $rows[0] ?? null;
    }

    public function getBaseByCode(string $code): ?array
    {
        $rows = $this->storage->query('SELECT * FROM ai_knowledge_base WHERE code = ?', [$code]);
        return $rows[0] ?? null;
    }

    /** @return array<int, array> */
    public function listBases(?string $ownerId = null): array
    {
        if ($ownerId === null || $ownerId === '') {
            return $this->storage->query('SELECT * FROM ai_knowledge_base ORDER BY id ASC');
        }
        return $this->storage->query(
            'SELECT * FROM ai_knowledge_base WHERE owner_id = ? ORDER BY id ASC',
            [$ownerId]
        );
    }

    /**
     * 列表卡片用：带文档数 / 词量粗估 / 启用中的绑定数
     *
     * @return array<int, array>
     */
    public function listBasesWithStats(?string $ownerId = null): array
    {
        $bases = $this->listBases($ownerId);
        $out = [];
        foreach ($bases as $base) {
            $out[] = $this->enrichBaseStats($base);
        }
        return $out;
    }

    /**
     * 单库详情（含统计）
     *
     * @return array|null
     */
    public function getBaseWithStats(string $baseId): ?array
    {
        $base = $this->getBase($baseId);
        if ($base === null) {
            return null;
        }
        return $this->enrichBaseStats($base);
    }

    /**
     * 某库的绑定列表（含禁用，供设置页管理）
     *
     * @return array<int, array>
     */
    public function bindingsOfBase(string $baseId, ?string $env = null): array
    {
        if ($env === null || $env === '') {
            return $this->storage->query(
                'SELECT * FROM ai_knowledge_binding WHERE base_id = ? ORDER BY id ASC',
                [$baseId]
            );
        }
        return $this->storage->query(
            'SELECT * FROM ai_knowledge_binding WHERE base_id = ? AND env = ? ORDER BY id ASC',
            [$baseId, $env]
        );
    }

    /**
     * 启用/禁用一条绑定
     */
    public function setBindingEnabled(string $bindingId, bool $enabled, string $updatedBy): void
    {
        $rows = $this->storage->query('SELECT id FROM ai_knowledge_binding WHERE id = ?', [$bindingId]);
        if ($rows === []) {
            throw new \InvalidArgumentException("绑定不存在：{$bindingId}");
        }
        $this->storage->execute(
            'UPDATE ai_knowledge_binding SET is_enabled = ?, updated_by = ?, updated_at = ? WHERE id = ?',
            [$enabled ? 1 : 0, $updatedBy, $this->now(), $bindingId]
        );
    }

    /**
     * 分段预览（与 ingestText 同一拆段规则；不落库）
     *
     * @param array $chunkOpt TextChunker 选项；可与库 meta.chunk 合并
     * @return array{chunks:string[],count:int,word_count:int}
     */
    public function previewChunks(string $text, array $chunkOpt = []): array
    {
        $chunks = $this->splitChunks($text, $chunkOpt);
        $words = 0;
        foreach ($chunks as $c) {
            $words += $this->estimateWords($c);
        }
        return [
            'chunks' => $chunks,
            'count' => count($chunks),
            'word_count' => $words,
        ];
    }

    /**
     * 解析库上的 meta_json
     *
     * @return array
     */
    public function baseMeta(string $baseId): array
    {
        $base = $this->requireBase($baseId);
        $raw = (string)($base['meta_json'] ?? '{}');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 纯文本入库并分段（默认读库 meta.chunk；可用 $opt['chunk'] 覆盖）
     *
     * @param array{source_type?:string,source_ref?:string,chunk?:array} $opt
     * @return string document id
     */
    public function ingestText(string $baseId, string $title, string $text, bool $enabled = true, array $opt = []): string
    {
        $base = $this->requireBase($baseId);
        $meta = json_decode((string)($base['meta_json'] ?? '{}'), true);
        $chunkOpt = [];
        if (is_array($meta) && isset($meta['chunk']) && is_array($meta['chunk'])) {
            $chunkOpt = $meta['chunk'];
        }
        if (isset($opt['chunk']) && is_array($opt['chunk'])) {
            $chunkOpt = array_merge($chunkOpt, $opt['chunk']);
        }
        $chunks = $this->splitChunks($text, $chunkOpt);
        $docId = Id::gen();
        $now = $this->now();
        $wordCount = 0;
        foreach ($chunks as $c) {
            $wordCount += $this->estimateWords($c);
        }
        $sourceType = (string)($opt['source_type'] ?? 'text');
        $sourceRef = (string)($opt['source_ref'] ?? '');

        $this->storage->transaction(function (StorageInterface $s) use (
            $docId, $baseId, $title, $enabled, $wordCount, $now, $chunks, $sourceType, $sourceRef
        ) {
            $s->execute(
                'INSERT INTO ai_knowledge_document
                 (id, base_id, title, source_type, source_ref, status, enabled, word_count, error, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $docId,
                    $baseId,
                    $title,
                    $sourceType,
                    $sourceRef,
                    'ready',
                    $enabled ? 1 : 0,
                    $wordCount,
                    null,
                    $now,
                    $now,
                ]
            );
            foreach ($chunks as $i => $content) {
                $s->execute(
                    'INSERT INTO ai_knowledge_chunk (id, document_id, base_id, position, content, token_estimate, created_at)
                     VALUES (?,?,?,?,?,?,?)',
                    [
                        Id::gen(),
                        $docId,
                        $baseId,
                        $i,
                        $content,
                        $this->estimateWords($content),
                        $now,
                    ]
                );
            }
        });

        return $docId;
    }

    /**
     * 本地文本文件入库（.txt / .md / .csv / .json / .log 等纯文本；不做 PDF/Office 解析）
     *
     * @return string document id
     */
    public function ingestFile(string $baseId, string $path, ?string $title = null, bool $enabled = true): string
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("文件不可读：{$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException("读取失败：{$path}");
        }
        // 去掉 UTF-8 BOM；其它编码原样交给拆段（宿主可先转码）
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        $title = $title !== null && $title !== '' ? $title : basename($path);
        return $this->ingestText($baseId, $title, $raw, $enabled, [
            'source_type' => 'file',
            'source_ref' => $path,
        ]);
    }

    public function setDocumentEnabled(string $documentId, bool $enabled): void
    {
        $rows = $this->storage->query('SELECT id FROM ai_knowledge_document WHERE id = ?', [$documentId]);
        if ($rows === []) {
            throw new \InvalidArgumentException("文档不存在：{$documentId}");
        }
        $this->storage->execute(
            'UPDATE ai_knowledge_document SET enabled = ?, updated_at = ? WHERE id = ?',
            [$enabled ? 1 : 0, $this->now(), $documentId]
        );
    }

    /** @return array<int, array> */
    public function listDocuments(string $baseId): array
    {
        return $this->storage->query(
            'SELECT * FROM ai_knowledge_document WHERE base_id = ? ORDER BY id ASC',
            [$baseId]
        );
    }

    /**
     * 导出已启用文档的分段（供 Python RAG 桥 /v1/index 同步）
     *
     * @return array<int, array{chunk_id:string,content:string,document_id:string,title:string}>
     */
    public function exportChunks(string $baseId): array
    {
        $rows = $this->storage->query(
            'SELECT c.id AS chunk_id, c.content, c.document_id, d.title
             FROM ai_knowledge_chunk c
             INNER JOIN ai_knowledge_document d ON d.id = c.document_id
             WHERE c.base_id = ? AND d.enabled = 1 AND d.status = ?
             ORDER BY c.document_id ASC, c.position ASC',
            [$baseId, 'ready']
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'chunk_id' => (string)$row['chunk_id'],
                'content' => (string)$row['content'],
                'document_id' => (string)$row['document_id'],
                'title' => (string)$row['title'],
            ];
        }
        return $out;
    }

    public function retriever(): RetrieverInterface
    {
        return $this->retriever;
    }

    /**
     * 用 EmbeddingIndexer 为当前库建向量索引（retriever 不必是 EmbeddingRetriever）
     *
     * @return int 写入条数
     */
    public function indexEmbeddings(EmbeddingIndexer $indexer, string $baseId, array $opt = []): int
    {
        $this->requireBase($baseId);
        return $indexer->indexBase($baseId, $opt);
    }

    /**
     * 入口绑定知识库（同一 entry+base+env 幂等覆盖）
     */
    public function bind(string $entryId, string $baseId, string $env, string $updatedBy, bool $enabled = true): string
    {
        $this->requireBase($baseId);
        $env = $env === '' ? 'prod' : $env;
        $existing = $this->storage->query(
            'SELECT id FROM ai_knowledge_binding WHERE entry_id = ? AND base_id = ? AND env = ?',
            [$entryId, $baseId, $env]
        );
        $now = $this->now();
        if ($existing !== []) {
            $id = (string)$existing[0]['id'];
            $this->storage->execute(
                'UPDATE ai_knowledge_binding SET is_enabled = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$enabled ? 1 : 0, $updatedBy, $now, $id]
            );
            return $id;
        }
        $id = Id::gen();
        $this->storage->execute(
            'INSERT INTO ai_knowledge_binding (id, entry_id, base_id, env, is_enabled, updated_by, updated_at)
             VALUES (?,?,?,?,?,?,?)',
            [$id, $entryId, $baseId, $env, $enabled ? 1 : 0, $updatedBy, $now]
        );
        return $id;
    }

    /** @return array<int, array> 该入口已启用的绑定行（含 base） */
    public function bindingsOf(string $entryId, string $env = 'prod'): array
    {
        return $this->storage->query(
            'SELECT b.*, k.code AS base_code, k.name AS base_name, k.status AS base_status
             FROM ai_knowledge_binding b
             INNER JOIN ai_knowledge_base k ON k.id = b.base_id
             WHERE b.entry_id = ? AND b.env = ? AND b.is_enabled = 1
             ORDER BY b.id ASC',
            [$entryId, $env]
        );
    }

    /**
     * 按入口检索：汇总其绑定的 active 库命中结果，按 score 合并截断
     *
     * @return array<int, array>
     */
    public function retrieve(string $entryId, string $query, array $opt = []): array
    {
        $env = (string)($opt['env'] ?? 'prod');
        $topK = max(1, (int)($opt['top_k'] ?? 5));
        $bindings = $this->bindingsOf($entryId, $env);
        $merged = [];
        foreach ($bindings as $b) {
            if (($b['base_status'] ?? '') === 'archived') {
                continue;
            }
            foreach ($this->retriever->retrieve((string)$b['base_id'], $query, ['top_k' => $topK]) as $hit) {
                $merged[] = $hit;
            }
        }
        usort($merged, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            }
            return ($a['score'] < $b['score']) ? 1 : -1;
        });
        return array_slice($merged, 0, $topK);
    }

    /**
     * 直接按库 id 检索（管理端/测试用）
     *
     * @return array<int, array>
     */
    public function retrieveBase(string $baseId, string $query, array $opt = []): array
    {
        $this->requireBase($baseId);
        return $this->retriever->retrieve($baseId, $query, $opt);
    }

    /** @return string[] */
    private function splitChunks(string $text, array $opt = []): array
    {
        return (new TextChunker())->split($text, $opt);
    }

    private function normalizeRetriever(string $retriever): string
    {
        $retriever = trim($retriever);
        if ($retriever === '' || $retriever === 'economy' || $retriever === 'keyword') {
            return 'simple';
        }
        if ($retriever === 'high_quality' || $retriever === 'vector') {
            return 'embedding';
        }
        if (!in_array($retriever, ['simple', 'http', 'embedding', 'hybrid'], true)) {
            throw new \InvalidArgumentException('retriever 仅支持 simple|http|embedding|hybrid');
        }
        return $retriever;
    }

    private function estimateWords(string $content): int
    {
        return max(1, (int)ceil(mb_strlen($content) / 2));
    }

    private function requireBase(string $baseId): array
    {
        $base = $this->getBase($baseId);
        if ($base === null) {
            throw new \InvalidArgumentException("知识库不存在：{$baseId}");
        }
        return $base;
    }

    /** @param array $base */
    private function enrichBaseStats(array $base): array
    {
        $id = (string)$base['id'];
        $docs = $this->storage->query(
            'SELECT COUNT(*) AS c, COALESCE(SUM(word_count), 0) AS w FROM ai_knowledge_document WHERE base_id = ?',
            [$id]
        );
        $binds = $this->storage->query(
            'SELECT COUNT(*) AS c FROM ai_knowledge_binding WHERE base_id = ? AND is_enabled = 1',
            [$id]
        );
        $base['doc_count'] = (int)($docs[0]['c'] ?? 0);
        $base['word_count'] = (int)($docs[0]['w'] ?? 0);
        $base['binding_count'] = (int)($binds[0]['c'] ?? 0);
        return $base;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}