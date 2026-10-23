# aiflow Python RAG 桥

零强制依赖（仅 Python 3.6+ 标准库）。默认内存 TF-IDF，供 PHP `HttpRagRetriever` 调用。

## 启动

```bash
python server.py
# 或
AIFLOW_RAG_HOST=127.0.0.1 AIFLOW_RAG_PORT=8765 AIFLOW_RAG_TOKEN=secret python server.py
```

健康检查：`curl http://127.0.0.1:8765/v1/health`

## 与 PHP 联调

```bash
# 终端 1
python plugins/python-rag/server.py

# 终端 2
php examples/rag.php
```

协议细节见 [protocol.md](protocol.md)。日后可把 `/v1/retrieve` 后端换成真实 Embedding，而不改 PHP 契约。
