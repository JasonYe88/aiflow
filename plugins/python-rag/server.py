#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""aiflow Python RAG 桥：stdlib HTTP + 内存 TF-IDF（无强制 pip 依赖）

运行：
  python server.py                  # 默认 127.0.0.1:8765
  AIFLOW_RAG_HOST=0.0.0.0 AIFLOW_RAG_PORT=8765 python server.py

协议见同目录 protocol.md
"""
from __future__ import print_function

import json
import math
import os
import re
import sys
from collections import Counter, defaultdict

try:
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
except ImportError:  # pragma: no cover - Python 2 不支持
    raise SystemExit("需要 Python 3.6+")


VERSION = "0.1.0"
HOST = os.environ.get("AIFLOW_RAG_HOST", "127.0.0.1")
PORT = int(os.environ.get("AIFLOW_RAG_PORT", "8765"))
TOKEN = os.environ.get("AIFLOW_RAG_TOKEN", "")

# base_id -> list of chunk dicts
_CORPUS = {}


def _tokenize(text):
    text = (text or "").lower()
    # 空白/标点切分 + 中文二字邻接（与 PHP SimpleKeywordRetriever 对齐思路）
    parts = re.split(r"[\s\W_]+", text, flags=re.UNICODE)
    tokens = [p for p in parts if len(p) >= 2]
    cjk = re.findall(r"[\u4e00-\u9fff]", text)
    for i in range(len(cjk) - 1):
        tokens.append(cjk[i] + cjk[i + 1])
    if text.strip() and len(text.strip()) >= 2:
        tokens.append(text.strip())
    return tokens


def _tfidf_scores(query, chunks):
    if not chunks:
        return []
    docs_tokens = [_tokenize(c.get("content", "")) for c in chunks]
    df = Counter()
    for toks in docs_tokens:
        for t in set(toks):
            df[t] += 1
    n = float(len(chunks))
    idf = {t: math.log((n + 1.0) / (df[t] + 1.0)) + 1.0 for t in df}

    def vec(toks):
        tf = Counter(toks)
        return {t: (tf[t] / float(len(toks) or 1)) * idf.get(t, 0.0) for t in tf}

    qv = vec(_tokenize(query))
    if not qv:
        return []

    def cosine(a, b):
        keys = set(a) | set(b)
        dot = sum(a.get(k, 0.0) * b.get(k, 0.0) for k in keys)
        na = math.sqrt(sum(v * v for v in a.values())) or 1e-9
        nb = math.sqrt(sum(v * v for v in b.values())) or 1e-9
        return dot / (na * nb)

    scored = []
    for chunk, toks in zip(chunks, docs_tokens):
        score = cosine(qv, vec(toks))
        if score <= 0:
            continue
        scored.append((score, chunk))
    scored.sort(key=lambda x: x[0], reverse=True)
    return scored


class Handler(BaseHTTPRequestHandler):
    server_version = "AIFlowRag/" + VERSION

    def log_message(self, fmt, *args):
        sys.stderr.write("[rag] %s - %s\n" % (self.address_string(), fmt % args))

    def _path(self):
        return self.path.split("?", 1)[0].rstrip("/") or "/"

    def _auth_ok(self):
        if not TOKEN:
            return True
        auth = self.headers.get("Authorization", "")
        return auth == "Bearer " + TOKEN

    def _read_json(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"
        if not raw:
            return {}
        return json.loads(raw.decode("utf-8"))

    def _send(self, code, payload):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self._path() == "/v1/health":
            return self._send(200, {"ok": True, "version": VERSION, "bases": list(_CORPUS.keys())})
        return self._send(404, {"error": "not_found", "path": self._path()})

    def do_DELETE(self):
        if not self._auth_ok():
            return self._send(401, {"error": "unauthorized"})
        m = re.match(r"^/v1/index/([^/]+)$", self._path())
        if not m:
            return self._send(404, {"error": "not_found"})
        base_id = m.group(1)
        _CORPUS.pop(base_id, None)
        return self._send(200, {"ok": True, "base_id": base_id})

    def do_POST(self):
        path = self._path()
        try:
            data = self._read_json()
        except ValueError:
            return self._send(400, {"error": "invalid_json"})

        if path == "/v1/health":
            return self._send(200, {"ok": True, "version": VERSION})

        if not self._auth_ok():
            return self._send(401, {"error": "unauthorized"})

        if path == "/v1/index":
            base_id = str(data.get("base_id") or "")
            chunks = data.get("chunks") or []
            if not base_id:
                return self._send(400, {"error": "base_id_required"})
            if not isinstance(chunks, list):
                return self._send(400, {"error": "chunks_must_be_list"})
            replace = bool(data.get("replace", True))
            normalized = []
            for c in chunks:
                if not isinstance(c, dict):
                    continue
                content = str(c.get("content") or "")
                if not content:
                    continue
                normalized.append(
                    {
                        "chunk_id": str(c.get("chunk_id") or ""),
                        "content": content,
                        "document_id": str(c.get("document_id") or ""),
                        "base_id": base_id,
                        "title": str(c.get("title") or ""),
                    }
                )
            if replace or base_id not in _CORPUS:
                _CORPUS[base_id] = normalized
            else:
                _CORPUS[base_id].extend(normalized)
            return self._send(
                200,
                {"ok": True, "base_id": base_id, "count": len(_CORPUS[base_id])},
            )

        if path == "/v1/retrieve":
            base_id = str(data.get("base_id") or "")
            query = str(data.get("query") or "")
            top_k = max(1, int(data.get("top_k") or 5))
            if not base_id or not query:
                return self._send(400, {"error": "base_id_and_query_required"})
            scored = _tfidf_scores(query, _CORPUS.get(base_id, []))
            hits = []
            for score, chunk in scored[:top_k]:
                hits.append(
                    {
                        "chunk_id": chunk["chunk_id"],
                        "content": chunk["content"],
                        "score": round(float(score), 4),
                        "document_id": chunk["document_id"],
                        "base_id": chunk["base_id"],
                        "title": chunk["title"],
                    }
                )
            return self._send(200, {"hits": hits})

        return self._send(404, {"error": "not_found"})


def main():
    httpd = ThreadingHTTPServer((HOST, PORT), Handler)
    print("aiflow python-rag %s listening on http://%s:%s" % (VERSION, HOST, PORT), flush=True)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nbye", flush=True)


if __name__ == "__main__":
    main()
