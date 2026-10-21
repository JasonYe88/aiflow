<?php
declare(strict_types=1);

namespace AIFlow\Tests\Gateway;

use AIFlow\Gateway\ModelGateway;
use AIFlow\Support\ModelResult;
use PHPUnit\Framework\TestCase;

class ModelGatewayTest extends TestCase
{
    public function testChatRoutesToRegisteredProvider(): void
    {
        $gateway = new ModelGateway();
        $gateway->add('deepseek', new class implements \AIFlow\Contract\ModelProviderInterface {
            public function chat(array $messages, array $options = []): ModelResult
            {
                return new ModelResult('来自假提供方', 'fake', 1, 2, 3);
            }
            public function chatStream(array $messages, array $options, callable $onDelta): ModelResult
            {
                call_user_func($onDelta, '来自');
                call_user_func($onDelta, '假提供方');
                return new ModelResult('来自假提供方', 'fake', 1, 2, 3);
            }
        });

        $this->assertSame('来自假提供方', $gateway->chat('deepseek', [['role' => 'user', 'content' => 'hi']])->content);

        $deltas = [];
        $result = $gateway->chatStream('deepseek', [['role' => 'user', 'content' => 'hi']], [], function ($d) use (&$deltas) {
            $deltas[] = $d;
        });
        $this->assertSame(['来自', '假提供方'], $deltas);
        $this->assertSame('来自假提供方', $result->content);
    }

    public function testChannelsListing(): void
    {
        $gateway = new ModelGateway();
        $gateway->add('deepseek', new class implements \AIFlow\Contract\ModelProviderInterface {
            public function chat(array $messages, array $options = []): ModelResult
            {
                return new ModelResult('', 'fake', 0, 0, 1);
            }
            public function chatStream(array $messages, array $options, callable $onDelta): ModelResult
            {
                return new ModelResult('', 'fake', 0, 0, 1);
            }
        });
        $this->assertSame(['deepseek'], $gateway->channels());
        $this->assertTrue($gateway->has('deepseek'));
    }

    public function testUnknownModelThrows(): void
    {
        $gateway = new ModelGateway();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未注册的模型渠道：foo');
        $gateway->chat('foo', []);
    }
}
