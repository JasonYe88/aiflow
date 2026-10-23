<?php
declare(strict_types=1);

namespace AIFlow\Tests\Catalog;

use AIFlow\Adapter\PdoStorage;
use AIFlow\Catalog\CatalogService;
use AIFlow\Support\Migrator;
use PHPUnit\Framework\TestCase;

class CatalogServiceTest extends TestCase
{
    /** @var CatalogService */
    private $catalog;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $storage = new PdoStorage($pdo);
        (new Migrator($storage, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->catalog = new CatalogService($storage);
    }

    public function testCreateSkillRejectsDuplicate(): void
    {
        $this->catalog->createSkill('u1', 'demo.author', '编目', '摘要', '说明书', array('demo.list'));
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->createSkill('u1', 'demo.author', '另一份', '', '', array());
    }

    public function testUpdateSkillOnlyByOwner(): void
    {
        $this->catalog->createSkill('u1', 'demo.author', '编目', '', '旧', array());
        $updated = $this->catalog->updateSkill('u1', 'demo.author', '编目2', '新摘要', '新说明', array('demo.list'));
        $this->assertSame('编目2', $updated['name']);
        $this->assertSame(array('demo.list'), $updated['tools']);
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->updateSkill('u2', 'demo.author', '抢改', '', '', array());
    }

    public function testToolListsReferencingSkills(): void
    {
        $this->catalog->saveTool('demo.list', 'list', '列出', 0, '');
        $this->catalog->createSkill('u1', 'demo.author', '编目', '', '', array('demo.list'));
        $tools = $this->catalog->tools();
        $this->assertSame('demo.list', $tools[0]['tool_id']);
        $this->assertSame(0, $tools[0]['has_php']);
        $this->assertSame(array('demo.author'), $tools[0]['skills']);
    }

    public function testBindEntryRequiresSkill(): void
    {
        $this->catalog->createSkill('u1', 'demo.author', '编目', '', '说明', array());
        $row = $this->catalog->bindEntry('platform.authorChat', 'demo.author', 1, 1, 'u1');
        $this->assertSame('编目', $row['skill_name']);
        $again = $this->catalog->bindEntry('platform.authorChat', 'demo.author', 1, 0, 'u1');
        $this->assertSame(0, $again['is_enabled']);
        $this->assertCount(1, $this->catalog->entries());
    }

    public function testRuleRoundTripAndChatPrefix(): void
    {
        $this->catalog->saveRule('u1', '不要编造', '先列表');
        $this->catalog->createSkill('u1', 'demo.author', '编目', '', '按登记表回答', array());
        $prefix = $this->catalog->chatPrefix('u1', 'demo.author');
        $this->assertStringContainsString('不要编造', $prefix);
        $this->assertStringContainsString('按登记表回答', $prefix);
        $this->catalog->clearRule('u1');
        $this->assertSame('', $this->catalog->ruleOf('u1')['dont']);
    }

    public function testCopyWorkflowGoesOffline(): void
    {
        $created = $this->catalog->createWorkflow('u1', '刊登总流程', '刊登', '说明', 'online', 1);
        $copy = $this->catalog->copyWorkflow('u2', $created['id']);
        $this->assertSame('刊登总流程-副本', $copy['name']);
        $this->assertSame('offline', $copy['status']);
        $this->assertSame(1, $copy['is_template']);
        $listed = $this->catalog->workflows('副本', '', '');
        $this->assertCount(1, $listed);
    }
}