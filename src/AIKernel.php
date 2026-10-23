<?php
declare(strict_types=1);

namespace AIFlow;

use AIFlow\Access\AccessService;
use AIFlow\Access\FixedUserProvider;
use AIFlow\Adapter\ArrayCache;
use AIFlow\Adapter\CurlHttpClient;
use AIFlow\Adapter\PdoStorage;
use AIFlow\Adapter\RedisCache;
use AIFlow\Agent\AgentRuntime;
use AIFlow\Chat\ChatService;
use AIFlow\Contract\CacheInterface;
use AIFlow\Contract\StorageInterface;
use AIFlow\Contract\UserProviderInterface;
use AIFlow\Credit\CreditService;
use AIFlow\Gateway\ModelGateway;
use AIFlow\Gateway\OpenAiCompatibleProvider;
use AIFlow\Contract\HttpClientInterface;
use AIFlow\Knowledge\EmbeddingIndexer;
use AIFlow\Knowledge\EmbeddingRetriever;
use AIFlow\Knowledge\HttpRagClient;
use AIFlow\Knowledge\HttpRagRetriever;
use AIFlow\Knowledge\HybridRetriever;
use AIFlow\Knowledge\KnowledgeService;
use AIFlow\Knowledge\RetrieverInterface;
use AIFlow\Knowledge\SimpleKeywordRetriever;
use AIFlow\Support\Config;
use AIFlow\Support\Migrator;
use AIFlow\Workflow\GraphWorkflow;
use AIFlow\Workflow\WorkflowEngine;
use PDO;
use Redis;

/**
 * 框架唯一入口
 * AIKernel::boot($config) 引导内核，装配适配器
 *
 * 配置树：
 *   storage:   { dsn, user?, pass? }        PDO DSN，如 mysql:... 或 sqlite::memory:
 *   cache:     { driver: array|redis, host?, port?, db? }
 *   migration: { dir?, auto?: bool }         dir 缺省为包内 migrations/
 *   models:    { 渠道名: { driver: openai, base_url, api_key, model }, http_timeout?: int }
 *   access:    { user_provider?: UserProviderInterface, roles_map?: {userId: [role]}, default_roles?: [role] }（M2）
 *   knowledge: { retriever?: simple|http|embedding|hybrid, rag_url?, rag_token?, embed_channel?,
 *                embed_batch?, embed_min_score?, hybrid_keyword_weight?, hybrid_embed_weight? }
 */
final class AIKernel
{
    /** @var Config */
    private $config;

    /** @var StorageInterface */
    private $storage;

    /** @var CacheInterface */
    private $cache;

    /** @var ModelGateway|null 惰性 */
    private $gateway;

    /** @var ChatService|null 惰性 */
    private $chat;

    /** @var CreditService|null 惰性 */
    private $credit;

    /** @var AccessService|null 惰性 */
    private $guard;

    /** @var AgentRuntime|null 惰性 */
    private $agent;

    /** @var WorkflowEngine|null 惰性 */
    private $workflow;

    /** @var GraphWorkflow|null 惰性 */
    private $graph;

    /** @var KnowledgeService|null 惰性 */
    private $knowledge;

    private function __construct(Config $config)
    {
        $this->config = $config;
        $this->storage = $this->makeStorage();
        $this->cache = $this->makeCache();
        if ($config->get('migration.auto', false)) {
            $this->migrate();
        }
    }

    public static function boot(array $config): self
    {
        return new self(new Config($config));
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function storage(): StorageInterface
    {
        return $this->storage;
    }

    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    /** 模型网关（惰性单例） */
    public function gateway(): ModelGateway
    {
        if ($this->gateway === null) {
            $this->gateway = $this->makeGateway();
        }
        return $this->gateway;
    }

    /** 会话/消息服务（惰性单例） */
    public function chat(): ChatService
    {
        if ($this->chat === null) {
            $this->chat = new ChatService($this->storage);
        }
        return $this->chat;
    }

    /** 积分/计价/钱包/账本服务（惰性单例） */
    public function credit(): CreditService
    {
        if ($this->credit === null) {
            $this->credit = new CreditService($this->storage);
        }
        return $this->credit;
    }

    /** RBAC 权限判定服务（惰性单例）；配置见 access 段 */
    public function guard(): AccessService
    {
        if ($this->guard === null) {
            $this->guard = new AccessService($this->storage, $this->makeUserProvider());
        }
        return $this->guard;
    }

    /** Agent 运行时（惰性单例，挂模型网关）；工具注册后即可 function-calling 循环 */
    public function agent(): AgentRuntime
    {
        if ($this->agent === null) {
            $this->agent = new AgentRuntime($this->gateway());
        }
        return $this->agent;
    }

    /** 工作流引擎（惰性单例，挂存储 + 模型网关）：run() 同步执行 / dispatch() 异步入队 + consume() 消费 */
    public function workflow(): WorkflowEngine
    {
        if ($this->workflow === null) {
            $this->workflow = new WorkflowEngine($this->storage, $this->gateway());
        }
        return $this->workflow;
    }

    /** 图工作流（惰性单例）：草稿、发布、按连线运行。节点明细用 register() 后补 */
    public function graph(): GraphWorkflow
    {
        if ($this->graph === null) {
            $this->graph = new GraphWorkflow($this->storage);
        }
        return $this->graph;
    }

    /** 知识库架子（惰性单例）：建库/入库分段/绑定/检索；retriever=http|embedding 见配置 */
    public function knowledge(): KnowledgeService
    {
        if ($this->knowledge === null) {
            $this->knowledge = new KnowledgeService($this->storage, $this->makeRetriever());
        }
        return $this->knowledge;
    }

    /**
     * 向量索引器（按需新建，非单例）：依赖 knowledge.embed_channel 对应的 embeddings 渠道
     */
    public function embeddingIndexer(): EmbeddingIndexer
    {
        $channel = (string)$this->config->get('knowledge.embed_channel', '');
        if ($channel === '') {
            throw new \InvalidArgumentException('请配置 knowledge.embed_channel（embeddings 渠道名）');
        }
        return new EmbeddingIndexer(
            $this->storage,
            $this->gateway(),
            $channel,
            (int)$this->config->get('knowledge.embed_batch', 16)
        );
    }

    /** 执行迁移，返回应用的迁移版本 */
    public function migrate(): array
    {
        return (new Migrator(
            $this->storage,
            (string)$this->config->get('migration.dir', dirname(__DIR__) . '/migrations')
        ))->migrate();
    }

    private function makeStorage(): StorageInterface
    {
        $dsn = (string)$this->config->get('storage.dsn');
        $pdo = new PDO(
            $dsn,
            (string)$this->config->get('storage.user'),
            (string)$this->config->get('storage.pass')
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return new PdoStorage($pdo);
    }

    private function makeCache(): CacheInterface
    {
        if ($this->config->get('cache.driver') === 'redis') {
            if (!class_exists(Redis::class)) {
                trigger_error(
                    'aiflow: ext-redis 未安装，cache 降级为 ArrayCache（进程内不共享，Swoole 场景慎用）',
                    E_USER_WARNING
                );
                return new ArrayCache();
            }
            $redis = new Redis();
            $redis->connect(
                (string)$this->config->get('cache.host', '127.0.0.1'),
                (int)$this->config->get('cache.port', 6379)
            );
            $redis->select((int)$this->config->get('cache.db', 0));
            return new RedisCache($redis);
        }
        return new ArrayCache();
    }

    /** models 配置 → 渠道注册（目前支持 driver=openai，M2+ 扩展更多驱动） */
    private function makeGateway(): ModelGateway
    {
        $gateway = new ModelGateway();
        $http = new CurlHttpClient((int)$this->config->get('models.http_timeout', 120));
        foreach ((array)$this->config->get('models', []) as $channel => $conf) {
            if (!is_array($conf) || ($conf['driver'] ?? '') !== 'openai') {
                continue; // 未知驱动忽略，保持前向兼容
            }
            $gateway->add((string)$channel, new OpenAiCompatibleProvider(
                (string)$conf['base_url'],
                (string)$conf['api_key'],
                (string)($conf['model'] ?? $channel),
                $http
            ));
        }
        return $gateway;
    }

    /**
     * access.user_provider：已实现 UserProviderInterface 的实例 → 直接用；
     * 未配置 → FixedUserProvider（access.roles_map 精确映射 + access.default_roles 兜底，默认 ['user']）
     */
    private function makeUserProvider(): UserProviderInterface
    {
        $provider = $this->config->get('access.user_provider');
        if ($provider instanceof UserProviderInterface) {
            return $provider;
        }
        return new FixedUserProvider(
            (array)$this->config->get('access.roles_map', []),
            (array)$this->config->get('access.default_roles', ['user'])
        );
    }

    private function makeRetriever(): RetrieverInterface
    {
        $custom = $this->config->get('knowledge.retriever_instance');
        if ($custom instanceof RetrieverInterface) {
            return $custom;
        }
        $driver = (string)$this->config->get('knowledge.retriever', 'simple');
        if ($driver === 'http') {
            $http = $this->config->get('knowledge.http');
            if (!$http instanceof HttpClientInterface) {
                $http = new CurlHttpClient((int)$this->config->get(
                    'knowledge.http_timeout',
                    (int)$this->config->get('models.http_timeout', 30)
                ));
            }
            $client = new HttpRagClient(
                $http,
                (string)$this->config->get('knowledge.rag_url', 'http://127.0.0.1:8765'),
                (string)$this->config->get('knowledge.rag_token', '')
            );
            return new HttpRagRetriever($client);
        }
        if ($driver === 'embedding') {
            return $this->makeEmbeddingRetriever();
        }
        if ($driver === 'hybrid') {
            return new HybridRetriever(
                new SimpleKeywordRetriever($this->storage),
                $this->makeEmbeddingRetriever(),
                (float)$this->config->get('knowledge.hybrid_keyword_weight', 0.4),
                (float)$this->config->get('knowledge.hybrid_embed_weight', 0.6)
            );
        }
        return new SimpleKeywordRetriever($this->storage);
    }

    private function makeEmbeddingRetriever(): EmbeddingRetriever
    {
        $channel = (string)$this->config->get('knowledge.embed_channel', '');
        if ($channel === '') {
            throw new \InvalidArgumentException('knowledge.retriever 为 embedding/hybrid 时须配置 knowledge.embed_channel');
        }
        return new EmbeddingRetriever(
            $this->storage,
            $this->gateway(),
            $channel,
            (float)$this->config->get('knowledge.embed_min_score', 0.0)
        );
    }
}
