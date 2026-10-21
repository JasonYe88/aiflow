<?php
declare(strict_types=1);

namespace AIFlow\Chat;

use AIFlow\Contract\StorageInterface;
use AIFlow\Support\Id;

/**
 * 会话/消息服务：对话的持久化层（表：ai_conversation / ai_message，M0 已建）
 * M2 的积分计量直接消费 appendMessage 的 token 字段
 */
final class ChatService
{
    /** @var StorageInterface */
    private $storage;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /** 开新会话，返回会话 ID；updated_seq 初始为会话 id（毫秒前缀，单调递增） */
    public function startConversation(string $userId, string $appId, string $title = ''): string
    {
        $id = Id::gen();
        $now = date('Y-m-d H:i:s');
        $this->storage->execute(
            'INSERT INTO ai_conversation (id, user_id, app_id, title, created_at, updated_at, updated_seq) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $userId, $appId, $title, $now, $now, $id]
        );
        return $id;
    }

    /**
     * 用户会话列表（最新活跃在前）
     * 排序用 updated_seq（毫秒精度单调递增）而非秒级 updated_at：
     * 同一秒内多次活跃的相对先后也能稳定表达（002 迁移引入）
     */
    public function listConversations(string $userId, int $limit = 50): array
    {
        return $this->storage->query(
            'SELECT id, title, created_at, updated_at FROM ai_conversation
             WHERE user_id = ? ORDER BY updated_seq DESC LIMIT ' . (int)$limit,
            [$userId]
        );
    }

    /** 会话消息（时间正序），未知会话返回空数组；id 为时间戳前缀 hex，同秒内天然有序，双端排序一致 */
    public function history(string $conversationId, int $limit = 200): array
    {
        return $this->storage->query(
            'SELECT role, content, input_tokens, output_tokens, elapsed_ms, created_at FROM ai_message
             WHERE conversation_id = ? ORDER BY created_at ASC, id ASC LIMIT ' . (int)$limit,
            [$conversationId]
        );
    }

    /** 追加消息（user/assistant/system/tool），返回消息 ID；消息 id 同时作为会话活跃序列 */
    public function appendMessage(string $conversationId, string $role, string $content, int $inputTokens = 0, int $outputTokens = 0, int $elapsedMs = 0): string
    {
        $id = Id::gen();
        $this->storage->execute(
            'INSERT INTO ai_message (id, conversation_id, role, content, input_tokens, output_tokens, elapsed_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $conversationId, $role, $content, $inputTokens, $outputTokens, $elapsedMs, date('Y-m-d H:i:s')]
        );
        // 会话活跃度前移：updated_at 供展示，updated_seq（= 消息 id，毫秒单调）供排序
        $this->storage->execute(
            'UPDATE ai_conversation SET updated_at = ?, updated_seq = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $id, $conversationId]
        );
        return $id;
    }
}
