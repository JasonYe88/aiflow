# Python RAG HTTP 协议（v1）

Base URL 默认：`http://127.0.0.1:8765`  
可选鉴权：请求头 `Authorization: Bearer <AIFLOW_RAG_TOKEN>`（未设环境变量则不校验）。

## `GET /v1/health`

```json
{"ok": true, "version": "0.1.0", "bases": ["..."]}
```

## `POST /v1/index`

写入（默认替换）某知识库的分段。

```json
{
  "base_id": "kb_xxx",
  "replace": true,
  "chunks": [
    {
      "chunk_id": "c1",
      "content": "三段文本",
      "document_id": "d1",
      "title": "标题"
    }
  ]
}
```

响应：`{"ok": true, "base_id": "...", "count": 1}`

## `POST /v1/retrieve`

```json
{"base_id": "kb_xxx", "query": "用户问题", "top_k": 5}
```

响应：

```json
{
  "hits": [
    {
      "chunk_id": "c1",
      "content": "...",
      "score": 0.42,
      "document_id": "d1",
      "base_id": "kb_xxx",
      "title": "标题"
    }
  ]
}
```

## `DELETE /v1/index/{base_id}`

清空该库内存索引。
