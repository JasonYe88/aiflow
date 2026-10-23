<?php
declare(strict_types=1);

namespace AIFlow\Demo;

use AIFlow\AIKernel;
use AIFlow\Chat\SseEmitter;
use AIFlow\Credit\Billing;
use AIFlow\Exception\InsufficientCreditException;
use AIFlow\Exception\PermissionDeniedException;
use AIFlow\Knowledge\KnowledgeContext;
use AIFlow\Support\Id;

/**
 * AIFlow 框架无关对话演示页（M2 含计费/权限 + 可选知识库注入）
 *
 * 一个入口 ChatDemoPage::run($kernel, $userId, $options) 自带：
 *   页面 UI + conversations/history/wallet/grant JSON 端点 + chat SSE 流式端点
 * chat 链路：guard('app:ai_chat_demo') →（可选）知识库 retrieve 注入 system
 *   → ensureWallet → Billing 两阶段计费 → gateway chatStream
 *   （预检余额不足 = 402 JSON 模型零调用；未授权 = 403 JSON）
 * SSE 帧：meta(channel/conversation/wallet_balance/knowledge_hits) → delta* → done(用量+amount/balance)
 *
 * options：
 *   base_url    端点前缀（宿主路由），缺省 '?act='；act 值见 run() switch
 *   allow_grant 是否暴露 demo 充值按钮（默认 true；生产宿主可关，走真实充值渠道）
 *   grant_cents demo 充值按钮金额（分，默认 10000 = 1 元）
 *   page_title  页头标题
 *   knowledge_enabled  是否启用知识库检索注入（默认 true）
 *   knowledge_entry    绑定入口 id（默认 app:ai_chat_demo）
 *   knowledge_top_k    检索条数（默认 5）
 *   knowledge_seed     无绑定时是否自动灌入演示 FAQ（默认 true）
 *
 * 宿主挂载示例（约 10 行）：见 aiflow/demo/index.php（独立入口）或 fms 薄挂载控制器
 */
final class ChatDemoPage
{
    /** @var AIKernel */
    private static $kernel;

    /** @var string 当前演示用户（宿主传入登录态 userId） */
    private static $userId;

    /** @var array 全局 options */
    private static $options;

    /**
     * 演示页统一入口（按 $_GET['act'] 分发；缺省 index）
     *
     * @param AIKernel $kernel  已引导内核
     * @param string   $userId 当前用户标识（宿主登录态）
     * @param array    $options 见类注释
     * @return void 直接输出（HTML / JSON / SSE），不返回值
     */
    public static function run(AIKernel $kernel, $userId, array $options = array())
    {
        self::$kernel = $kernel;
        self::$userId = ($userId === '' ? 'demo_user' : (string)$userId);
        self::$options = array_merge(
            array(
                'base_url' => '?act=',
                'allow_grant' => true,
                'grant_cents' => 10000,
                'knowledge_enabled' => true,
                'knowledge_entry' => 'app:ai_chat_demo',
                'knowledge_top_k' => 5,
                'knowledge_seed' => true,
            ),
            $options
        );

        $act = isset($_GET['act']) ? strtolower(trim((string)$_GET['act'])) : 'index';
        switch ($act) {
            case 'index':
                self::page();
                return;
            case 'conversations':
                self::jsonOut(200, '会话列表', $kernel->chat()->listConversations(self::$userId));
                return;
            case 'conversationdelete':
                self::actionDeleteConversation();
                return;
            case 'history':
                self::actionHistory();
                return;
            case 'wallet':
                self::actionWallet();
                return;
            case 'grant':
                self::actionGrant();
                return;
            case 'chat':
                self::actionChat();
                return;
            default:
                self::jsonOut(400, "未知动作 act={$act}", null);
        }
    }

    /**
     * 演示页 UI（fetch + ReadableStream 解析 SSE：POST 不能用 EventSource）
     * @return void
     */
    private static function page()
    {
        $base = self::$options['base_url'];
        $title = isset(self::$options['page_title']) ? (string)self::$options['page_title'] : 'AIFlow 对话 Demo';
        $grantBtn = self::$options['allow_grant']
            ? '<button id="topup" onclick="topUp()">充值 1 元（demo）</button>'
            : '';

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . htmlspecialchars($title) . '</title>';
        echo '<style>
h1{font-size:18px;margin:0;padding:12px 16px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;align-items:center}
h1 .sp{flex:1}
#walletBar{font-size:13px;color:#555}
#walletBar button{margin-left:8px;padding:3px 10px;border:1px solid #1a5fb4;border-radius:6px;background:#1a5fb4;color:#fff;cursor:pointer;font-size:12px}
.tip{color:#666;font-size:12px;padding:4px 16px 8px;background:#fff;border-bottom:1px solid #e5e7eb}
.main{flex:1;display:flex;overflow:hidden}
body{font-family:"Microsoft YaHei",Arial,sans-serif;margin:0;background:#f7f8fa;color:#222;height:100vh;display:flex;flex-direction:column}
.side{width:220px;background:#fff;border-right:1px solid #e5e7eb;padding:10px;overflow-y:auto}
.side button{width:100%;margin-bottom:6px;padding:6px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer}
.conv{padding:8px 6px;border-radius:6px;cursor:pointer;font-size:13px;line-height:1.4;color:#1a5fb4;word-break:break-all}
.conv:hover{background:#f3f4f6}.conv.active{background:#e8f0fe}
.chat{flex:1;display:flex;flex-direction:column;overflow:hidden}
.msgs{flex:1;overflow-y:auto;padding:16px}
.m{max-width:72%;margin:6px 0;padding:10px 12px;border-radius:10px;white-space:pre-wrap;word-break:break-all;line-height:1.6}
.m.user{background:#1a5fb4;color:#fff;margin-left:auto}
.m.assistant{background:#fff;border:1px solid #e5e7eb}
.m.error{background:#fde8e8;color:#b91c1c;border:1px solid #fca5a5}
.meta{font-size:11px;color:#999;margin:2px 0}
.bar{display:flex;padding:10px;background:#fff;border-top:1px solid #e5e7eb}
.bar textarea{flex:1;height:44px;resize:none;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-family:inherit}
.bar button{margin-left:8px;padding:0 18px;border:0;border-radius:8px;background:#1a5fb4;color:#fff;cursor:pointer}
.bar button:disabled{background:#9ca3af}
</style></head><body>';
        echo '<h1>' . htmlspecialchars($title) . '<span class="sp"></span>'
            . '<span id="walletBar">余额：<span id="bal">…</span> 分</span>' . $grantBtn . '</h1>';
        echo '<div class="tip">SSE 流式：POST 到 chat 端点（fetch + ReadableStream 解析）。'
            . '对话自动计费：预检冻结 → 按真实 token 清算；余额不足返回 402、未授权返回 403。'
            . (self::$options['knowledge_enabled']
                ? ' 已启用知识库：入口 <code>' . htmlspecialchars((string)self::$options['knowledge_entry'])
                  . '</code>，命中片段会注入 system（meta 含 knowledge_hits）。'
                : '')
            . '</div>';
        echo '<div class="main">';
        echo '<div class="side">';
        echo '<button onclick="newConv()">＋ 新会话</button>';
        echo '<button onclick="loadConvs()">↻ 刷新列表</button>';
        echo '<div id="convs"></div>';
        echo '</div>';
        echo '<div class="chat"><div class="msgs" id="msgs"></div>';
        echo '<div class="bar"><textarea id="q" placeholder="输入问题，Ctrl+Enter 发送"></textarea><button id="send" onclick="send()">发送</button></div>';
        echo '</div></div>';
        echo '<script>
var BASE = "' . addslashes($base) . '";
var W = ' . (self::$options['allow_grant'] ? 'true' : 'false') . ';
var GRANT_CENTS = ' . (int)self::$options['grant_cents'] . ';
var cid = "";
var sending = false;

// 余额刷新（wallet 端点）
async function loadBal() {
    try {
        var r = await fetch(BASE + "wallet");
        var j = await r.json();
        document.getElementById("bal").textContent = j.data.balance;
    } catch (e) { }
}
var topUp = async function () {
    try {
        var r = await fetch(BASE + "grant", { method: "POST" });
        var j = await r.json();
        if (j.code === 200) { loadBal(); alert("已充值 " + GRANT_CENTS + " 分（demo 模拟）"); }
        else { alert(j.info); }
    } catch (e) { alert("充值失败：" + e.message); }
};

// 会话列表
async function loadConvs() {
    var box = document.getElementById("convs");
    try {
        var r = await fetch(BASE + "conversations");
        var j = await r.json();
        box.innerHTML = "";
        (j.data || []).forEach(function (c) {
            var d = document.createElement("div");
            d.className = "conv" + (c.id === cid ? " active" : "");
            d.textContent = c.title || "(无标题)";
            d.title = "更新于 " + c.updated_at;
            d.onclick = function () { openConv(c.id); };
            box.appendChild(d);
        });
    } catch (e) { box.innerHTML = \'<div class="meta">列表加载失败</div>\'; }
}

// 打开历史会话
async function openConv(id) {
    cid = id;
    document.getElementById("msgs").innerHTML = "";
    try {
        var r = await fetch(BASE + "history&cid=" + encodeURIComponent(cid));
        var j = await r.json();
        (j.data || []).forEach(function (m) {
            if (m.role === "user" || m.role === "assistant") { addMsg(m.role, m.content); }
        });
    } catch (e) { addMsg("error", "历史加载失败：" + e.message); }
    loadConvs();
}

// 新会话（cid 清空，首条消息时后端自动开）
function newConv() {
    cid = "";
    document.getElementById("msgs").innerHTML = "";
    loadConvs();
}

function addMsg(role, text) {
    var box = document.getElementById("msgs");
    var d = document.createElement("div");
    d.className = "m " + role;
    d.textContent = text;
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    return d;
}

// 发送：POST → fetch 流式读 → 按 \n\n 切 SSE 帧
async function send() {
    if (sending) { return; }
    var ta = document.getElementById("q");
    var q = ta.value.trim();
    if (!q) { return; }
    ta.value = "";
    sending = true;
    document.getElementById("send").disabled = true;
    addMsg("user", q);
    var out = addMsg("assistant", "");
    try {
        var resp = await fetch(BASE + "chat", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ q: q, cid: cid })
        });
        // 非流式错误（402 积分不足 / 403 无权 / 400 参数 / 503 渠道缺失）：JSON 返回
        var ct = resp.headers.get("Content-Type") || "";
        if (ct.indexOf("json") >= 0) {
            var j = await resp.json();
            throw new Error(j.info || "请求失败");
        }
        var reader = resp.body.getReader();
        var dec = new TextDecoder();
        var buf = "";
        while (true) {
            var chunk = await reader.read();
            if (chunk.done) { break; }
            buf += dec.decode(chunk.value, { stream: true });
            var parts = buf.split("\\n\\n");
            buf = parts.pop();
            for (var i = 0; i < parts.length; i++) { handleFrame(parts[i], out); }
        }
    } catch (e) {
        out.className = "m error";
        out.textContent = (out.textContent || "") + "[出错] " + e.message;
    }
    sending = false;
    document.getElementById("send").disabled = false;
    loadConvs();
    loadBal();
}

// 单帧处理：event + 多行 data；meta 带 wallet_balance；done 带 amount/balance
function handleFrame(frame, out) {
    var event = "", dataLines = [];
    frame.split("\\n").forEach(function (line) {
        if (line.indexOf("event: ") === 0) { event = line.slice(7); }
        else if (line.indexOf("data: ") === 0) { dataLines.push(line.slice(6)); }
    });
    var data = dataLines.join("\\n");
    var kv;
    if (event === "delta") {
        out.textContent += data;
        var box = document.getElementById("msgs");
        box.scrollTop = box.scrollHeight;
    } else if (event === "meta") {
        var m = document.createElement("div");
        m.className = "meta";
        m.textContent = data;
        document.getElementById("msgs").insertBefore(m, out);
        kv = data.match(/conversation=(\S+)/);
        if (kv) { cid = kv[1]; }
        kv = data.match(/wallet_balance=(\S+)/);
        if (kv) { document.getElementById("bal").textContent = kv[1]; }
        kv = data.match(/knowledge_hits=(\S+)/);
        if (kv && parseInt(kv[1], 10) > 0) {
            m.textContent = data + "（已注入知识库片段）";
        }
    } else if (event === "done") {
        var m2 = document.createElement("div");
        m2.className = "meta";
        m2.textContent = "完成：" + data;
        document.getElementById("msgs").appendChild(m2);
        kv = data.match(/balance=(\S+)/);
        if (kv) { document.getElementById("bal").textContent = kv[1]; }
    } else if (event === "error") {
        out.className = "m error";
        out.textContent += "[流中出错] " + data;
    }
}

document.getElementById("q").addEventListener("keydown", function (e) {
    if (e.ctrlKey && e.key === "Enter") { send(); }
});
loadConvs();
loadBal();
</script></body></html>';
    }

    /**
     * 删除当前用户的一场会话（消息一并删除）
     * @return void
     */
    private static function actionDeleteConversation()
    {
        $cid = isset($_REQUEST['cid']) ? trim((string)$_REQUEST['cid']) : '';
        if ($cid === '' || !preg_match('/^[0-9a-f]{32}$/', $cid)) {
            self::jsonOut(400, 'cid 必须为 32 位十六进制会话ID', null);
            return;
        }
        try {
            self::$kernel->chat()->deleteConversation(self::$userId, $cid);
            self::jsonOut(200, '已删除', array('id' => $cid));
        } catch (\InvalidArgumentException $e) {
            self::jsonOut(400, $e->getMessage(), null);
        }
    }

    /**
     * 会话消息历史端点
     * @return void
     */
    private static function actionHistory()
    {
        $cid = isset($_REQUEST['cid']) ? trim((string)$_REQUEST['cid']) : '';
        if ($cid === '' || !preg_match('/^[0-9a-f]{32}$/', $cid)) {
            self::jsonOut(400, 'cid 必须为 32 位十六进制会话ID', null);
            return;
        }
        try {
            self::jsonOut(200, '消息历史', self::$kernel->chat()->history($cid));
        } catch (\InvalidArgumentException $e) {
            self::jsonOut(400, $e->getMessage(), null);
        }
    }

    /**
     * 钱包端点（余额/冻结，供页头余额栏轮询）
     * @return void
     */
    private static function actionWallet()
    {
        try {
            $info = self::$kernel->credit()->walletInfo('user', self::$userId);
            if ($info === null) {
                // 无钱包 = 余额 0（避免前端判空分支）
                $info = array('balance' => 0, 'frozen' => 0);
            }
            self::jsonOut(200, '钱包', $info);
        } catch (\Throwable $e) {
            self::jsonOut(500, $e->getMessage(), null);
        }
    }

    /**
     * demo 充值端点（模拟支付；生产宿主关 allow_grant 接真实充值渠道）
     * @return void
     */
    private static function actionGrant()
    {
        if (!self::$options['allow_grant']) {
            self::jsonOut(403, 'demo 充值入口未开放', null);
            return;
        }
        try {
            $info = self::$kernel->credit()->grant('user', self::$userId, (int)self::$options['grant_cents']);
            self::jsonOut(200, '已充值', $info);
        } catch (\InvalidArgumentException $e) {
            self::jsonOut(400, $e->getMessage(), null);
        } catch (\Throwable $e) {
            self::jsonOut(500, $e->getMessage(), null);
        }
    }

    /**
     * 无绑定时灌入演示 FAQ 并绑定 knowledge_entry（假数据，便于页面立刻看到命中）
     * @return void
     */
    private static function ensureKnowledgeSeed()
    {
        if (empty(self::$options['knowledge_seed'])) {
            return;
        }
        $entryId = (string)self::$options['knowledge_entry'];
        $kb = self::$kernel->knowledge();
        if ($kb->bindingsOf($entryId, 'prod') !== array()) {
            return;
        }
        $code = 'demo.chat_faq';
        $base = $kb->getBaseByCode($code);
        if ($base === null) {
            $baseId = $kb->createBase($code, '演示FAQ', self::$userId, 'ChatDemo 自动灌入，非生产内容');
            $docId = $kb->ingestText(
                $baseId,
                '退货与余额',
                "问：退货几天到账？\n答：一般三个工作日入账。\n\n问：如何查余额？\n答：打开钱包页或看页头「余额」即可。"
            );
            $kb->setDocumentEnabled($docId, true);
        } else {
            $baseId = (string)$base['id'];
        }
        $kb->bind($entryId, $baseId, 'prod', self::$userId, true);
    }

    /**
     * chat SSE 流式端点（POST q + cid；cid 空 = 自动开新会话）
     * 链路：guard → 知识库注入 → ensureWallet → Billing 预检冻结 → chatStream → 真实用量清算
     * @return void
     */
    private static function actionChat()
    {
        @set_time_limit(0);

        $question = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
        $cid = isset($_POST['cid']) ? trim((string)$_POST['cid']) : '';
        if ($question === '') {
            self::jsonOut(400, 'q 不能为空', null);
            return;
        }
        if ($cid !== '' && !preg_match('/^[0-9a-f]{32}$/', $cid)) {
            self::jsonOut(400, 'cid 格式非法', null);
            return;
        }

        $kernel = self::$kernel;
        try {
            $channels = $kernel->gateway()->channels();
            $channel = isset($channels[0]) ? $channels[0] : '';
            if ($channel === '') {
                self::jsonOut(503, '未注册任何模型渠道：请设置环境变量 AIFLOW_QWEN_KEY 或 AIFLOW_DEEPSEEK_KEY', null);
                return;
            }

            // 权限门：未授权（头未发）→ 403 JSON，且不落任何会话数据
            $kernel->guard()->guard(self::$userId, 'app:ai_chat_demo');

            // 开/续会话并落用户消息
            if ($cid === '') {
                $cid = $kernel->chat()->startConversation(self::$userId, 'ai_chat_demo', function_exists('mb_substr') ? mb_substr($question, 0, 20) : substr($question, 0, 20));
            }
            $kernel->chat()->appendMessage($cid, 'user', $question);

            // 历史作为上下文（最近 20 条），拼接内容做输入 token 粗估
            $messages = array();
            $inputText = '';
            foreach ($kernel->chat()->history($cid, 20) as $m) {
                if ($m['role'] === 'user' || $m['role'] === 'assistant') {
                    $messages[] = array('role' => $m['role'], 'content' => $m['content']);
                    $inputText .= $m['content'] . "\n";
                }
            }
            // 编目控制台：个人规则 + 顶栏所选技能说明书。切换技能不改入口绑定。
            $skillId = isset($_POST['skill_id']) ? trim((string)$_POST['skill_id']) : '';
            $prefix = (new \AIFlow\Catalog\CatalogService($kernel->storage()))->chatPrefix(self::$userId, $skillId);
            if ($prefix !== '') {
                array_unshift($messages, array('role' => 'system', 'content' => $prefix));
                $inputText = $prefix . "\n" . $inputText;
            }

            // 知识库：按入口检索并注入 system（首次无绑定时可自动灌演示 FAQ）
            $knowledgeHits = 0;
            if (!empty(self::$options['knowledge_enabled'])) {
                self::ensureKnowledgeSeed();
                $entryId = (string)self::$options['knowledge_entry'];
                $topK = max(1, (int)self::$options['knowledge_top_k']);
                $hits = $kernel->knowledge()->retrieve($entryId, $question, array('top_k' => $topK));
                $knowledgeHits = count($hits);
                if ($hits !== array()) {
                    $messages = KnowledgeContext::prependToMessages($messages, $hits);
                    $kbText = KnowledgeContext::toText($hits);
                    if ($kbText !== '') {
                        $inputText = $kbText . "\n" . $inputText;
                    }
                }
            }

            $model = (string)$kernel->config()->get('models.' . $channel . '.model', $channel);
            $credit = $kernel->credit();
            $walletId = $credit->ensureWallet('user', self::$userId);
            $balance = $credit->walletInfo('user', self::$userId);
            $refId = Id::gen();
            $billing = new Billing($credit);

            // 两阶段计费：预检失败在流式头之前 → 402 JSON，模型零调用
            $start = microtime(true);
            $billed = $billing->run(
                $walletId,
                $model,
                $refId,
                function () use ($kernel, $channel, $messages, $cid, $balance, $knowledgeHits) {
                    // reserve 已成功才开流（余额不足时这里根本不会执行）
                    SseEmitter::start();
                    SseEmitter::send(
                        'meta',
                        'channel=' . $channel
                        . ' conversation=' . $cid
                        . ' wallet_balance=' . (int)$balance['balance']
                        . ' knowledge_hits=' . (int)$knowledgeHits
                    );
                    // stream_options.include_usage：千问兼容模式流式需显式要求才在末 chunk 返回用量
                    return $kernel->gateway()->chatStream($channel, $messages, array(
                        'stream_options' => array('include_usage' => true),
                    ), function ($delta) {
                        SseEmitter::send('delta', $delta);
                    });
                },
                Billing::estimateTokens($inputText)
            );

            $result = $billed['result'];
            $kernel->chat()->appendMessage(
                $cid,
                'assistant',
                $result->content,
                $result->inputTokens,
                $result->outputTokens,
                (int)round((microtime(true) - $start) * 1000)
            );

            SseEmitter::close(
                'conversation=' . $cid
                . ' input_tokens=' . $result->inputTokens
                . ' output_tokens=' . $result->outputTokens
                . ' amount=' . $billed['amount']
                . ' balance=' . ($billed['balance'] === null ? '?' : (string)$billed['balance'])
            );
        } catch (PermissionDeniedException $e) {
            if (headers_sent()) {
                SseEmitter::send('error', '无权访问 ' . $e->resource);
            } else {
                self::jsonOut(403, "无权访问 {$e->resource}（角色未授权 app:ai_chat_demo）", null);
            }
        } catch (InsufficientCreditException $e) {
            if (headers_sent()) {
                SseEmitter::send('error', "积分不足：预估 {$e->need} 分，可用 {$e->have} 分");
            } else {
                self::jsonOut(402, "积分不足：本次预估 {$e->need} 分，当前可用 {$e->have} 分（模型未被调用）", null);
            }
        } catch (\Throwable $e) {
            // 流式头已发只能走 SSE error 帧；未发走统一 JSON
            if (headers_sent()) {
                SseEmitter::send('error', $e->getMessage());
            } else {
                self::jsonOut(500, $e->getMessage(), null);
            }
        }
    }

    /**
     * 统一 JSON 输出（code/info/data 三段式，同 FMS 惯例）
     *
     * @param int      $code HTTP 状态码 / 业务码
     * @param string   $info 提示
     * @param mixed    $data 数据载荷
     * @return void
     */
    private static function jsonOut($code, $info, $data)
    {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            array('code' => (int)$code, 'info' => $info, 'data' => $data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}