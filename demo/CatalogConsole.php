<?php
declare(strict_types=1);

namespace AIFlow\Demo;

use AIFlow\AIKernel;
use AIFlow\Catalog\CatalogService;
use AIFlow\Exception\PermissionDeniedException;
use AIFlow\Knowledge\KnowledgeContext;

/**
 * 编目控制台：对话、技能、工具、入口绑定、规则、工作流、知识库
 * 知识库：列表卡片 → 单库（文档 / 召回测试 / 设置）；对话只消费绑定（不自动 seed）。
 * 对话/会话/钱包仍交给 ChatDemoPage（含知识库检索注入）。工作流：草稿、发布、运行。
 */
final class CatalogConsole
{
    /** @var AIKernel */
    private static $kernel;
    /** @var string */
    private static $userId;
    /** @var array */
    private static $options;

    public static function run(AIKernel $kernel, $userId, array $options = array())
    {
        self::$kernel = $kernel;
        self::$userId = ($userId === '' ? 'demo_user' : (string)$userId);
        // L4：对话只消费绑定；运营灌库在知识库页，不自动 seed
        self::$options = array_merge(array('knowledge_seed' => false), $options);
        $options = self::$options;
        $act = isset($_GET['act']) ? strtolower(trim((string)$_GET['act'])) : 'index';
        switch ($act) {
            case 'index':
                self::page();
                return;
            case 'skills':
                self::guarded(function () { return self::catalog()->skills(); });
                return;
            case 'skillsave':
                self::guarded(function () { return self::saveSkill(); });
                return;
            case 'tools':
                self::guarded(function () { return self::catalog()->tools(); });
                return;
            case 'toolsave':
                self::guarded(function () {
                    return self::catalog()->saveTool(self::field('tool_id'), self::field('name'), self::field('description'), self::field('is_async') === '1' ? 1 : 0, self::field('php_ref'));
                });
                return;
            case 'entries':
                self::guarded(function () { return self::catalog()->entries(); });
                return;
            case 'entrybind':
                self::guarded(function () {
                    return self::catalog()->bindEntry(self::field('entry_id'), self::field('skill_id'), self::field('is_production') === '0' ? 0 : 1, self::field('is_enabled') === '0' ? 0 : 1, self::$userId);
                });
                return;
            case 'rule':
                self::guarded(function () { return self::catalog()->ruleOf(self::$userId); });
                return;
            case 'rulesave':
                self::guarded(function () {
                    if (self::field('clear') === '1') {
                        return self::catalog()->clearRule(self::$userId);
                    }
                    return self::catalog()->saveRule(self::$userId, self::field('dont'), self::field('prefer'));
                });
                return;
            case 'workflows':
                self::guarded(function () {
                    return self::attachGraph(self::catalog()->workflows(self::field('name'), self::field('status'), self::field('template')));
                });
                return;
            case 'workflowsave':
                self::guarded(function () {
                    $row = self::catalog()->createWorkflow(self::$userId, self::field('name'), self::field('tag'), self::field('description'), self::field('status') === 'online' ? 'online' : 'offline', self::field('is_template') === '1' ? 1 : 0);
                    self::$kernel->graph()->saveDraft($row['id'], self::defaultGraph());
                    return $row;
                });
                return;
            case 'workflowcopy':
                self::guarded(function () {
                    $sourceId = self::field('id');
                    $row = self::catalog()->copyWorkflow(self::$userId, $sourceId);
                    $graph = self::$kernel->graph()->draft($sourceId);
                    if ($graph === null) {
                        $graph = self::$kernel->graph()->published($sourceId);
                    }
                    self::$kernel->graph()->saveDraft($row['id'], $graph === null ? self::defaultGraph() : $graph);
                    return $row;
                });
                return;
            case 'workflowgraph':
                self::guarded(function () {
                    $id = self::requireWorkflowId();
                    $draft = self::$kernel->graph()->draft($id);
                    if ($draft === null && self::$kernel->graph()->published($id) === null) {
                        self::$kernel->graph()->saveDraft($id, self::defaultGraph());
                        $draft = self::$kernel->graph()->draft($id);
                    }
                    return array('draft' => $draft, 'published' => self::$kernel->graph()->published($id));
                });
                return;
            case 'workflowgraphsave':
                self::guarded(function () {
                    $id = self::requireWorkflowId();
                    $raw = self::field('graph');
                    $graph = json_decode($raw, true);
                    if (!is_array($graph)) {
                        throw new \InvalidArgumentException('图必须是 JSON 对象');
                    }
                    self::$kernel->graph()->saveDraft($id, $graph);
                    return array('draft' => self::$kernel->graph()->draft($id));
                });
                return;
            case 'workflowpublish':
                self::guarded(function () {
                    $id = self::requireWorkflowId();
                    self::$kernel->graph()->publish($id);
                    return array('published' => self::$kernel->graph()->published($id));
                });
                return;
            case 'workflowrun':
                self::guarded(function () {
                    $id = self::requireWorkflowId();
                    $raw = self::field('input');
                    $input = array();
                    if ($raw !== '') {
                        $input = json_decode($raw, true);
                        if (!is_array($input)) {
                            throw new \InvalidArgumentException('输入必须是 JSON 对象');
                        }
                    }
                    $result = self::$kernel->graph()->runPublished($id, $input);
                    return array('run_id' => $result->runId, 'status' => $result->status, 'context' => $result->context);
                });
                return;
            case 'knowledgebases':
                self::guarded(function () {
                    return self::$kernel->knowledge()->listBasesWithStats();
                });
                return;
            case 'knowledgebase':
                self::guarded(function () {
                    $baseId = self::field('base_id');
                    if ($baseId === '') {
                        throw new \InvalidArgumentException('base_id 不能为空');
                    }
                    $row = self::$kernel->knowledge()->getBaseWithStats($baseId);
                    if ($row === null) {
                        throw new \InvalidArgumentException('知识库不存在');
                    }
                    return $row;
                });
                return;
            case 'knowledgedocs':
                self::guarded(function () {
                    $baseId = self::field('base_id');
                    if ($baseId === '') {
                        throw new \InvalidArgumentException('base_id 不能为空');
                    }
                    return self::$kernel->knowledge()->listDocuments($baseId);
                });
                return;
            case 'knowledgedocenable':
                self::guarded(function () {
                    $docId = self::field('document_id');
                    if ($docId === '') {
                        throw new \InvalidArgumentException('document_id 不能为空');
                    }
                    $on = self::field('enabled') !== '0';
                    self::$kernel->knowledge()->setDocumentEnabled($docId, $on);
                    return array('document_id' => $docId, 'enabled' => $on ? 1 : 0);
                });
                return;
            case 'knowledgebindings':
                self::guarded(function () {
                    $baseId = self::field('base_id');
                    if ($baseId !== '') {
                        return self::$kernel->knowledge()->bindingsOfBase($baseId);
                    }
                    $entry = self::field('entry_id');
                    if ($entry === '') {
                        $entry = 'app:ai_chat_demo';
                    }
                    return self::$kernel->knowledge()->bindingsOf($entry, 'prod');
                });
                return;
            case 'knowledgecreate':
                self::guarded(function () {
                    $kb = self::$kernel->knowledge();
                    $name = self::field('name');
                    $code = self::field('code');
                    if ($name === '') {
                        throw new \InvalidArgumentException('名称不能为空');
                    }
                    if ($code === '') {
                        $code = 'kb.' . preg_replace('/[^a-z0-9]+/i', '_', $name) . '.' . substr(md5(uniqid('', true)), 0, 6);
                    }
                    $meta = self::parseKnowledgeMetaFromRequest();
                    $indexing = isset($meta['indexing']) ? (string)$meta['indexing'] : 'economy';
                    $retriever = self::field('retriever');
                    if ($retriever === '') {
                        $retriever = $indexing === 'high_quality' ? 'embedding' : 'simple';
                        $mode = isset($meta['retrieval']['mode']) ? (string)$meta['retrieval']['mode'] : '';
                        if ($mode === 'hybrid') {
                            $retriever = 'hybrid';
                        } elseif ($mode === 'keyword') {
                            $retriever = 'simple';
                        }
                    }
                    // 未配置向量通道时库字段仍记意图，实际检索走内核全局 retriever
                    $driver = (string)self::$kernel->config()->get('knowledge.retriever', 'simple');
                    if (($retriever === 'embedding' || $retriever === 'hybrid') && !in_array($driver, array('embedding', 'hybrid'), true)) {
                        // 保留 meta.indexing；列值用 simple 避免误解已启用向量
                        $meta['retriever_intent'] = $retriever;
                        $retriever = 'simple';
                    }
                    $id = $kb->createBase($code, $name, self::$userId, self::field('description'), $retriever, $meta);
                    return $kb->getBaseWithStats($id);
                });
                return;
            case 'knowledgeupdate':
                self::guarded(function () {
                    $kb = self::$kernel->knowledge();
                    $baseId = self::field('base_id');
                    if ($baseId === '') {
                        throw new \InvalidArgumentException('base_id 不能为空');
                    }
                    $fields = array();
                    if (self::field('name') !== '') {
                        $fields['name'] = self::field('name');
                    }
                    if (array_key_exists('description', $_POST) || array_key_exists('description', $_GET)) {
                        $fields['description'] = self::field('description');
                    }
                    $kb->updateBase($baseId, $fields);
                    return $kb->getBaseWithStats($baseId);
                });
                return;
            case 'knowledgeingest':
                self::guarded(function () {
                    $kb = self::$kernel->knowledge();
                    $baseId = self::field('base_id');
                    $title = self::field('title');
                    $text = self::field('text');
                    if ($baseId === '' || $text === '') {
                        throw new \InvalidArgumentException('base_id / text 不能为空');
                    }
                    if ($title === '') {
                        $title = '文档-' . date('His');
                    }
                    $docId = $kb->ingestText($baseId, $title, $text, true);
                    return array('document_id' => $docId);
                });
                return;
            case 'knowledgeingestfile':
                self::guarded(function () {
                    $kb = self::$kernel->knowledge();
                    $baseId = self::field('base_id');
                    if ($baseId === '') {
                        throw new \InvalidArgumentException('base_id 不能为空');
                    }
                    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                        throw new \InvalidArgumentException('请上传文件');
                    }
                    $f = $_FILES['file'];
                    if (!isset($f['error']) || (int)$f['error'] !== UPLOAD_ERR_OK) {
                        throw new \InvalidArgumentException('上传失败（error=' . (isset($f['error']) ? $f['error'] : '?') . '）');
                    }
                    $name = isset($f['name']) ? (string)$f['name'] : 'upload.txt';
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allow = array('txt', 'md', 'markdown', 'csv', 'log', 'json');
                    if (!in_array($ext, $allow, true)) {
                        throw new \InvalidArgumentException('仅支持 TXT/MD/CSV/JSON/LOG，当前：' . $ext);
                    }
                    $tmp = (string)$f['tmp_name'];
                    $title = self::field('title');
                    if ($title === '') {
                        $title = $name;
                    }
                    $docId = $kb->ingestFile($baseId, $tmp, $title, true);
                    return array('document_id' => $docId, 'title' => $title);
                });
                return;
            case 'knowledgepreview':
                self::guarded(function () {
                    $text = self::field('text');
                    if ($text === '') {
                        throw new \InvalidArgumentException('text 不能为空');
                    }
                    $meta = self::parseKnowledgeMetaFromRequest();
                    $chunk = isset($meta['chunk']) && is_array($meta['chunk']) ? $meta['chunk'] : array();
                    return self::$kernel->knowledge()->previewChunks($text, $chunk);
                });
                return;
            case 'knowledgebind':
                self::guarded(function () {
                    $kb = self::$kernel->knowledge();
                    $entry = self::field('entry_id');
                    $baseId = self::field('base_id');
                    if ($entry === '') {
                        $entry = 'app:ai_chat_demo';
                    }
                    if ($baseId === '') {
                        throw new \InvalidArgumentException('base_id 不能为空');
                    }
                    $enabled = self::field('enabled') !== '0';
                    $bindId = $kb->bind($entry, $baseId, 'prod', self::$userId, $enabled);
                    return array('id' => $bindId, 'entry_id' => $entry, 'base_id' => $baseId, 'is_enabled' => $enabled ? 1 : 0);
                });
                return;
            case 'knowledgebindingenable':
                self::guarded(function () {
                    $id = self::field('binding_id');
                    if ($id === '') {
                        throw new \InvalidArgumentException('binding_id 不能为空');
                    }
                    $on = self::field('enabled') !== '0';
                    self::$kernel->knowledge()->setBindingEnabled($id, $on, self::$userId);
                    return array('binding_id' => $id, 'enabled' => $on ? 1 : 0);
                });
                return;
            case 'knowledgeseed':
                self::guarded(function () {
                    return self::seedDemoKnowledge();
                });
                return;
            case 'knowledgetest':
                self::guarded(function () {
                    $query = self::field('query');
                    if ($query === '') {
                        throw new \InvalidArgumentException('query 不能为空');
                    }
                    $topK = max(1, (int)(self::field('top_k') !== '' ? self::field('top_k') : '5'));
                    $baseId = self::field('base_id');
                    $kb = self::$kernel->knowledge();
                    if ($baseId !== '') {
                        $hits = $kb->retrieveBase($baseId, $query, array('top_k' => $topK));
                    } else {
                        $entry = self::field('entry_id');
                        if ($entry === '') {
                            $entry = 'app:ai_chat_demo';
                        }
                        $hits = $kb->retrieve($entry, $query, array('top_k' => $topK));
                    }
                    return array(
                        'hits' => $hits,
                        'context' => KnowledgeContext::toText($hits),
                        'count' => count($hits),
                    );
                });
                return;
            default:
                ChatDemoPage::run($kernel, self::$userId, $options);
        }
    }

    /**
     * 从请求解析分段/索引/检索配置（对齐 Gaia Step2）
     * @return array
     */
    private static function parseKnowledgeMetaFromRequest()
    {
        $raw = self::field('meta_json');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $sep = self::field('chunk_separator');
        if ($sep === '') {
            $sep = '\n\n';
        }
        $max = (int)(self::field('chunk_max') !== '' ? self::field('chunk_max') : '500');
        $overlap = (int)(self::field('chunk_overlap') !== '' ? self::field('chunk_overlap') : '50');
        $indexing = self::field('indexing');
        if ($indexing !== 'high_quality') {
            $indexing = 'economy';
        }
        $retrieval = self::field('retrieval_mode');
        if (!in_array($retrieval, array('vector', 'keyword', 'hybrid'), true)) {
            $retrieval = $indexing === 'high_quality' ? 'vector' : 'keyword';
        }
        $topK = max(1, (int)(self::field('top_k') !== '' ? self::field('top_k') : '3'));
        $score = (float)(self::field('score_threshold') !== '' ? self::field('score_threshold') : '0.5');
        return array(
            'chunk' => array(
                'separator' => $sep,
                'max_length' => $max,
                'overlap' => $overlap,
                'clean_whitespace' => self::field('clean_whitespace') !== '0',
                'strip_urls' => self::field('strip_urls') === '1',
                'mode' => 'general',
            ),
            'indexing' => $indexing,
            'retrieval' => array(
                'mode' => $retrieval,
                'top_k' => $topK,
                'score_threshold' => $score,
                'rerank' => self::field('rerank') === '1',
            ),
        );
    }

    /**
     * 灌入演示 FAQ 并绑定对话入口（与 ChatDemoPage 种子一致）
     * @return array
     */
    private static function seedDemoKnowledge()
    {
        $kb = self::$kernel->knowledge();
        $entryId = 'app:ai_chat_demo';
        $code = 'demo.chat_faq';
        $base = $kb->getBaseByCode($code);
        if ($base === null) {
            $baseId = $kb->createBase($code, '演示FAQ', self::$userId, '编目控制台一键灌入');
            $docId = $kb->ingestText(
                $baseId,
                '退货与余额',
                "问：退货几天到账？\n答：一般三个工作日入账。\n\n问：如何查余额？\n答：打开钱包页或看页头「余额」即可。"
            );
            $kb->setDocumentEnabled($docId, true);
        } else {
            $baseId = (string)$base['id'];
        }
        $bindId = $kb->bind($entryId, $baseId, 'prod', self::$userId, true);
        return array(
            'base_id' => $baseId,
            'entry_id' => $entryId,
            'bind_id' => $bindId,
            'bindings' => $kb->bindingsOf($entryId, 'prod'),
        );
    }

    private static function catalog()
    {
        return new CatalogService(self::$kernel->storage());
    }

    private static function guarded($fn)
    {
        try {
            self::$kernel->guard()->guard(self::$userId, 'app:ai_chat_demo');
            self::jsonOut(200, 'ok', call_user_func($fn));
        } catch (PermissionDeniedException $e) {
            self::jsonOut(403, '无权访问 ' . $e->resource, null);
        } catch (\InvalidArgumentException $e) {
            self::jsonOut(400, $e->getMessage(), null);
        } catch (\Throwable $e) {
            self::jsonOut(500, $e->getMessage(), null);
        }
    }

    private static function saveSkill()
    {
        $tools = preg_split('/[,，\s]+/u', self::field('tools'));
        if (!is_array($tools)) {
            $tools = array();
        }
        if (self::field('mode') === 'update') {
            return self::catalog()->updateSkill(self::$userId, self::field('skill_id'), self::field('name'), self::field('summary'), self::field('instruction'), $tools);
        }
        return self::catalog()->createSkill(self::$userId, self::field('skill_id'), self::field('name'), self::field('summary'), self::field('instruction'), $tools);
    }

    private static function field($key)
    {
        if (isset($_POST[$key])) {
            return trim((string)$_POST[$key]);
        }
        if (isset($_GET[$key])) {
            return trim((string)$_GET[$key]);
        }
        return '';
    }

    private static function page()
    {
        $base = isset(self::$options['base_url']) ? (string)self::$options['base_url'] : '?act=';
        $title = isset(self::$options['page_title']) ? (string)self::$options['page_title'] : 'AIFlow 编目控制台';
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo <<<'CSS'
<style>
*{box-sizing:border-box}
body{margin:0;height:100vh;display:flex;font-family:"Microsoft YaHei",Arial,sans-serif;background:#f3f5f8;color:#1f2430}
button,input,select,textarea{font:inherit}
.nav{width:200px;background:#fff;border-right:1px solid #e6e8ee;padding:18px 12px;display:flex;flex-direction:column}
.nav h2{font-size:15px;margin:0 10px 14px;font-weight:650}
.nav button{display:block;width:100%;text-align:left;border:0;background:transparent;padding:9px 12px;border-radius:8px;cursor:pointer;color:#3c4454;margin-bottom:2px}
.nav button:hover{background:#f4f6f9}
.nav button.on{background:#e8f1ff;color:#1558c0;font-weight:600}
.nav p{margin-top:auto;font-size:12px;color:#7b8494;padding:12px 10px 4px;line-height:1.6}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.pane{display:none;flex:1;flex-direction:column;min-height:0}
.pane.on{display:flex}
.head{padding:18px 20px 8px}
.head h1{margin:0;font-size:20px;font-weight:650}
.head p{margin:6px 0 0;color:#6b7280;font-size:13px;line-height:1.5}
.pad{padding:4px 20px 24px;overflow:auto}
table{width:100%;border-collapse:collapse;background:#fff;font-size:13px;border:1px solid #e6e8ee;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.04)}
th,td{border-bottom:1px solid #eef0f3;padding:10px 12px;text-align:left;vertical-align:top}
tr:last-child td{border-bottom:0}
th{color:#667085;background:#f8f9fb;font-weight:600}
.rowform{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;align-items:center}
.rowform input,.rowform select,.rowform textarea,select{border:1px solid #d7dbe3;border-radius:8px;padding:7px 10px;background:#fff;color:#1f2430}
.rowform textarea{width:100%;min-height:72px}
.rowform input:focus,.rowform select:focus,.rowform textarea:focus,select:focus,.bar textarea:focus{outline:none;border-color:#8eb4f0;box-shadow:0 0 0 3px rgba(21,88,192,.12)}
button.go{border:0;background:#1558c0;color:#fff;border-radius:8px;padding:7px 14px;cursor:pointer}
button.go:hover{background:#114896}
button.ghost{border:1px solid #d7dbe3;background:#fff;border-radius:8px;padding:6px 10px;cursor:pointer;color:#344054}
button.ghost:hover{background:#f7f8fb}
.topbar{display:flex;gap:8px;align-items:center;padding:4px 20px 10px}
.topbar span{font-size:13px;color:#667085}
.chips{display:flex;gap:8px;padding:0 20px 12px;flex-wrap:wrap}
.chips button{text-align:left;background:#fff;border:1px solid #e6e8ee;border-radius:999px;padding:7px 12px;cursor:pointer;font-size:13px;color:#344054}
.chips button:hover{border-color:#b9cff5;background:#f5f8ff}
.stage{flex:1;display:flex;flex-direction:column;min-height:0;margin:0 20px 16px}
.chat{flex:1;display:flex;min-height:0;background:#fff;border:1px solid #e6e8ee;border-bottom:0;border-radius:14px 14px 0 0;overflow:hidden}
.side{width:240px;background:#fafbfc;border-right:1px solid #eef0f3;overflow:auto;padding:8px}
.side:empty::before{content:"还没有会话";display:block;padding:16px 10px;color:#98a2b3;font-size:13px}
.conv{display:flex;align-items:center;gap:4px;padding:8px 8px 8px 10px;border-radius:8px;cursor:pointer;font-size:13px;color:#243044;line-height:1.4}
.conv-title{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.conv-del{flex:none;width:24px;height:24px;border:0;background:transparent;color:#98a2b3;cursor:pointer;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center}
.conv-del svg{display:block}
.conv-del:hover{color:#d92d20;background:#fde8e8}
.mask{position:fixed;inset:0;background:rgba(16,24,40,.45);z-index:30;display:flex;align-items:center;justify-content:center}
.mask[hidden]{display:none}
.dlg{width:400px;max-width:calc(100vw - 48px);background:#fff;border-radius:12px;box-shadow:0 16px 48px rgba(16,24,40,.18);padding:22px 24px 16px}
.dlg-title{display:flex;align-items:center;gap:8px;font-size:16px;font-weight:650;color:#1f2430}
.warn{width:22px;height:22px;border-radius:50%;background:#f5a524;color:#fff;font-size:14px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex:none;line-height:1}
.dlg-body{margin:10px 0 0 30px;color:#667085;font-size:14px;line-height:1.5}
.dlg-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}
button.danger{border:0;background:#f04438;color:#fff;border-radius:8px;padding:7px 16px;cursor:pointer}
button.danger:hover{background:#d92d20}
.dlg .ghost{padding:7px 16px}
.conv:hover,.conv.on{background:#e8f1ff;color:#1558c0}
.msgs{flex:1;overflow:auto;padding:20px;background:#f7f8fb}
.msgs:empty::before{content:"从下面输入开始，或点左侧一场会话";color:#98a2b3;font-size:13px}
.m{max-width:72%;margin:8px 0;padding:10px 14px;border-radius:14px;white-space:pre-wrap;word-break:break-word;line-height:1.55}
.m.user{background:#1558c0;color:#fff;margin-left:auto;border-bottom-right-radius:4px}
.m.assistant{background:#fff;border:1px solid #e6e8ee;border-bottom-left-radius:4px}
.m.error{background:#fde8e8;color:#b42318}
.bar{display:flex;gap:10px;align-items:flex-end;padding:12px;background:#fff;border:1px solid #e6e8ee;border-radius:0 0 14px 14px}
.bar textarea{flex:1;height:56px;resize:none;border:1px solid #d7dbe3;border-radius:10px;padding:10px 12px}
.kb-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin:0 0 16px}
.kb-card{background:#fff;border:1px solid #e6e8ee;border-radius:12px;padding:16px;cursor:pointer;text-align:left;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.kb-card:hover{border-color:#b9cff5;background:#f8fbff}
.kb-card h3{margin:0 0 6px;font-size:15px;font-weight:650}
.kb-card .meta{font-size:12px;color:#667085;line-height:1.5}
.kb-card.create{border-style:dashed;color:#1558c0;display:flex;flex-direction:column;justify-content:center;min-height:110px}
.kb-sub{display:none}
.kb-sub.on{display:block}
.kb-tabs{display:flex;gap:4px;margin:0 0 14px;border-bottom:1px solid #e6e8ee;padding-bottom:0}
.kb-tabs button{border:0;background:transparent;padding:8px 14px;cursor:pointer;color:#667085;border-bottom:2px solid transparent;margin-bottom:-1px}
.kb-tabs button.on{color:#1558c0;font-weight:600;border-bottom-color:#1558c0}
.kb-hit{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:900px){.kb-hit{grid-template-columns:1fr}}
.kb-hit-box{background:#fff;border:1px solid #e6e8ee;border-radius:12px;padding:12px;min-height:180px}
.kb-chunk{border:1px solid #eef0f3;border-radius:8px;padding:10px;margin:0 0 8px;background:#fafbfc;font-size:13px;white-space:pre-wrap;word-break:break-word}
.kb-chunk .sc{color:#1558c0;font-size:12px;margin-bottom:4px}
.kb-steps{display:flex;gap:18px;align-items:center;margin:0 0 18px;font-size:12px;color:#98a2b3}
.kb-steps .on{color:#1558c0;font-weight:650}
.kb-src{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 14px}
.kb-src button{flex:1;min-width:140px;background:#fff;border:1px solid #e6e8ee;border-radius:12px;padding:14px 12px;cursor:pointer;text-align:left}
.kb-src button.on{border-color:#1558c0;box-shadow:0 0 0 1px #1558c0;background:#f5f8ff}
.kb-src button:disabled{opacity:.55;cursor:not-allowed}
.kb-src strong{display:block;font-size:13px;margin-bottom:4px}
.kb-src span{font-size:11px;color:#98a2b3}
.kb-drop{border:1.5px dashed #d0d5dd;border-radius:14px;background:#fafbfc;padding:28px 16px;text-align:center;color:#667085;cursor:pointer}
.kb-drop.drag{border-color:#1558c0;background:#f5f8ff}
.kb-drop .hint{font-size:12px;color:#98a2b3;margin-top:8px;line-height:1.5}
.kb-drop .file{margin-top:10px;font-size:13px;color:#1558c0;font-weight:600}
.kb-step2{display:grid;grid-template-columns:minmax(280px,1fr) minmax(260px,1fr);gap:14px;align-items:start}
@media(max-width:960px){.kb-step2{grid-template-columns:1fr}}
.kb-panel{background:#fff;border:1px solid #e6e8ee;border-radius:12px;padding:14px}
.kb-panel h4{margin:0 0 10px;font-size:14px}
.kb-opt{border:1px solid #e6e8ee;border-radius:10px;padding:10px 12px;margin:0 0 8px;cursor:pointer}
.kb-opt.on{border-color:#1558c0;background:#f5f8ff}
.kb-opt.disabled{opacity:.55;cursor:not-allowed}
.kb-opt strong{display:block;font-size:13px}
.kb-opt p{margin:4px 0 0;font-size:12px;color:#667085;line-height:1.4}
.kb-field{margin:0 0 10px}
.kb-field label{display:block;font-size:12px;color:#667085;margin-bottom:4px}
.kb-field input[type=text],.kb-field input[type=number]{width:100%;border:1px solid #d7dbe3;border-radius:8px;padding:7px 10px}
.kb-check{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#344054;margin:6px 0}
.kb-preview-empty{color:#98a2b3;font-size:13px;text-align:center;padding:40px 12px}
</style>
CSS;
        echo '</head><body>';
        echo '<aside class="nav"><h2>编目控制台</h2>';
        foreach (array('chat' => '对话', 'knowledge' => '知识库', 'skills' => '技能', 'tools' => '工具', 'entries' => '入口绑定', 'rules' => '规则', 'flows' => '工作流') as $id => $label) {
            echo '<button type="button" data-nav="' . $id . '" onclick="showPane(\'' . $id . '\')">' . $label . '</button>';
        }
        echo '<p>对话只消费已绑定入口 <code>app:ai_chat_demo</code> 的知识库。建库、加文档、召回测试请到「知识库」。</p></aside><div class="main">';
        echo '<section class="pane" data-pane="chat"><div class="head"><h1>编目对话</h1><p>顶栏切换技能只影响本场对话。若入口已绑定知识库且问句命中，消息上方显示 knowledge_hits。运营请到「知识库」。</p></div>';
        echo '<div class="topbar"><span>测试技能</span><select id="skillSel"></select><button class="ghost" type="button" onclick="newConv()">新对话</button></div>';
        echo '<div class="chips"><button type="button" onclick="useChip(\'退货几天到账？\')">试问：退货几天到账</button><button type="button" onclick="useChip(\'如何查余额？\')">试问：如何查余额</button><button type="button" onclick="useChip(\'列出当前已登记的技能和工具\')">列出技能和工具</button></div>';
        echo '<div class="stage"><div class="chat"><div class="side" id="convs"></div><div class="msgs" id="msgs"></div></div>';
        echo '<div class="bar"><textarea id="q" placeholder="例如：退货几天到账？"></textarea><button class="go" type="button" onclick="send()">发送</button></div></div></section>';
        echo '<section class="pane" data-pane="knowledge"><div class="head" id="kbHead"><h1 id="kbTitle">知识库</h1><p id="kbDesc">列表卡片 · 空库 · 单库文档/召回测试/设置。对话只读绑定。</p></div><div class="pad">';
        echo '<div id="kbHome">';
        echo '<div class="rowform"><button class="ghost" type="button" onclick="loadKnowledge()">刷新</button><button class="go" type="button" onclick="openKbCreate()">创建知识库</button><button class="ghost" type="button" onclick="seedKnowledge()">一键演示 FAQ 并绑定对话</button></div>';
        echo '<div class="kb-cards" id="kbCards"></div>';
        echo '</div>';
        echo '<div id="kbCreate" style="display:none;max-width:720px">';
        echo '<div class="rowform"><button class="ghost" type="button" onclick="loadKnowledge()">← 返回列表</button></div>';
        echo '<div class="kb-steps"><span class="on" id="kbStep1Label">STEP 1 选择数据源</span><span id="kbStep2Label">2 文本分段与清洗</span><span id="kbStep3Label">3 处理并完成</span></div>';
        echo '<div id="kbCreateStep1">';
        echo '<h3 style="font-size:15px;margin:0 0 10px">选择数据源</h3>';
        echo '<div class="kb-src">';
        echo '<button type="button" class="on" data-kbsrc="file" onclick="pickKbSrc(\'file\')"><strong>导入已有文本</strong><span>上传本地文件</span></button>';
        echo '<button type="button" data-kbsrc="notion" disabled title="后置"><strong>同步自 Notion 内容</strong><span>后置 · 未接入</span></button>';
        echo '<button type="button" data-kbsrc="web" disabled title="后置"><strong>同步自 Web 站点</strong><span>后置 · 未接入</span></button>';
        echo '</div>';
        echo '<div id="kbSrcFile">';
        echo '<div style="font-size:13px;font-weight:600;margin:0 0 8px">上传文本文件</div>';
        echo '<div class="kb-drop" id="kbDrop" onclick="document.getElementById(\'kbCreateFile\').click()">';
        echo '<div>拖拽文件至此，或者 <span style="color:#1558c0;font-weight:600">选择文件</span></div>';
        echo '<div class="hint">已支持 TXT、MARKDOWN、MD、CSV、JSON、LOG。PDF / DOCX / XLSX / HTML 解析后置。<br>单文件建议不超过 2MB（演示）。</div>';
        echo '<div class="file" id="kbDropName"></div>';
        echo '</div>';
        echo '<input type="file" id="kbCreateFile" accept=".txt,.md,.markdown,.csv,.log,.json" style="display:none">';
        echo '</div>';
        echo '<div class="rowform" style="margin-top:16px;justify-content:space-between">';
        echo '<button type="button" class="ghost" onclick="showEmptyKbForm()">创建一个空知识库</button>';
        echo '<button type="button" class="go" id="kbCreateNext" disabled onclick="kbCreateGoStep2()">下一步 →</button>';
        echo '</div>';
        echo '<form id="kbEmptyForm" class="rowform" onsubmit="createEmptyKnowledge(event)" style="display:none;margin-top:12px;background:#fff;border:1px solid #e6e8ee;border-radius:12px;padding:12px">';
        echo '<strong style="width:100%;font-size:13px">空知识库</strong><input id="kbNewName" placeholder="名称" required style="min-width:180px"><input id="kbNewCode" placeholder="code 可空"><input id="kbNewDesc" placeholder="描述" style="width:220px"><button class="go" type="submit">创建</button>';
        echo '</form></div>';
        echo '<div id="kbCreateStep2" style="display:none">';
        echo '<div class="kb-step2"><div>';
        echo '<div class="kb-panel" style="margin-bottom:12px"><h4>分段设置</h4>';
        echo '<div class="kb-opt on" id="kbModeGeneral" onclick="pickChunkMode(\'general\')"><strong>通用</strong><p>检索与召回使用同一分段</p></div>';
        echo '<div class="kb-opt disabled" title="后置"><strong>父子分段</strong><p>后置 · 子块检索、父块作上下文</p></div>';
        echo '<div class="kb-field"><label>分段标识符</label><input type="text" id="kbChunkSep" value="\\n\\n"></div>';
        echo '<div class="kb-field"><label>分段最大长度</label><input type="number" id="kbChunkMax" min="32" max="4000" value="500"></div>';
        echo '<div class="kb-field"><label>分段重叠长度</label><input type="number" id="kbChunkOverlap" min="0" max="500" value="50"></div>';
        echo '<div style="font-size:12px;color:#667085;margin:8px 0 4px">文本预处理规则</div>';
        echo '<label class="kb-check"><input type="checkbox" id="kbCleanWs" checked> 替换掉连续的空格、换行符和制表符</label>';
        echo '<label class="kb-check"><input type="checkbox" id="kbStripUrl"> 删除所有 URL 和电子邮件地址</label>';
        echo '<label class="kb-check" style="opacity:.55"><input type="checkbox" disabled> 使用 Q&A 分段（后置）</label>';
        echo '<div class="rowform" style="margin-top:10px"><button type="button" class="ghost" onclick="runKbCreatePreview()">预览块</button><button type="button" class="ghost" onclick="resetKbChunkForm()">重置</button></div>';
        echo '</div>';
        echo '<div class="kb-panel" style="margin-bottom:12px"><h4>索引方式</h4>';
        echo '<div class="kb-opt on" data-index="economy" onclick="pickIndexing(\'economy\')"><strong>经济</strong><p>关键词检索，不消耗 Embedding Token</p></div>';
        echo '<div class="kb-opt" data-index="high_quality" onclick="pickIndexing(\'high_quality\')"><strong>高质量 <span style="color:#1558c0;font-size:11px">推荐</span></strong><p>向量检索（需配置 embed_channel；未配置时仍按经济入库）</p></div>';
        echo '</div>';
        echo '<div class="kb-panel" id="kbRetrievalPanel"><h4>检索设置</h4>';
        echo '<div class="kb-opt" data-ret="vector" onclick="pickRetrieval(\'vector\')"><strong>向量检索</strong><p>查询向量化后找最相近分段</p></div>';
        echo '<div class="kb-opt on" data-ret="keyword" onclick="pickRetrieval(\'keyword\')"><strong>全文 / 关键词</strong><p>倒排关键词匹配（经济默认）</p></div>';
        echo '<div class="kb-opt" data-ret="hybrid" onclick="pickRetrieval(\'hybrid\')"><strong>混合检索 <span style="color:#1558c0;font-size:11px">推荐</span></strong><p>关键词 + 向量，可配权重/Rerank（Rerank 后置）</p></div>';
        echo '<label class="kb-check"><input type="checkbox" id="kbRerank"> Rerank 模型（后置，仅保存开关）</label>';
        echo '<div class="kb-field"><label>Top K</label><input type="number" id="kbTopK" min="1" max="20" value="3"></div>';
        echo '<div class="kb-field"><label>Score 阈值</label><input type="number" id="kbScore" min="0" max="1" step="0.05" value="0.5"></div>';
        echo '</div>';
        echo '<div class="rowform" style="justify-content:space-between"><button type="button" class="ghost" onclick="kbCreateBack1()">上一步</button><button type="button" class="go" onclick="kbCreateGoStep3()">保存并处理 →</button></div>';
        echo '</div><div class="kb-panel" style="min-height:360px">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><strong id="kbPreviewFile" style="font-size:13px">预览</strong><span id="kbPreviewCount" style="font-size:12px;color:#667085">0 预估块</span></div>';
        echo '<div id="kbCreatePreview" class="kb-preview-empty">点击左侧「预览块」加载预览</div>';
        echo '</div></div></div>';
        echo '<div id="kbCreateStep3" style="display:none">';
        echo '<h3 style="font-size:15px;margin:0 0 8px">处理并完成</h3>';
        echo '<form class="rowform" onsubmit="finishKbCreate(event)" style="flex-direction:column;align-items:stretch;max-width:480px">';
        echo '<label style="font-size:13px">知识库名称<input id="kbCreateName" required style="width:100%;margin-top:4px" placeholder="例如：客服 FAQ"></label>';
        echo '<label style="font-size:13px">描述<textarea id="kbCreateDesc" style="width:100%;margin-top:4px;min-height:64px" placeholder="可选"></textarea></label>';
        echo '<label style="font-size:13px"><input id="kbCreateBind" type="checkbox" checked> 创建后绑定对话入口 app:ai_chat_demo</label>';
        echo '<div class="rowform" style="justify-content:flex-end"><button type="button" class="ghost" onclick="kbCreateShowStep(2)">上一步</button><button class="go" type="submit">创建并入库</button></div>';
        echo '</form></div></div>';
        echo '<div id="kbDetail" style="display:none">';
        echo '<div class="rowform"><button class="ghost" type="button" onclick="closeKbDetail()">← 返回列表</button><span id="kbDetailMeta" style="font-size:12px;color:#667085"></span></div>';
        echo '<div class="kb-tabs"><button type="button" data-kbtab="docs" class="on" onclick="showKbTab(\'docs\')">文档</button><button type="button" data-kbtab="hit" onclick="showKbTab(\'hit\')">召回测试</button><button type="button" data-kbtab="settings" onclick="showKbTab(\'settings\')">设置</button></div>';
        echo '<div class="kb-sub on" data-kbsub="docs">';
        echo '<div class="kb-drop" id="kbDocDrop" onclick="document.getElementById(\'kbDocFile\').click()" style="margin-bottom:12px">';
        echo '<div>拖拽文件至此，或者 <span style="color:#1558c0;font-weight:600">选择文件</span></div>';
        echo '<div class="hint">已支持 TXT、MARKDOWN、MD、CSV、JSON、LOG。PDF / DOCX / XLSX 后置。</div>';
        echo '<div class="file" id="kbDocDropName"></div></div>';
        echo '<form class="rowform" onsubmit="ingestKbFile(event)"><input type="file" id="kbDocFile" accept=".txt,.md,.markdown,.csv,.log,.json" style="display:none"><button class="go" type="submit">上传所选文件</button></form>';
        echo '<form class="rowform" onsubmit="ingestKbText(event)"><input id="kbDocTitle" placeholder="或粘贴：文档标题"><textarea id="kbDocText" placeholder="正文（空行分段）"></textarea><button class="ghost" type="button" onclick="previewKbChunks()">预览分段</button><button class="go" type="submit">添加文本</button></form>';
        echo '<pre id="kbPreview" style="display:none;background:#f8f9fb;border:1px solid #e6e8ee;border-radius:8px;padding:10px;white-space:pre-wrap;font-size:12px;max-height:160px;overflow:auto"></pre>';
        echo '<table><tr><th>标题</th><th>来源</th><th>词量</th><th>状态</th><th>启用</th><th></th></tr><tbody id="kbDocRows"></tbody></table>';
        echo '</div>';
        echo '<div class="kb-sub" data-kbsub="hit"><div class="kb-hit">';
        echo '<div class="kb-hit-box"><div style="font-size:12px;color:#667085;margin-bottom:8px">源文本 · 按本库检索</div><textarea id="kbHitQ" placeholder="请输入短陈述句，例如：退货几天到账" style="width:100%;min-height:100px"></textarea><div class="rowform" style="margin-top:8px"><label style="font-size:12px">TopK <input id="kbHitK" type="number" min="1" max="20" value="5" style="width:56px"></label><button class="go" type="button" onclick="runKbHit()">测试</button></div></div>';
        echo '<div class="kb-hit-box"><div style="font-size:12px;color:#667085;margin-bottom:8px">召回结果</div><div id="kbHitOut" style="color:#98a2b3;font-size:13px">结果将显示在这里</div></div>';
        echo '</div></div>';
        echo '<div class="kb-sub" data-kbsub="settings">';
        echo '<form class="rowform" onsubmit="saveKbSettings(event)" style="flex-direction:column;align-items:stretch;max-width:520px"><label style="font-size:13px">名称<input id="kbSetName" style="width:100%;margin-top:4px"></label><label style="font-size:13px">描述<textarea id="kbSetDesc" style="width:100%;margin-top:4px;min-height:80px"></textarea></label><div><span style="font-size:12px;color:#98a2b3">code：</span><code id="kbSetCode"></code></div><button class="go" type="submit" style="align-self:flex-start">保存</button></form>';
        echo '<h3 style="font-size:14px;margin:20px 0 8px">绑定到对话入口</h3><form class="rowform" onsubmit="bindKbEntry(event)"><input id="kbSetEntry" value="app:ai_chat_demo" style="width:200px"><button class="go" type="submit">绑定并启用</button></form>';
        echo '<table><tr><th>入口</th><th>环境</th><th>启用</th><th></th></tr><tbody id="kbBindRows"></tbody></table>';
        echo '</div></div></div></section>';
        echo '<section class="pane" data-pane="skills"><div class="head"><h1>技能</h1><p>只能改自己创建的技能。skillId 已存在时新建会被拒绝。</p></div><div class="pad">';
        echo '<form class="rowform" onsubmit="saveSkill(event)"><input type="hidden" id="skMode" value="create"><input id="skId" placeholder="skillId，如 demo.author" required><input id="skName" placeholder="名称" required><input id="skSum" placeholder="摘要" style="width:220px"><input id="skTools" placeholder="工具 id，逗号分隔" style="width:220px"><textarea id="skIns" placeholder="说明书"></textarea><button class="go" type="submit">保存技能</button></form>';
        echo '<table><tr><th>skillId</th><th>名称</th><th>工具</th><th>标记</th><th>更新</th><th></th></tr><tbody id="skillRows"></tbody></table></div></section>';
        echo '<section class="pane" data-pane="tools"><div class="head"><h1>工具</h1><p>只登记说明和 PHP 引用，不生成 PHP 文件。</p></div><div class="pad">';
        echo '<form class="rowform" onsubmit="saveTool(event)"><input id="tlId" placeholder="工具 id" required><input id="tlName" placeholder="name" required><input id="tlPhp" placeholder="PHP 引用，可空"><label><input id="tlAsync" type="checkbox">异步</label><textarea id="tlDesc" placeholder="说明"></textarea><button class="go" type="submit">保存工具</button></form>';
        echo '<table><tr><th>id</th><th>name</th><th>说明</th><th>异步</th><th>PHP</th><th>被哪些技能引用</th></tr><tbody id="toolRows"></tbody></table></div></section>';
        echo '<section class="pane" data-pane="entries"><div class="head"><h1>入口绑定</h1><p>一个入口钉一份技能。改绑会再确认一次。</p></div><div class="pad">';
        echo '<form class="rowform" onsubmit="saveEntry(event)"><input type="hidden" id="enExists" value=""><input id="enId" placeholder="入口，如 platform.authorChat" required><select id="entrySkill"></select><label><input id="enProd" type="checkbox" checked>生产</label><label><input id="enOn" type="checkbox" checked>启用</label><button class="go" type="submit">绑定入口</button></form>';
        echo '<table><tr><th>入口</th><th>技能</th><th>技能名</th><th>生产</th><th>启用</th><th>更新</th><th></th></tr><tbody id="entryRows"></tbody></table></div></section>';
        echo '<section class="pane" data-pane="rules"><div class="head"><h1>我的规则</h1><p>只进当前用户 ' . htmlspecialchars(self::$userId, ENT_QUOTES, 'UTF-8') . ' 的对话。写普通文字。</p></div><div class="pad">';
        echo '<div class="rowform"><textarea id="ruleDont" placeholder="不要做"></textarea><textarea id="rulePrefer" placeholder="偏好"></textarea></div>';
        echo '<button class="go" type="button" onclick="saveRule(false)">保存</button> <button class="ghost" type="button" onclick="saveRule(true)">清空我的规则</button> <button class="ghost" type="button" onclick="loadRule()">重新加载</button></div></section>';
        echo '<section class="pane" data-pane="flows"><div class="head"><h1>工作流</h1><p>新建时带一张开始到结束的草稿。改 JSON 后先保存草稿，发布之后才能运行。节点类型目前只有 start 和 end。</p></div><div class="pad">';
        echo '<form class="rowform" onsubmit="loadFlows();return false;"><input id="wfName" placeholder="名称"><select id="wfStatus"><option value="">全部状态</option><option value="online">上线</option><option value="offline">下线</option></select><select id="wfTpl"><option value="">是否模板</option><option value="1">是</option><option value="0">否</option></select><button class="go" type="submit">搜索</button></form>';
        echo '<form class="rowform" onsubmit="saveFlow(event)"><input id="wfNewName" placeholder="新工作流名称" required><input id="wfNewTag" placeholder="业务标签"><input id="wfNewDesc" placeholder="描述" style="width:220px"><select id="wfNewStatus"><option value="offline">下线</option><option value="online">上线</option></select><label><input id="wfNewTpl" type="checkbox">模板</label><button class="go" type="submit">新建工作流</button></form>';
        echo '<table><tr><th>ID</th><th>名称</th><th>标签</th><th>描述</th><th>状态</th><th>图</th><th>模板</th><th>创建人</th><th></th></tr><tbody id="flowRows"></tbody></table>';
        echo '<div id="flowEditor" style="display:none;margin-top:16px"><h3 id="flowEditorTitle" style="margin:0 0 8px;font-size:15px"></h3>';
        echo '<textarea id="flowGraph" style="width:100%;min-height:180px;font-family:Consolas,monospace"></textarea>';
        echo '<div class="rowform"><button class="go" type="button" onclick="saveGraph()">保存草稿</button><button class="ghost" type="button" onclick="publishGraph()">发布</button></div>';
        echo '<textarea id="flowInput" placeholder=\'运行输入，JSON 对象，例如 {"year":"2026"}\'></textarea>';
        echo '<div class="rowform"><button class="go" type="button" onclick="runGraph()">运行已发布图</button></div>';
        echo '<pre id="flowResult" style="background:#fff;border:1px solid #e5e7eb;padding:10px;white-space:pre-wrap"></pre></div></div></section>';
        echo '</div>';
        echo '<div id="delMask" class="mask" hidden><div class="dlg" role="dialog" aria-modal="true" aria-labelledby="delTitle">';
        echo '<div class="dlg-title"><span class="warn" aria-hidden="true">!</span><span id="delTitle">确定删除对话?</span></div>';
        echo '<p class="dlg-body">删除后，聊天记录将不可恢复。</p>';
        echo '<div class="dlg-actions"><button type="button" class="ghost" id="delCancel">取消</button><button type="button" class="danger" id="delOk">删除</button></div>';
        echo '</div></div>';
        echo '<script>var BASE=' . json_encode($base) . ';';
        echo <<<'JS'
var cid="",sending=false,skills=[],kbId="",kbTab="docs",kbCreateFile=null,kbCreateText="",kbIndexing="economy",kbRetrieval="keyword";
function showPane(name){
  document.querySelectorAll("[data-pane]").forEach(function(el){el.className="pane"+(el.getAttribute("data-pane")===name?" on":"");});
  document.querySelectorAll("[data-nav]").forEach(function(el){el.className=el.getAttribute("data-nav")===name?"on":"";});
  if(name!=="knowledge"){location.hash=name;kbId="";}
  if(name==="chat"){loadConvs();loadSkills();}
  if(name==="knowledge")loadKnowledge();
  if(name==="skills")loadSkills();
  if(name==="tools")loadTools();
  if(name==="entries")loadEntries();
  if(name==="rules")loadRule();
  if(name==="flows")loadFlows();
}
function activatePane(name){
  document.querySelectorAll("[data-pane]").forEach(function(el){el.className="pane"+(el.getAttribute("data-pane")===name?" on":"");});
  document.querySelectorAll("[data-nav]").forEach(function(el){el.className=el.getAttribute("data-nav")===name?"on":"";});
}
function kbWordsLabel(n){n=parseInt(n,10)||0;if(n>=1000)return(Math.round(n/100)/10)+" 千字符";return n+" 字符";}
function hideKbPanels(){
  document.getElementById("kbHome").style.display="none";
  document.getElementById("kbCreate").style.display="none";
  document.getElementById("kbDetail").style.display="none";
}
async function loadKnowledge(){
  kbId="";kbTab="docs";kbCreateFile=null;kbCreateText="";
  hideKbPanels();
  document.getElementById("kbHome").style.display="";
  document.getElementById("kbTitle").textContent="知识库";
  document.getElementById("kbDesc").textContent="列表 · 创建向导（选数据源/上传）· 单库文档/召回/设置。对话只读绑定。";
  location.hash="knowledge";
  try{
    var bases=await api("knowledgebases");
    var box=document.getElementById("kbCards");box.innerHTML="";
    (bases||[]).forEach(function(b){
      var card=document.createElement("button");card.type="button";card.className="kb-card";
      card.innerHTML="<h3>"+esc(b.name||b.code)+"</h3><div class=\"meta\">"+esc(b.code)+"<br>"+(b.doc_count||0)+" 文档 · "+kbWordsLabel(b.word_count)+" · "+(b.binding_count||0)+" 关联入口</div>";
      card.onclick=function(){openKbDetail(b.id,"docs");};
      box.appendChild(card);
    });
    var create=document.createElement("button");create.type="button";create.className="kb-card create";
    create.innerHTML="<strong>+ 创建知识库</strong><span class=\"meta\" style=\"margin-top:8px\">导入文本或先建空库</span>";
    create.onclick=function(){openKbCreate();};
    box.appendChild(create);
  }catch(e){alert(e.message);}
}
function openKbCreate(){
  hideKbPanels();
  document.getElementById("kbCreate").style.display="";
  document.getElementById("kbTitle").textContent="创建知识库";
  document.getElementById("kbDesc").textContent="选择数据源 → 分段与检索设置 → 处理并完成（对齐 Gaia）。";
  location.hash="knowledge/create";
  kbCreateFile=null;kbCreateText="";
  document.getElementById("kbDropName").textContent="";
  document.getElementById("kbCreateFile").value="";
  document.getElementById("kbCreateNext").disabled=true;
  document.getElementById("kbEmptyForm").style.display="none";
  document.getElementById("kbCreatePreview").className="kb-preview-empty";
  document.getElementById("kbCreatePreview").textContent="点击左侧「预览块」加载预览";
  document.getElementById("kbPreviewCount").textContent="0 预估块";
  resetKbChunkForm();
  pickIndexing("economy");
  kbCreateShowStep(1);
  pickKbSrc("file");
}
function pickChunkMode(){/* 仅通用可用 */}
function resetKbChunkForm(){
  document.getElementById("kbChunkSep").value="\\n\\n";
  document.getElementById("kbChunkMax").value="500";
  document.getElementById("kbChunkOverlap").value="50";
  document.getElementById("kbCleanWs").checked=true;
  document.getElementById("kbStripUrl").checked=false;
  document.getElementById("kbRerank").checked=false;
  document.getElementById("kbTopK").value="3";
  document.getElementById("kbScore").value="0.5";
}
function pickIndexing(kind){
  kbIndexing=kind;
  document.querySelectorAll("[data-index]").forEach(function(el){el.className="kb-opt"+(el.getAttribute("data-index")===kind?" on":"");});
  if(kind==="economy")pickRetrieval("keyword");
  else if(kbRetrieval==="keyword")pickRetrieval("vector");
}
function pickRetrieval(kind){
  kbRetrieval=kind;
  document.querySelectorAll("[data-ret]").forEach(function(el){el.className="kb-opt"+(el.getAttribute("data-ret")===kind?" on":"");});
}
function collectKbMetaFields(){
  return {
    chunk_separator:document.getElementById("kbChunkSep").value,
    chunk_max:document.getElementById("kbChunkMax").value,
    chunk_overlap:document.getElementById("kbChunkOverlap").value,
    clean_whitespace:document.getElementById("kbCleanWs").checked?"1":"0",
    strip_urls:document.getElementById("kbStripUrl").checked?"1":"0",
    indexing:kbIndexing,
    retrieval_mode:kbRetrieval,
    top_k:document.getElementById("kbTopK").value,
    score_threshold:document.getElementById("kbScore").value,
    rerank:document.getElementById("kbRerank").checked?"1":"0"
  };
}
async function runKbCreatePreview(){
  if(!kbCreateText){
    if(!kbCreateFile){alert("请先选择文件");return;}
    kbCreateText=await readFileAsText(kbCreateFile);
  }
  try{
    var body=collectKbMetaFields();body.text=kbCreateText;
    var r=await api("knowledgepreview",body);
    var box=document.getElementById("kbCreatePreview");
    box.className="";
    box.style.cssText="white-space:pre-wrap;font-size:12px;max-height:480px;overflow:auto;line-height:1.5";
    box.textContent=(r.chunks||[]).map(function(c,i){return "块 "+(i+1)+" · "+c.length+" 字符\n"+c;}).join("\n\n────────\n\n")||"(空)";
    document.getElementById("kbPreviewCount").textContent=(r.count||0)+" 预估块";
    document.getElementById("kbPreviewFile").textContent=kbCreateFile?kbCreateFile.name:"预览";
  }catch(e){alert(e.message);}
}
function kbCreateShowStep(n){
  document.getElementById("kbCreateStep1").style.display=n===1?"":"none";
  document.getElementById("kbCreateStep2").style.display=n===2?"":"none";
  document.getElementById("kbCreateStep3").style.display=n===3?"":"none";
  document.getElementById("kbStep1Label").className=n===1?"on":"";
  document.getElementById("kbStep2Label").className=n===2?"on":"";
  document.getElementById("kbStep3Label").className=n===3?"on":"";
}
function pickKbSrc(kind){
  document.querySelectorAll("[data-kbsrc]").forEach(function(el){
    if(el.disabled)return;
    el.className=el.getAttribute("data-kbsrc")===kind?"on":"";
  });
  document.getElementById("kbSrcFile").style.display=kind==="file"?"":"none";
}
function showEmptyKbForm(){
  document.getElementById("kbEmptyForm").style.display="";
  document.getElementById("kbNewName").focus();
}
function setKbCreateFile(file){
  if(!file)return;
  var ext=(file.name.split(".").pop()||"").toLowerCase();
  var allow={txt:1,md:1,markdown:1,csv:1,log:1,json:1};
  if(!allow[ext]){alert("当前仅支持 TXT/MD/CSV/JSON/LOG。PDF/DOCX 等后置。");return;}
  kbCreateFile=file;
  document.getElementById("kbDropName").textContent=file.name+" ("+Math.round(file.size/1024)+" KB)";
  document.getElementById("kbCreateNext").disabled=false;
  if(!document.getElementById("kbCreateName").value){
    document.getElementById("kbCreateName").value=file.name.replace(/\.[^.]+$/,"");
  }
}
function readFileAsText(file){
  return new Promise(function(resolve,reject){
    if(file.text){file.text().then(resolve).catch(reject);return;}
    var fr=new FileReader();fr.onload=function(){resolve(String(fr.result||""));};fr.onerror=function(){reject(new Error("读文件失败"));};fr.readAsText(file);
  });
}
async function kbCreateGoStep2(){
  if(!kbCreateFile){alert("请先选择文件");return;}
  try{
    kbCreateText=await readFileAsText(kbCreateFile);
    document.getElementById("kbPreviewFile").textContent=kbCreateFile.name;
    kbCreateShowStep(2);
    await runKbCreatePreview();
  }catch(e){alert(e.message);}
}
function kbCreateBack1(){kbCreateShowStep(1);}
function kbCreateGoStep3(){kbCreateShowStep(3);}
async function finishKbCreate(ev){
  ev.preventDefault();
  if(!kbCreateFile||!kbCreateText){alert("缺少文件内容");return;}
  try{
    var name=document.getElementById("kbCreateName").value;
    var body=collectKbMetaFields();
    body.name=name;
    body.description=document.getElementById("kbCreateDesc").value;
    var row=await api("knowledgecreate",body);
    await api("knowledgeingest",{base_id:row.id,title:kbCreateFile.name,text:kbCreateText});
    if(document.getElementById("kbCreateBind").checked){
      await api("knowledgebind",{base_id:row.id,entry_id:"app:ai_chat_demo",enabled:"1"});
    }
    openKbDetail(row.id,"docs");
  }catch(e){alert(e.message);}
}
async function createEmptyKnowledge(ev){
  ev.preventDefault();
  try{
    var row=await api("knowledgecreate",{name:document.getElementById("kbNewName").value,code:document.getElementById("kbNewCode").value,description:document.getElementById("kbNewDesc").value});
    ev.target.reset();
    openKbDetail(row.id,"docs");
  }catch(e){alert(e.message);}
}
async function seedKnowledge(){
  try{var r=await api("knowledgeseed",{});alert("已灌入并绑定对话入口\nbase="+r.base_id);openKbDetail(r.base_id,"docs");}catch(e){alert(e.message);}
}
async function openKbDetail(id,tab){
  kbId=id;kbTab=tab||"docs";
  hideKbPanels();
  document.getElementById("kbDetail").style.display="";
  location.hash="knowledge/"+id+"/"+kbTab;
  try{
    var b=await api("knowledgebase",{base_id:id});
    document.getElementById("kbTitle").textContent=b.name||b.code;
    document.getElementById("kbDesc").textContent=(b.description||"本地文档")+" · "+(b.doc_count||0)+" 文档 · "+kbWordsLabel(b.word_count);
    document.getElementById("kbDetailMeta").textContent=(b.binding_count||0)+" 关联入口 · "+(b.status||"");
    document.getElementById("kbSetName").value=b.name||"";
    document.getElementById("kbSetDesc").value=b.description||"";
    document.getElementById("kbSetCode").textContent=b.code||"";
    showKbTab(kbTab);
    if(kbTab==="docs")await loadKbDocs();
    if(kbTab==="settings")await loadKbBinds();
  }catch(e){alert(e.message);}
}
function closeKbDetail(){loadKnowledge();}
function showKbTab(name){
  kbTab=name;
  document.querySelectorAll("[data-kbtab]").forEach(function(el){el.className=el.getAttribute("data-kbtab")===name?"on":"";});
  document.querySelectorAll("[data-kbsub]").forEach(function(el){el.className="kb-sub"+(el.getAttribute("data-kbsub")===name?" on":"");});
  if(kbId)location.hash="knowledge/"+kbId+"/"+name;
  if(name==="docs")loadKbDocs();
  if(name==="settings")loadKbBinds();
}
async function loadKbDocs(){
  if(!kbId)return;
  try{
    var docs=await api("knowledgedocs",{base_id:kbId});
    var rows=document.getElementById("kbDocRows");rows.innerHTML="";
    if(!docs||!docs.length){rows.innerHTML="<tr><td colspan=\"6\" style=\"color:#98a2b3\">还没有文档。添加文本或上传 TXT/MD。</td></tr>";return;}
    docs.forEach(function(d){
      var tr=document.createElement("tr");
      var on=String(d.enabled)==="1"||d.enabled===1;
      tr.innerHTML="<td>"+esc(d.title)+"</td><td>"+esc(d.source_type)+"</td><td>"+esc(d.word_count)+"</td><td>"+esc(d.status)+"</td><td>"+(on?"启用":"禁用")+"</td><td></td>";
      var btn=document.createElement("button");btn.type="button";btn.className="ghost";btn.textContent=on?"禁用":"启用";
      btn.onclick=function(){toggleKbDoc(d.id,!on);};
      tr.lastChild.appendChild(btn);rows.appendChild(tr);
    });
  }catch(e){alert(e.message);}
}
async function toggleKbDoc(docId,on){
  try{await api("knowledgedocenable",{document_id:docId,enabled:on?"1":"0"});loadKbDocs();var b=await api("knowledgebase",{base_id:kbId});document.getElementById("kbDesc").textContent=(b.description||"本地文档")+" · "+(b.doc_count||0)+" 文档 · "+kbWordsLabel(b.word_count);}catch(e){alert(e.message);}
}
async function previewKbChunks(){
  var text=document.getElementById("kbDocText").value;
  try{
    var r=await api("knowledgepreview",{text:text});
    var box=document.getElementById("kbPreview");box.style.display="block";
    box.textContent="共 "+r.count+" 段 · 约 "+r.word_count+" 字符\n\n"+(r.chunks||[]).map(function(c,i){return "["+(i+1)+"] "+c;}).join("\n\n---\n\n");
  }catch(e){alert(e.message);}
}
async function ingestKbText(ev){
  ev.preventDefault();
  try{
    await api("knowledgeingest",{base_id:kbId,title:document.getElementById("kbDocTitle").value,text:document.getElementById("kbDocText").value});
    document.getElementById("kbDocText").value="";document.getElementById("kbDocTitle").value="";document.getElementById("kbPreview").style.display="none";
    loadKbDocs();
  }catch(e){alert(e.message);}
}
async function ingestKbFile(ev){
  ev.preventDefault();
  var fileInput=document.getElementById("kbDocFile");
  if(!fileInput.files||!fileInput.files[0]){alert("请选择文件");return;}
  try{
    var fd=new FormData();fd.append("base_id",kbId);fd.append("file",fileInput.files[0]);
    var resp=await fetch(BASE+"knowledgeingestfile",{method:"POST",credentials:"same-origin",body:fd});
    var j=await resp.json();
    if(j.code!==200)throw new Error(j.info||"上传失败");
    fileInput.value="";document.getElementById("kbDocDropName").textContent="";loadKbDocs();
  }catch(e){alert(e.message);}
}
function bindKbDrop(zoneId,inputId,onFile){
  var zone=document.getElementById(zoneId),input=document.getElementById(inputId);
  if(!zone||!input)return;
  input.addEventListener("change",function(){if(input.files&&input.files[0])onFile(input.files[0]);});
  ;["dragenter","dragover"].forEach(function(ev){zone.addEventListener(ev,function(e){e.preventDefault();zone.classList.add("drag");});});
  ;["dragleave","drop"].forEach(function(ev){zone.addEventListener(ev,function(e){e.preventDefault();zone.classList.remove("drag");});});
  zone.addEventListener("drop",function(e){var f=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0];if(f){if(input){try{var dt=new DataTransfer();dt.items.add(f);input.files=dt.files;}catch(err){}}onFile(f);}});
}
bindKbDrop("kbDrop","kbCreateFile",setKbCreateFile);
bindKbDrop("kbDocDrop","kbDocFile",function(f){
  var ext=(f.name.split(".").pop()||"").toLowerCase();
  if(["txt","md","markdown","csv","log","json"].indexOf(ext)<0){alert("当前仅支持 TXT/MD/CSV/JSON/LOG");return;}
  document.getElementById("kbDocDropName").textContent=f.name;
});
async function runKbHit(){
  try{
    var r=await api("knowledgetest",{base_id:kbId,query:document.getElementById("kbHitQ").value,top_k:document.getElementById("kbHitK").value});
    var box=document.getElementById("kbHitOut");
    if(!r.count){box.innerHTML="<span style=\"color:#98a2b3\">无命中</span>";return;}
    box.innerHTML=(r.hits||[]).map(function(h){
      return "<div class=\"kb-chunk\"><div class=\"sc\">score="+esc(h.score)+(h.document_id?" · doc="+esc(h.document_id):"")+(h.chunk_id?" · chunk="+esc(h.chunk_id):"")+"</div>"+esc(h.content||h.text||"")+"</div>";
    }).join("");
  }catch(e){alert(e.message);}
}
async function saveKbSettings(ev){
  ev.preventDefault();
  try{
    await api("knowledgeupdate",{base_id:kbId,name:document.getElementById("kbSetName").value,description:document.getElementById("kbSetDesc").value});
    openKbDetail(kbId,"settings");
  }catch(e){alert(e.message);}
}
async function bindKbEntry(ev){
  ev.preventDefault();
  try{
    await api("knowledgebind",{base_id:kbId,entry_id:document.getElementById("kbSetEntry").value,enabled:"1"});
    loadKbBinds();
  }catch(e){alert(e.message);}
}
async function loadKbBinds(){
  if(!kbId)return;
  try{
    var binds=await api("knowledgebindings",{base_id:kbId});
    var rows=document.getElementById("kbBindRows");rows.innerHTML="";
    if(!binds||!binds.length){rows.innerHTML="<tr><td colspan=\"4\" style=\"color:#98a2b3\">尚未绑定入口。绑定 app:ai_chat_demo 后对话才会注入本库。</td></tr>";return;}
    binds.forEach(function(x){
      var on=String(x.is_enabled)==="1"||x.is_enabled===1;
      var tr=document.createElement("tr");
      tr.innerHTML="<td>"+esc(x.entry_id)+"</td><td>"+esc(x.env)+"</td><td>"+(on?"启用":"禁用")+"</td><td></td>";
      var btn=document.createElement("button");btn.type="button";btn.className="ghost";btn.textContent=on?"禁用":"启用";
      btn.onclick=function(){api("knowledgebindingenable",{binding_id:x.id,enabled:on?"0":"1"}).then(loadKbBinds).catch(function(e){alert(e.message);});};
      tr.lastChild.appendChild(btn);rows.appendChild(tr);
    });
  }catch(e){alert(e.message);}
}
async function api(act,body){
  var opt={credentials:"same-origin"};
  if(body){opt.method="POST";opt.headers={"Content-Type":"application/x-www-form-urlencoded"};opt.body=new URLSearchParams(body);}
  var resp=await fetch(BASE+act,opt);
  var j=await resp.json();
  if(j.code!==200)throw new Error(j.info||"请求失败");
  return j.data;
}
function esc(s){return String(s==null?"":s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c];});}
async function loadSkills(){
  var box=document.getElementById("skillRows");
  try{
    skills=await api("skills");
    var sel=document.getElementById("skillSel"),cur=sel.value;
    var opts="<option value=''>不指定技能</option>"+skills.map(function(r){return "<option value='"+esc(r.skill_id)+"'>"+esc(r.name)+" · "+esc(r.skill_id)+"</option>";}).join("");
    sel.innerHTML=opts;sel.value=cur;
    var es=document.getElementById("entrySkill");
    es.innerHTML=skills.map(function(r){return "<option value='"+esc(r.skill_id)+"'>"+esc(r.skill_id)+"</option>";}).join("");
    if(!skills.length){box.innerHTML="<tr><td colspan='6'>暂无数据</td></tr>";return;}
    box.innerHTML=skills.map(function(r){
      var btn=r.is_system?"":"<button class='ghost' type='button' onclick='editSkill("+JSON.stringify(r.skill_id)+")'>修改</button>";
      return "<tr><td>"+esc(r.skill_id)+"</td><td>"+esc(r.name)+"</td><td>"+esc((r.tools||[]).join(", "))+"</td><td>"+(r.is_system?"系统":"本地")+"</td><td>"+esc(r.updated_at)+"</td><td>"+btn+"</td></tr>";
    }).join("");
  }catch(e){box.innerHTML="<tr><td colspan='6'>"+esc(e.message)+"</td></tr>";}
}
function editSkill(id){
  var r=skills.filter(function(x){return x.skill_id===id;})[0]; if(!r)return;
  document.getElementById("skMode").value="update";
  document.getElementById("skId").value=r.skill_id;
  document.getElementById("skId").readOnly=true;
  document.getElementById("skName").value=r.name;
  document.getElementById("skSum").value=r.summary||"";
  document.getElementById("skIns").value=r.instruction||"";
  document.getElementById("skTools").value=(r.tools||[]).join(",");
}
async function saveSkill(ev){
  ev.preventDefault();
  try{
    await api("skillSave",{mode:document.getElementById("skMode").value,skill_id:document.getElementById("skId").value,name:document.getElementById("skName").value,summary:document.getElementById("skSum").value,instruction:document.getElementById("skIns").value,tools:document.getElementById("skTools").value});
    document.getElementById("skMode").value="create";document.getElementById("skId").readOnly=false;ev.target.reset();loadSkills();
  }catch(e){alert(e.message);}
}
async function loadTools(){
  var box=document.getElementById("toolRows");
  try{
    var rows=await api("tools");
    if(!rows.length){box.innerHTML="<tr><td colspan='6'>暂无数据</td></tr>";return;}
    box.innerHTML=rows.map(function(r){return "<tr><td>"+esc(r.tool_id)+"</td><td>"+esc(r.name)+"</td><td>"+esc(r.description)+"</td><td>"+(r.is_async?"异步":"同步")+"</td><td>"+(r.has_php?"有引用":"无")+"</td><td>"+esc((r.skills||[]).join(", "))+"</td></tr>";}).join("");
  }catch(e){box.innerHTML="<tr><td colspan='6'>"+esc(e.message)+"</td></tr>";}
}
async function saveTool(ev){
  ev.preventDefault();
  try{
    await api("toolSave",{tool_id:document.getElementById("tlId").value,name:document.getElementById("tlName").value,description:document.getElementById("tlDesc").value,is_async:document.getElementById("tlAsync").checked?"1":"0",php_ref:document.getElementById("tlPhp").value});
    ev.target.reset();loadTools();
  }catch(e){alert(e.message);}
}
async function loadEntries(){
  await loadSkills();
  var box=document.getElementById("entryRows");
  try{
    var rows=await api("entries");
    if(!rows.length){box.innerHTML="<tr><td colspan='7'>暂无绑定</td></tr>";return;}
    box.innerHTML=rows.map(function(r){return "<tr><td>"+esc(r.entry_id)+"</td><td>"+esc(r.skill_id)+"</td><td>"+esc(r.skill_name)+"</td><td>"+(r.is_production?"生产":"测试")+"</td><td>"+(r.is_enabled?"是":"否")+"</td><td>"+esc(r.updated_at)+"</td><td><button class='ghost' type='button' onclick='prefillEntry("+JSON.stringify(r.entry_id)+","+JSON.stringify(r.skill_id)+")'>改绑</button></td></tr>";}).join("");
  }catch(e){box.innerHTML="<tr><td colspan='7'>"+esc(e.message)+"</td></tr>";}
}
function prefillEntry(entryId,skillId){document.getElementById("enId").value=entryId;document.getElementById("entrySkill").value=skillId;document.getElementById("enExists").value="1";}
async function saveEntry(ev){
  ev.preventDefault();
  if(document.getElementById("enExists").value==="1"&&!confirm("改绑会换成另一份技能。确定？"))return;
  try{
    await api("entryBind",{entry_id:document.getElementById("enId").value,skill_id:document.getElementById("entrySkill").value,is_production:document.getElementById("enProd").checked?"1":"0",is_enabled:document.getElementById("enOn").checked?"1":"0"});
    document.getElementById("enExists").value="";loadEntries();
  }catch(e){alert(e.message);}
}
async function loadRule(){
  try{var r=await api("rule");document.getElementById("ruleDont").value=r.dont||"";document.getElementById("rulePrefer").value=r.prefer||"";}catch(e){alert(e.message);}
}
async function saveRule(clear){
  try{await api("ruleSave",clear?{clear:"1"}:{dont:document.getElementById("ruleDont").value,prefer:document.getElementById("rulePrefer").value});if(clear)loadRule();}catch(e){alert(e.message);}
}
var flowId="";
async function loadFlows(){
  var box=document.getElementById("flowRows");
  try{
    var rows=await api("workflows",{name:document.getElementById("wfName").value,status:document.getElementById("wfStatus").value,template:document.getElementById("wfTpl").value});
    if(!rows.length){box.innerHTML="<tr><td colspan='9'>暂无登记</td></tr>";return;}
    box.innerHTML=rows.map(function(r){
      var graph=r.published?"已发布":(r.has_draft?"草稿":"无图");
      return "<tr><td>"+esc(r.id)+"</td><td>"+esc(r.name)+"</td><td>"+esc(r.tag)+"</td><td>"+esc(r.description)+"</td><td>"+(r.status==="online"?"上线":"下线")+"</td><td>"+graph+"</td><td>"+(r.is_template?"是":"否")+"</td><td>"+esc(r.owner_id)+"</td><td><button class='ghost' type='button' onclick='openFlow("+JSON.stringify(r.id)+","+JSON.stringify(r.name)+")'>图</button> <button class='ghost' type='button' onclick='copyFlow("+JSON.stringify(r.id)+")'>复制</button></td></tr>";
    }).join("");
  }catch(e){box.innerHTML="<tr><td colspan='9'>"+esc(e.message)+"</td></tr>";}
}
async function saveFlow(ev){
  ev.preventDefault();
  try{
    await api("workflowSave",{name:document.getElementById("wfNewName").value,tag:document.getElementById("wfNewTag").value,description:document.getElementById("wfNewDesc").value,status:document.getElementById("wfNewStatus").value,is_template:document.getElementById("wfNewTpl").checked?"1":"0"});
    ev.target.reset();loadFlows();
  }catch(e){alert(e.message);}
}
async function copyFlow(id){try{await api("workflowCopy",{id:id});loadFlows();}catch(e){alert(e.message);}}
async function openFlow(id,name){
  flowId=id;
  document.getElementById("flowEditor").style.display="block";
  document.getElementById("flowEditorTitle").textContent=name+" · "+id;
  try{
    var g=await api("workflowGraph",{id:id});
    document.getElementById("flowGraph").value=JSON.stringify(g.draft||g.published,null,2);
    document.getElementById("flowResult").textContent=g.published?"已有发布版本，运行用的是发布版":"尚未发布";
  }catch(e){alert(e.message);}
}
async function saveGraph(){
  try{
    JSON.parse(document.getElementById("flowGraph").value);
    await api("workflowGraphSave",{id:flowId,graph:document.getElementById("flowGraph").value});
    document.getElementById("flowResult").textContent="草稿已保存";
    loadFlows();
    return true;
  }catch(e){alert(e.message);return false;}
}
async function publishGraph(){
  if(!await saveGraph())return;
  try{
    await api("workflowPublish",{id:flowId});
    document.getElementById("flowResult").textContent="已发布";
    loadFlows();
  }catch(e){alert(e.message);}
}
async function runGraph(){
  try{
    var raw=document.getElementById("flowInput").value.trim();
    if(raw)JSON.parse(raw);
    var data=await api("workflowRun",{id:flowId,input:raw});
    document.getElementById("flowResult").textContent=JSON.stringify(data,null,2);
  }catch(e){alert(e.message);}
}
function useChip(text){var ta=document.getElementById("q");ta.value=text;ta.focus();}
function askDelete(){
  return new Promise(function(resolve){
    var mask=document.getElementById("delMask");
    var okBtn=document.getElementById("delOk");
    var cancelBtn=document.getElementById("delCancel");
    function finish(ok){
      mask.hidden=true;
      document.removeEventListener("keydown",onKey);
      okBtn.onclick=null;
      cancelBtn.onclick=null;
      mask.onclick=null;
      resolve(ok);
    }
    function onKey(ev){if(ev.key==="Escape")finish(false);}
    okBtn.onclick=function(){finish(true);};
    cancelBtn.onclick=function(){finish(false);};
    mask.onclick=function(ev){if(ev.target===mask)finish(false);};
    document.addEventListener("keydown",onKey);
    mask.hidden=false;
    cancelBtn.focus();
  });
}
async function loadConvs(){
  var box=document.getElementById("convs");
  try{
    var resp=await fetch(BASE+"conversations",{credentials:"same-origin"});
    var j=await resp.json();box.innerHTML="";
    (j.data||[]).forEach(function(c){
      var d=document.createElement("div");
      d.className="conv"+(c.id===cid?" on":"");
      var title=document.createElement("span");
      title.className="conv-title";
      title.textContent=c.title||"(无标题)";
      var del=document.createElement("button");
      del.type="button";
      del.className="conv-del";
      del.setAttribute("aria-label","删除");
      del.innerHTML='<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M9 3h6a1 1 0 0 1 1 1v1h4v2h-1.1l-.9 13.1A2 2 0 0 1 16 22H8a2 2 0 0 1-2-1.9L5.1 7H4V5h4V4a1 1 0 0 1 1-1zm0 2h6V4H9v1zM7.1 7l.8 13h8.2l.8-13H7.1zM10 9h1.5v9H10V9zm2.5 0H14v9h-1.5V9z"/></svg>';
      del.onclick=function(ev){
        ev.stopPropagation();
        askDelete().then(function(ok){
          if(!ok)return;
          api("conversationDelete",{cid:c.id}).then(function(){
            if(cid===c.id){cid="";document.getElementById("msgs").innerHTML="";}
            loadConvs();
          }).catch(function(e){alert(e.message);});
        });
      };
      d.onclick=function(){var nodes=box.querySelectorAll(".conv");for(var i=0;i<nodes.length;i++)nodes[i].className="conv";d.className="conv on";openConv(c.id);};
      d.appendChild(title);
      d.appendChild(del);
      box.appendChild(d);
    });
  }catch(e){box.textContent="列表加载失败";}
}
async function openConv(id){
  cid=id;document.getElementById("msgs").innerHTML="";
  var resp=await fetch(BASE+"history&cid="+encodeURIComponent(cid),{credentials:"same-origin"});
  var j=await resp.json();
  (j.data||[]).forEach(function(m){if(m.role==="user"||m.role==="assistant")addMsg(m.role,m.content);});
}
function newConv(){cid="";document.getElementById("msgs").innerHTML="";loadConvs();}
function addMsg(role,text){var box=document.getElementById("msgs");var d=document.createElement("div");d.className="m "+role;d.textContent=text;box.appendChild(d);box.scrollTop=box.scrollHeight;return d;}
async function send(){
  if(sending)return;
  var q=document.getElementById("q").value.trim();if(!q)return;
  document.getElementById("q").value="";sending=true;addMsg("user",q);var out=addMsg("assistant","");
  try{
    var resp=await fetch(BASE+"chat",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({q:q,cid:cid,skill_id:document.getElementById("skillSel").value})});
    var ct=resp.headers.get("Content-Type")||"";
    if(ct.indexOf("json")>=0){var j=await resp.json();throw new Error(j.info||"请求失败");}
    var reader=resp.body.getReader(),dec=new TextDecoder(),buf="";
    while(true){var chunk=await reader.read();if(chunk.done)break;buf+=dec.decode(chunk.value,{stream:true});var parts=buf.split("\n\n");buf=parts.pop();parts.forEach(function(frame){handleFrame(frame,out);});}
  }catch(e){out.className="m error";out.textContent=(out.textContent||"")+"[出错] "+e.message;}
  sending=false;loadConvs();
}
function handleFrame(frame,out){
  var event="",data=[];
  frame.split("\n").forEach(function(line){if(line.indexOf("event: ")===0)event=line.slice(7);else if(line.indexOf("data: ")===0)data.push(line.slice(6));});
  data=data.join("\n");
  if(event==="delta"){out.textContent+=data;var box=document.getElementById("msgs");box.scrollTop=box.scrollHeight;}
  else if(event==="meta"){
    var kv=data.match(/conversation=(\S+)/);if(kv)cid=kv[1];
    var m=document.createElement("div");m.className="meta";m.style.cssText="font-size:11px;color:#98a2b3;margin:4px 0";
    m.textContent=data;
    kv=data.match(/knowledge_hits=(\S+)/);
    if(kv&&parseInt(kv[1],10)>0){m.textContent=data+" · 已注入知识库片段";}
    document.getElementById("msgs").insertBefore(m,out);
  }
  else if(event==="error"){out.className="m error";out.textContent+=data;}
}
document.getElementById("q").addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();send();}});
(function bootHash(){
  var h=(location.hash||"#chat").replace(/^#/,"");
  if(h==="knowledge/create"){activatePane("knowledge");openKbCreate();return;}
  if(h.indexOf("knowledge/")===0){
    var parts=h.split("/");
    activatePane("knowledge");
    openKbDetail(parts[1]||"",parts[2]||"docs");
    return;
  }
  showPane(h||"chat");
})();
JS;
        echo '</script></body></html>';
    }

    /** 还没有图的登记，打开时补一张开始到结束的草稿 */
    private static function defaultGraph()
    {
        return array(
            'nodes' => array(
                array('id' => 'start', 'type' => 'start', 'title' => '开始', 'config' => array()),
                array('id' => 'end', 'type' => 'end', 'title' => '结束', 'config' => array()),
            ),
            'edges' => array(
                array('source' => 'start', 'target' => 'end', 'sourceHandle' => 'source', 'targetHandle' => 'target'),
            ),
        );
    }

    /** @param array $rows */
    private static function attachGraph(array $rows)
    {
        if ($rows === array()) {
            return $rows;
        }
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = $row['id'];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $found = self::$kernel->storage()->query(
            'SELECT workflow_id, draft_json, published_json FROM ai_workflow_graph WHERE workflow_id IN (' . $marks . ')',
            $ids
        );
        $map = array();
        foreach ($found as $row) {
            $map[(string)$row['workflow_id']] = array(
                'has_draft' => (string)$row['draft_json'] !== '' ? 1 : 0,
                'published' => (string)$row['published_json'] !== '' ? 1 : 0,
            );
        }
        foreach ($rows as $i => $row) {
            $flag = isset($map[$row['id']]) ? $map[$row['id']] : array('has_draft' => 0, 'published' => 0);
            $rows[$i]['has_draft'] = $flag['has_draft'];
            $rows[$i]['published'] = $flag['published'];
        }
        return $rows;
    }

    private static function requireWorkflowId()
    {
        $id = self::field('id');
        $rows = self::$kernel->storage()->query('SELECT id FROM ai_workflow_def WHERE id = ?', array($id));
        if (!isset($rows[0])) {
            throw new \InvalidArgumentException('工作流不存在');
        }
        return $id;
    }

    private static function jsonOut($code, $info, $data)
    {
        if (!headers_sent()) {
            http_response_code((int)$code);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode(array('code' => (int)$code, 'info' => $info, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}