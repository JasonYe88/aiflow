<?php
declare(strict_types=1);

namespace AIFlow\Catalog;

use AIFlow\Contract\StorageInterface;
use AIFlow\Support\Id;

/**
 * 编目登记：技能、工具、入口绑定、个人规则、工作流清单
 *
 * 工作流清单只存名称和状态，不代替 PHP 工作流类的执行。
 * 技能修改只允许创建者；系统技能只读。skillId 已存在时新建直接拒绝。
 */
final class CatalogService
{
    /** @var StorageInterface */
    private $storage;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /** @return array 技能列表，tools 为 tool_id 数组 */
    public function skills(): array
    {
        $rows = $this->storage->query(
            'SELECT skill_id, name, summary, instruction, tools_json, owner_id, is_system, updated_at
             FROM ai_skill ORDER BY updated_at DESC, skill_id ASC'
        );
        $out = array();
        foreach ($rows as $row) {
            $out[] = $this->skillOut($row);
        }
        return $out;
    }

    /**
     * 新建技能（只 INSERT）
     *
     * @param string[] $toolIds
     * @return array
     */
    public function createSkill(string $ownerId, string $skillId, string $name, string $summary, string $instruction, array $toolIds): array
    {
        $this->assertKey($skillId, 'skillId');
        if (trim($name) === '') {
            throw new \InvalidArgumentException('名称不能为空');
        }
        if ($this->findSkill($skillId) !== null) {
            throw new \InvalidArgumentException('skillId 已存在，不会覆盖');
        }
        $now = date('Y-m-d H:i:s');
        $this->storage->execute(
            'INSERT INTO ai_skill (id, skill_id, name, summary, instruction, tools_json, owner_id, is_system, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
            array(Id::gen(), $skillId, $name, $summary, $instruction, $this->jsonTools($toolIds), $ownerId, $now, $now)
        );
        return $this->skillOut($this->findSkill($skillId));
    }

    /**
     * 修改自己创建的本地技能
     *
     * @param string[] $toolIds
     * @return array
     */
    public function updateSkill(string $ownerId, string $skillId, string $name, string $summary, string $instruction, array $toolIds): array
    {
        $exist = $this->findSkill($skillId);
        if ($exist === null) {
            throw new \InvalidArgumentException('技能不存在');
        }
        if ((int)$exist['is_system'] === 1) {
            throw new \InvalidArgumentException('系统技能不能在此修改');
        }
        if ((string)$exist['owner_id'] !== $ownerId) {
            throw new \InvalidArgumentException('只能改自己创建的技能');
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException('名称不能为空');
        }
        $this->storage->execute(
            'UPDATE ai_skill SET name = ?, summary = ?, instruction = ?, tools_json = ?, updated_at = ? WHERE skill_id = ?',
            array($name, $summary, $instruction, $this->jsonTools($toolIds), date('Y-m-d H:i:s'), $skillId)
        );
        return $this->skillOut($this->findSkill($skillId));
    }

    /** @return array 工具列表，skills 为引用它的 skill_id */
    public function tools(): array
    {
        $rows = $this->storage->query(
            'SELECT tool_id, name, description, is_async, php_ref, updated_at FROM ai_tool ORDER BY tool_id ASC'
        );
        $ref = array();
        foreach ($this->skills() as $skill) {
            foreach ($skill['tools'] as $tid) {
                if (!isset($ref[$tid])) {
                    $ref[$tid] = array();
                }
                $ref[$tid][] = $skill['skill_id'];
            }
        }
        $out = array();
        foreach ($rows as $row) {
            $tid = (string)$row['tool_id'];
            $out[] = array(
                'tool_id' => $tid,
                'name' => (string)$row['name'],
                'description' => (string)$row['description'],
                'is_async' => (int)$row['is_async'],
                'php_ref' => (string)$row['php_ref'],
                'has_php' => trim((string)$row['php_ref']) !== '' ? 1 : 0,
                'skills' => isset($ref[$tid]) ? $ref[$tid] : array(),
                'updated_at' => (string)$row['updated_at'],
            );
        }
        return $out;
    }

    /**
     * 登记或覆盖一条工具说明（不生成 PHP 文件）
     *
     * @return array
     */
    public function saveTool(string $toolId, string $name, string $description, int $isAsync, string $phpRef): array
    {
        $this->assertKey($toolId, '工具 id');
        if (trim($name) === '') {
            throw new \InvalidArgumentException('name 不能为空');
        }
        $now = date('Y-m-d H:i:s');
        $exist = $this->storage->query('SELECT id FROM ai_tool WHERE tool_id = ?', array($toolId));
        if (isset($exist[0])) {
            $this->storage->execute(
                'UPDATE ai_tool SET name = ?, description = ?, is_async = ?, php_ref = ?, updated_at = ? WHERE tool_id = ?',
                array($name, $description, $isAsync ? 1 : 0, $phpRef, $now, $toolId)
            );
        } else {
            $this->storage->execute(
                'INSERT INTO ai_tool (id, tool_id, name, description, is_async, php_ref, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array(Id::gen(), $toolId, $name, $description, $isAsync ? 1 : 0, $phpRef, $now, $now)
            );
        }
        $listed = $this->tools();
        foreach ($listed as $row) {
            if ($row['tool_id'] === $toolId) {
                return $row;
            }
        }
        throw new \RuntimeException('工具写入后未能读回');
    }

    /** @return array */
    public function entries(): array
    {
        $rows = $this->storage->query(
            'SELECT e.entry_id, e.skill_id, e.is_production, e.is_enabled, e.updated_by, e.updated_at, s.name AS skill_name
             FROM ai_entry e
             LEFT JOIN ai_skill s ON s.skill_id = e.skill_id
             ORDER BY e.entry_id ASC'
        );
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'entry_id' => (string)$row['entry_id'],
                'skill_id' => (string)$row['skill_id'],
                'skill_name' => $row['skill_name'] === null ? '' : (string)$row['skill_name'],
                'is_production' => (int)$row['is_production'],
                'is_enabled' => (int)$row['is_enabled'],
                'updated_by' => (string)$row['updated_by'],
                'updated_at' => (string)$row['updated_at'],
            );
        }
        return $out;
    }

    /**
     * 绑定或改绑入口。skillId 必须已存在。
     *
     * @return array
     */
    public function bindEntry(string $entryId, string $skillId, int $isProduction, int $isEnabled, string $updatedBy): array
    {
        $this->assertKey($entryId, '入口');
        $this->assertKey($skillId, 'skillId');
        if ($this->findSkill($skillId) === null) {
            throw new \InvalidArgumentException('技能不存在，不能绑定');
        }
        $now = date('Y-m-d H:i:s');
        $exist = $this->storage->query('SELECT id FROM ai_entry WHERE entry_id = ?', array($entryId));
        if (isset($exist[0])) {
            $this->storage->execute(
                'UPDATE ai_entry SET skill_id = ?, is_production = ?, is_enabled = ?, updated_by = ?, updated_at = ? WHERE entry_id = ?',
                array($skillId, $isProduction ? 1 : 0, $isEnabled ? 1 : 0, $updatedBy, $now, $entryId)
            );
        } else {
            $this->storage->execute(
                'INSERT INTO ai_entry (id, entry_id, skill_id, is_production, is_enabled, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                array(Id::gen(), $entryId, $skillId, $isProduction ? 1 : 0, $isEnabled ? 1 : 0, $updatedBy, $now)
            );
        }
        foreach ($this->entries() as $row) {
            if ($row['entry_id'] === $entryId) {
                return $row;
            }
        }
        throw new \RuntimeException('入口写入后未能读回');
    }

    /** @return array{dont:string,prefer:string,updated_at:string} */
    public function ruleOf(string $userId): array
    {
        $rows = $this->storage->query(
            'SELECT dont_text, prefer_text, updated_at FROM ai_user_rule WHERE user_id = ?',
            array($userId)
        );
        if (!isset($rows[0])) {
            return array('dont' => '', 'prefer' => '', 'updated_at' => '');
        }
        return array(
            'dont' => (string)$rows[0]['dont_text'],
            'prefer' => (string)$rows[0]['prefer_text'],
            'updated_at' => (string)$rows[0]['updated_at'],
        );
    }

    /** @return array */
    public function saveRule(string $userId, string $dont, string $prefer): array
    {
        $now = date('Y-m-d H:i:s');
        $exist = $this->storage->query('SELECT user_id FROM ai_user_rule WHERE user_id = ?', array($userId));
        if (isset($exist[0])) {
            $this->storage->execute(
                'UPDATE ai_user_rule SET dont_text = ?, prefer_text = ?, updated_at = ? WHERE user_id = ?',
                array($dont, $prefer, $now, $userId)
            );
        } else {
            $this->storage->execute(
                'INSERT INTO ai_user_rule (user_id, dont_text, prefer_text, updated_at) VALUES (?, ?, ?, ?)',
                array($userId, $dont, $prefer, $now)
            );
        }
        return $this->ruleOf($userId);
    }

    public function clearRule(string $userId): array
    {
        $this->storage->execute('DELETE FROM ai_user_rule WHERE user_id = ?', array($userId));
        return $this->ruleOf($userId);
    }

    /**
     * 拼进对话的系统提示：个人规则 + 所选技能说明书
     * 顶栏切换技能只影响这一场对话，不改入口绑定。
     */
    public function chatPrefix(string $userId, string $skillId): string
    {
        $parts = array();
        $rule = $this->ruleOf($userId);
        if (trim($rule['dont']) !== '' || trim($rule['prefer']) !== '') {
            $parts[] = "个人规则（只对当前用户生效）\n不要做：\n" . $rule['dont'] . "\n偏好：\n" . $rule['prefer'];
        }
        if ($skillId !== '') {
            $skill = $this->findSkill($skillId);
            if ($skill !== null) {
                $parts[] = "当前技能 " . $skill['skill_id'] . "（" . $skill['name'] . "）\n" . $skill['instruction'];
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * @param string $name 名称包含
     * @param string $status online|offline|空
     * @param string $template 1|0|空
     * @return array
     */
    public function workflows(string $name, string $status, string $template): array
    {
        $sql = 'SELECT id, name, tag, description, status, is_template, owner_id, created_at, updated_at FROM ai_workflow_def WHERE 1 = 1';
        $params = array();
        if ($name !== '') {
            $sql .= ' AND name LIKE ?';
            $params[] = '%' . $name . '%';
        }
        if ($status === 'online' || $status === 'offline') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        if ($template === '1' || $template === '0') {
            $sql .= ' AND is_template = ?';
            $params[] = (int)$template;
        }
        $sql .= ' ORDER BY updated_at DESC';
        $rows = $this->storage->query($sql, $params);
        $out = array();
        foreach ($rows as $row) {
            $out[] = $this->workflowOut($row);
        }
        return $out;
    }

    /** @return array */
    public function createWorkflow(string $ownerId, string $name, string $tag, string $description, string $status, int $isTemplate): array
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('工作流名称不能为空');
        }
        if ($status !== 'online' && $status !== 'offline') {
            $status = 'offline';
        }
        $now = date('Y-m-d H:i:s');
        $id = Id::gen();
        $this->storage->execute(
            'INSERT INTO ai_workflow_def (id, name, tag, description, status, is_template, owner_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array($id, $name, $tag, $description, $status, $isTemplate ? 1 : 0, $ownerId, $now, $now)
        );
        return $this->workflowOut($this->findWorkflow($id));
    }

    /** 复制一条登记，新行下线，名称加「-副本」 */
    public function copyWorkflow(string $ownerId, string $id): array
    {
        $row = $this->findWorkflow($id);
        if ($row === null) {
            throw new \InvalidArgumentException('工作流不存在');
        }
        return $this->createWorkflow(
            $ownerId,
            $row['name'] . '-副本',
            (string)$row['tag'],
            (string)$row['description'],
            'offline',
            (int)$row['is_template']
        );
    }

    /** @return array|null */
    private function findSkill(string $skillId): ?array
    {
        $rows = $this->storage->query('SELECT * FROM ai_skill WHERE skill_id = ?', array($skillId));
        return isset($rows[0]) ? $rows[0] : null;
    }

    /** @return array|null */
    private function findWorkflow(string $id): ?array
    {
        $rows = $this->storage->query('SELECT * FROM ai_workflow_def WHERE id = ?', array($id));
        return isset($rows[0]) ? $rows[0] : null;
    }

    /** @param array $row */
    private function skillOut(array $row): array
    {
        $tools = json_decode((string)$row['tools_json'], true);
        return array(
            'skill_id' => (string)$row['skill_id'],
            'name' => (string)$row['name'],
            'summary' => (string)$row['summary'],
            'instruction' => (string)$row['instruction'],
            'tools' => is_array($tools) ? array_values($tools) : array(),
            'owner_id' => (string)$row['owner_id'],
            'is_system' => (int)$row['is_system'],
            'updated_at' => (string)$row['updated_at'],
        );
    }

    /** @param array $row */
    private function workflowOut(array $row): array
    {
        return array(
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'tag' => (string)$row['tag'],
            'description' => (string)$row['description'],
            'status' => (string)$row['status'],
            'is_template' => (int)$row['is_template'],
            'owner_id' => (string)$row['owner_id'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        );
    }

    /** @param string[] $toolIds */
    private function jsonTools(array $toolIds): string
    {
        $clean = array();
        foreach ($toolIds as $id) {
            $id = trim((string)$id);
            if ($id === '') {
                continue;
            }
            $this->assertKey($id, '工具 id');
            $clean[$id] = $id;
        }
        return json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function assertKey(string $key, string $label): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $key)) {
            throw new \InvalidArgumentException($label . ' 只能包含字母、数字、点、下划线和短横线');
        }
    }
}
