<?php
declare(strict_types=1);

namespace AIFlow\Tests\Chat;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Chat\ChatService;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class ChatServiceTest extends TestCase
{
    /** @var ChatService */
    private $chat;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->chat = new ChatService($storage);
    }

    public function testConversationLifecycle(): void
    {
        $cid = $this->chat->startConversation('demo_user', 'chat_demo', '第一个会话');
        $this->assertSame(32, strlen($cid));

        // 会话列表按 updated_at 倒序
        $this->chat->startConversation('demo_user', 'chat_demo', '第二个会话');
        $list = $this->chat->listConversations('demo_user');
        $this->assertCount(2, $list);
        $this->assertSame('第二个会话', $list[0]['title']);
        $this->assertSame('第一个会话', $list[1]['title']);

        // 别的用户看不到
        $this->assertCount(0, $this->chat->listConversations('other_user'));
    }

    public function testAppendAndHistory(): void
    {
        $cid = $this->chat->startConversation('demo_user', 'chat_demo');
        $this->chat->appendMessage($cid, 'user', '你好');
        $this->chat->appendMessage($cid, 'assistant', '你好，有什么可以帮你？', 11, 7, 560);

        $history = $this->chat->history($cid);
        $this->assertCount(2, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('你好', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertSame(11, (int)$history[1]['input_tokens']);
        $this->assertSame(7, (int)$history[1]['output_tokens']);
        $this->assertSame(560, (int)$history[1]['elapsed_ms']);

        // append 会话 updated_at 前移（列表排序依据）
        $this->chat->appendMessage($cid, 'user', '再来');
        $list = $this->chat->listConversations('demo_user');
        $this->assertSame($cid, $list[0]['id']);
    }

    public function testDeleteConversationRemovesMessages(): void
    {
        $cid = $this->chat->startConversation('demo_user', 'chat_demo', '要删的');
        $this->chat->appendMessage($cid, 'user', '你好');
        $keep = $this->chat->startConversation('demo_user', 'chat_demo', '留下');

        $this->chat->deleteConversation('demo_user', $cid);

        $list = $this->chat->listConversations('demo_user');
        $this->assertCount(1, $list);
        $this->assertSame($keep, $list[0]['id']);
        $this->assertSame([], $this->chat->history($cid));
    }

    public function testDeleteOtherUsersConversationRejected(): void
    {
        $cid = $this->chat->startConversation('demo_user', 'chat_demo', '别人的');
        try {
            $this->chat->deleteConversation('other_user', $cid);
            $this->fail('不应删除别人的会话');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('会话不存在', $e->getMessage());
        }
        $this->assertCount(1, $this->chat->listConversations('demo_user'));
    }

    public function testHistoryOfUnknownConversationIsEmpty(): void
    {
        $this->assertSame([], $this->chat->history('no_such_id'));
    }
}
