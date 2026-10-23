<?php
declare(strict_types=1);

namespace AIFlow\Tests\Workflow;

use AIFlow\AIKernel;
use AIFlow\Demo\CatalogConsole;
use PHPUnit\Framework\TestCase;

class CatalogGraphConsoleTest extends TestCase
{
    /** @var AIKernel */
    private $kernel;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/demo/CatalogConsole.php';
        $this->kernel = AIKernel::boot(array(
            'storage' => array('dsn' => 'sqlite::memory:', 'user' => '', 'pass' => ''),
            'cache' => array('driver' => 'array'),
            'migration' => array('auto' => true),
            'access' => array('default_roles' => array('user')),
        ));
        $this->kernel->guard()->grant('user', 'app', 'ai_chat_demo', 'allow');
    }

    public function testCreatePublishAndRunFromConsole(): void
    {
        $created = $this->call('workflowsave', array(
            'name' => '利润草稿',
            'tag' => 'profit',
            'description' => '开始到结束',
            'status' => 'offline',
            'is_template' => '0',
        ));
        $this->assertSame(200, $created['code']);
        $id = $created['data']['id'];

        $list = $this->call('workflows', array());
        $this->assertSame(1, $list['data'][0]['has_draft']);
        $this->assertSame(0, $list['data'][0]['published']);

        $early = $this->call('workflowrun', array('id' => $id, 'input' => '{"year":"2026"}'));
        $this->assertSame(400, $early['code']);

        $published = $this->call('workflowpublish', array('id' => $id));
        $this->assertSame(200, $published['code']);
        $this->assertSame('start', $published['data']['published']['nodes'][0]['id']);

        $ran = $this->call('workflowrun', array('id' => $id, 'input' => '{"year":"2026"}'));
        $this->assertSame(200, $ran['code']);
        $this->assertSame('success', $ran['data']['status']);
        $this->assertSame('2026', $ran['data']['context']['year']);
    }

    public function testUnknownNodeRejectedAndCopyKeepsDraft(): void
    {
        $created = $this->call('workflowsave', array(
            'name' => '原图',
            'tag' => '',
            'description' => '',
            'status' => 'offline',
        ));
        $id = $created['data']['id'];
        $bad = $this->call('workflowgraphsave', array(
            'id' => $id,
            'graph' => '{"nodes":[{"id":"start","type":"http","title":"HTTP"}],"edges":[]}',
        ));
        $this->assertSame(400, $bad['code']);
        $this->assertStringContainsString('未注册', $bad['info']);

        $graph = $this->call('workflowgraph', array('id' => $id));
        $graph['data']['draft']['nodes'][1]['title'] = '改过的结束';
        $saved = $this->call('workflowgraphsave', array(
            'id' => $id,
            'graph' => json_encode($graph['data']['draft'], JSON_UNESCAPED_UNICODE),
        ));
        $this->assertSame(200, $saved['code']);

        $copy = $this->call('workflowcopy', array('id' => $id));
        $this->assertSame(200, $copy['code']);
        $copied = $this->call('workflowgraph', array('id' => $copy['data']['id']));
        $this->assertSame('改过的结束', $copied['data']['draft']['nodes'][1]['title']);
        $this->assertNull($copied['data']['published']);
    }

    /** @return array */
    private function call(string $act, array $post): array
    {
        $_GET = array('act' => $act);
        $_POST = $post;
        ob_start();
        CatalogConsole::run($this->kernel, 'demo_user');
        $raw = ob_get_clean();
        $json = json_decode((string)$raw, true);
        $this->assertIsArray($json, (string)$raw);
        return $json;
    }
}