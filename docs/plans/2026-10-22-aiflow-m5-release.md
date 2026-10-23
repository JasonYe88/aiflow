# aiflow M5：开源发布实施计划

> 创建：2026-10-22  
> 约束：**不代推 GitHub / 不代打 tag**（由仓库所有者后续统一提交）；本里程碑把包做到「可立刻提交 Packagist」。

---

## 目标

| 项 | 验收 |
|----|------|
| Packagist 就绪 | `composer.json` 元数据完整；`.gitattributes` 精简 dist；`CHANGELOG` + 发布清单 |
| README 门面 | 10 秒装包示例置顶；能力矩阵含知识库；状态标 M5 |
| Python RAG 桥 | `plugins/python-rag/` 零重度依赖可启动；HTTP 协议文档 |
| PHP 桥接 | `HttpRagClient` + `HttpRagRetriever`；Kernel `knowledge.retriever=http` |
| 示例 / 测试 | `examples/rag.php`；FakeHttp 单测；CI 不强制起 Python |

---

## 明确不做（本阶段）

- 不代替用户在 packagist.org 点 Submit（需 GitHub 登录 + 推送 tag）
- 不做真实 Embedding 模型捆绑（桥内默认 TF-IDF；可日后换向量后端）
- 不做 Go SSE 网关
- 不做 Gaia / FMS 内容运营

---

## Task

| ID | 内容 | 状态 |
|----|------|------|
| R1 | composer 元数据 / `.gitattributes` / `CHANGELOG.md` / `docs/release.md` | ✅ |
| R2 | README + integration / docs 中心标 M5 | ✅ |
| R3 | `plugins/python-rag`（stdlib HTTP + TF-IDF） | ✅ |
| R4 | PHP `HttpRagClient` / `HttpRagRetriever` + Kernel 装配 | ✅ |
| R5 | 测试 + `examples/rag.php`（离线 + 可选真桥） | ✅ |

---

## 你方后续（本仓库不自动执行）

```bash
# 1) 你本地审阅后提交并推送
git tag v0.1.0
git push origin master --tags

# 2) https://packagist.org/packages/submit
#    仓库 URL: https://github.com/JasonYe88/aiflow
# 3) 宿主验证
composer require jasonye88/aiflow:^0.1
```
