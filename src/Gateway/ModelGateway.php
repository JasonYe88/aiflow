<?php
declare(strict_types=1);

namespace AIFlow\Gateway;

use AIFlow\Contract\EmbeddingCapableInterface;
use AIFlow\Contract\ModelProviderInterface;
use AIFlow\Support\ModelResult;

/**
 * 模型网关：渠道名 → 提供方的注册表，业务侧统一入口
 * $kernel->gateway()->chat('deepseek', $messages)
 */
final class ModelGateway
{
    /** @var ModelProviderInterface[] */
    private $providers = [];

    public function add(string $channel, ModelProviderInterface $provider): void
    {
        $this->providers[$channel] = $provider;
    }

    public function has(string $channel): bool
    {
        return isset($this->providers[$channel]);
    }

    /** @return string[] 已注册渠道名 */
    public function channels(): array
    {
        return array_keys($this->providers);
    }

    public function chat(string $channel, array $messages, array $options = []): ModelResult
    {
        return $this->provider($channel)->chat($messages, $options);
    }

    public function chatStream(string $channel, array $messages, array $options, callable $onDelta): ModelResult
    {
        return $this->provider($channel)->chatStream($messages, $options, $onDelta);
    }

    /**
     * OpenAI 兼容向量：渠道须实现 EmbeddingCapableInterface
     *
     * @param string[] $texts
     * @return float[][]
     */
    public function embed(string $channel, array $texts, array $options = []): array
    {
        $provider = $this->provider($channel);
        if (!$provider instanceof EmbeddingCapableInterface) {
            throw new \InvalidArgumentException("渠道 {$channel} 不支持 embeddings");
        }
        return $provider->embed($texts, $options);
    }

    private function provider(string $channel): ModelProviderInterface
    {
        if (!isset($this->providers[$channel])) {
            throw new \InvalidArgumentException("未注册的模型渠道：{$channel}");
        }
        return $this->providers[$channel];
    }
}
