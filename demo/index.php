<?php
/**
 * AIFlow 独立演示入口（仅本地：php -S localhost:8080 demo/index.php）
 *
 * 无宿主框架、无 SSO——对外暴露时请由宿主自行把守登录态。
 * 环境变量（二选一渠道）：
 *   AIFLOW_QWEN_KEY     通义千问 api_key（DashScope，模型 qwen-plus）
 *   AIFLOW_DEEPSEEK_KEY DeepSeek api_key（模型 deepseek-chat）
 * 可选：
 *   AIFLOW_DEMO_DSN       存储 DSN（缺省 sqlite:./demo/aiflow_demo.sqlite 持久文件）
 *   AIFLOW_DEMO_DB_USER / AIFLOW_DEMO_DB_PASS   MySQL 用户/密码（如 root/root）
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit("请先在 aiflow 根目录执行 composer install\n");
}
require $autoload;
require __DIR__ . '/ChatDemoPage.php';
require __DIR__ . '/CatalogConsole.php';

// 渠道装配：有 key 才注册（与宿主挂载同一约定）
$models = array();
if (($key = getenv('AIFLOW_QWEN_KEY')) !== false && $key !== '') {
    $models['qwen'] = array(
        'driver' => 'openai',
        'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
        'api_key' => $key,
        'model' => 'qwen-plus',
    );
}
if (($key = getenv('AIFLOW_DEEPSEEK_KEY')) !== false && $key !== '') {
    $models['deepseek'] = array(
        'driver' => 'openai',
        'base_url' => 'https://api.deepseek.com/v1',
        'api_key' => $key,
        'model' => 'deepseek-chat',
    );
}

$kernel = \AIFlow\AIKernel::boot(array(
    'storage' => array(
        'dsn' => (getenv('AIFLOW_DEMO_DSN') !== false && getenv('AIFLOW_DEMO_DSN') !== '')
            ? getenv('AIFLOW_DEMO_DSN')
            : 'sqlite:' . __DIR__ . '/aiflow_demo.sqlite',
        'user' => (string)(getenv('AIFLOW_DEMO_DB_USER') !== false ? getenv('AIFLOW_DEMO_DB_USER') : ''),
        'pass' => (string)(getenv('AIFLOW_DEMO_DB_PASS') !== false ? getenv('AIFLOW_DEMO_DB_PASS') : ''),
    ),
    'migration' => array('auto' => true), // 独立入口自动建表
    'models' => $models,
));

// 独立入口为演示方便自动给内置 user 角色授权（幂等 upsert）；
// 正式宿主请勿模仿——授权应走管理界面显式下发
$guard = $kernel->guard();
$guard->ensureRole('user', '演示用户');
$guard->ensureResource('app', 'ai_chat_demo', '对话 Demo');
$guard->grant('user', 'app', 'ai_chat_demo', 'allow');

// demo 计价（幂等 upsert）：qwen-plus 官方价约 0.8/2 元每百万 token（单位分）；
// 倍率 1000 只为演示小额对话也可见扣费动效——真实宿主请用倍率 1
$kernel->credit()->setPriceRule('qwen-plus', 800, 2000, 1000.0);

\AIFlow\Demo\CatalogConsole::run($kernel, 'demo_user', array('base_url' => '?act='));