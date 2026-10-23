<?php
declare(strict_types=1);

namespace AIFlow\Knowledge;

use AIFlow\Contract\HttpClientInterface;
use AIFlow\Exception\RagException;

/**
 * Python RAG 桥 HTTP 客户端（协议见 plugins/python-rag/protocol.md）
 */
final class HttpRagClient
{
    /** @var HttpClientInterface */
    private $http;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $token;

    public function __construct(HttpClientInterface $http, string $baseUrl, string $token = '')
    {
        $this->http = $http;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    public function health(): array
    {
        return $this->json('GET', '/v1/health', null);
    }

    /**
     * @param array<int, array{chunk_id?:string,content:string,document_id?:string,title?:string}> $chunks
     */
    public function index(string $baseId, array $chunks, bool $replace = true): array
    {
        return $this->json('POST', '/v1/index', [
            'base_id' => $baseId,
            'replace' => $replace,
            'chunks' => array_values($chunks),
        ]);
    }

    public function clear(string $baseId): array
    {
        return $this->json('DELETE', '/v1/index/' . rawurlencode($baseId), null);
    }

    /**
     * @return array<int, array{chunk_id:string,content:string,score:float,document_id:string,base_id:string,title:string}>
     */
    public function retrieve(string $baseId, string $query, int $topK = 5): array
    {
        $data = $this->json('POST', '/v1/retrieve', [
            'base_id' => $baseId,
            'query' => $query,
            'top_k' => $topK,
        ]);
        $hits = $data['hits'] ?? [];
        if (!is_array($hits)) {
            throw new RagException('RAG 响应 hits 非法');
        }
        $out = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $out[] = [
                'chunk_id' => (string)($hit['chunk_id'] ?? ''),
                'content' => (string)($hit['content'] ?? ''),
                'score' => (float)($hit['score'] ?? 0),
                'document_id' => (string)($hit['document_id'] ?? ''),
                'base_id' => (string)($hit['base_id'] ?? $baseId),
                'title' => (string)($hit['title'] ?? ''),
            ];
        }
        return $out;
    }

    /** @param array|null $payload */
    private function json(string $method, string $path, $payload): array
    {
        $headers = ['Accept: application/json'];
        $body = '';
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new RagException('RAG 请求 JSON 编码失败');
            }
            $body = $encoded;
        }
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $resp = $this->http->request($method, $this->baseUrl . $path, $headers, $body);
        if ($resp->statusCode < 200 || $resp->statusCode >= 300) {
            throw new RagException(
                'RAG HTTP ' . $resp->statusCode . '：' . mb_substr($resp->body, 0, 200)
            );
        }
        $data = json_decode($resp->body, true);
        if (!is_array($data)) {
            throw new RagException('RAG 响应非 JSON');
        }
        return $data;
    }
}
