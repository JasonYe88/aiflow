# AIFlow M2（积分 CreditLedger + 权限 AccessControl）实施计划

> **执行方式:** 逐 Task 实施，每个 Task 先写测试（红）再实现（绿）；提交由用户手动执行，Agent 只改代码不 commit。

**Goal:** 完成 M2：Token 钱包两阶段记账（预检额度 → 调用 → 事后清算）+ 扁平 RBAC 权限，并接入 FMS demo——对话自动计费入账、余额不足拦截、未授权角色拒绝访问。

**Architecture:** aiflow 侧新增两块——`Credit`（`CreditService` 钱包/计价/账本 + `Billing` 两阶段编排）与 `Access`（`AccessService` RBAC 判定 + `FixedUserProvider` 默认用户→角色映射）。**零新增表**：M0 的 6 张表（ai_role / ai_resource / ai_role_resource / ai_wallet / ai_price_rule / ai_ledger_entry）直接消费。FMS 侧：CLI 加 `wallet` / `access` 命令，`chat`/`chatStream` 接计费与权限，demo 页展示余额与计费。

**Tech Stack:** PHP 7.4+（语法红线同 M0/M1：禁 enum/readonly/named args/match/union types/constructor promotion）、PDO（MySQL 5.7 InnoDB / SQLite 双兼容）。

**设计依据:** `docs/plans/2026-10-21-aiflow-llmops-framework-design.md` §2（权限）、§3（积分）、§10 里程碑 M2 验收标准（对话自动计费、按角色限流可用）。

**已确认决策:**

1. **并发安全用原子 UPDATE，不用 SELECT...FOR UPDATE**：`UPDATE ai_wallet SET frozen = frozen + ? WHERE id = ? AND balance - frozen >= ?` 依赖影响行数判定，MySQL（行锁）与 SQLite（库级锁）行为一致且可移植；`FOR UPDATE` 是 MySQL 方言
2. **settle 幂等键 = ref_id + kind**：同一调用链重复清算直接返回已有结果，防网络重试导致重复扣账；账本表只追加不修改（amount 正=扣费/充值，负=冲正）
3. **实付可以超过预留**：预检防的是"无限制爆量"，真实用量必须如实扣——settle 允许余额扣到负数（欠费态，下次预检必失败），不做"扣到预留额为止"的假账
4. **未配置计价规则的模型不扣费**：`calcAmount` 找不到规则返回 0，tokens 仍入流水（用量可观测，计费可后补规则）
5. **kind 语义扩展**：ledger `kind` 增加 `grant`（充值，正数入账）——列是 VARCHAR 无需迁移，只扩语义
6. **RBAC 判定**：用户角色来自 `UserProviderInterface::rolesOf()`（M0 契约）；资源标识 `type:identifier`（如 `app:ai_chat_demo`）；支持通配资源 `app:*`；**deny 优先于 allow**；未挂任何角色 = 全拒绝
7. **默认 UserProvider**：`FixedUserProvider`，从内核配置 `access.roles_map`（userId→角色）与 `access.default_roles`（兜底角色，默认 `['user']`）构造；FMS M2 先用默认实现，M3+ 再接真实登录态
8. **Billing 独立于 ChatService**：计费编排做成 `Billing::run(refId, wallet, model, 估算, callable)`，包任意模型调用（对话/Agent/M4 工作流 LLM 节点复用），不侵入 ChatService
9. **异常树**：新增 `AIFlowException` 根异常；`InsufficientCreditException`、`PermissionDeniedException` 继承之（M1 旧异常后续统一收编，不在本期）

**环境约定:**（同 M1）
- aiflow 仓库 `D:\phpstudy_pro\WWW\aiflow`；FMS 仓库 `d:\phpstudy_pro\WWW\fms`
- 测试：`php vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php tests`；MySQL 契约轮需设 `AIFLOW_TEST_MYSQL_DSN` 等（`aiflow_test` 库已建）
- 本机 phpstudy MySQL 默认 MyISAM——涉及余额的 UPDATE 原子性依赖事务/锁，**aiflow_fms 库已验证全 InnoDB**
- FMS CLI 用 `D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe`（aiflow src 已过 7.3 语法检查）

---

## Task 1: 异常树 + 计价规则（PriceBook）

**Files:**
- Create: `src/Exception/AIFlowException.php`
- Create: `src/Exception/InsufficientCreditException.php`
- Create: `src/Exception/PermissionDeniedException.php`
- Modify: `src/Credit/CreditService.php`（本任务创建，先含计价部分）
- Test: `tests/Credit/CreditServiceTest.php`

**Step 1: 异常类**

```php
<?php
declare(strict_types=1);

namespace AIFlow\Exception;

/** 框架异常根：宿主可只捕获此基类获得全部框架错误 */
interface AIFlowException
{
}
```

> 接口形式（marker interface）而非抽象类：不占用单继承槽，旧异常后续可无损 `implements` 收编。`InsufficientCreditException extends \RuntimeException implements AIFlowException`，构造参数 `($message, $need, $have)` 并保留只读属性供前端展示"还差多少"；`PermissionDeniedException` 同理带 `$userId`、`$resource`。

**Step 2: CreditService 计价部分（TDD）**

```php
final class CreditService
{
    private $storage;

    public function __construct(StorageInterface $storage) { ... }

    /** 计价规则 upsert（model 唯一键）：单价单位=分/百万token */
    public function setPriceRule(string $model, int $inputPerM, int $outputPerM, float $multiplier = 1.0): void
    {
        // 先删后插（无 ON DUPLICATE KEY 的可移植写法，事务包裹）
        // DELETE FROM ai_price_rule WHERE model = ?; INSERT ...
    }

    /** @return array|null {model, input_price_per_m, output_price_per_m, multiplier} */
    public function priceOf(string $model): ?array { ... }

    /** 按规则算金额（分）：无规则返回 0；四舍五入，最低 0 */
    public function calcAmount(string $model, int $inputTokens, int $outputTokens): int
    {
        // round(inTok * inPrice * mult / 1e6 + outTok * outPrice * mult / 1e6)
    }
}
```

**验收用例:** `setPriceRule('qwen-plus', 800, 2000)`（qwen-plus 官方价约 ¥0.8/¥2 每百万 token，分单位 800/2000）→ `calcAmount('qwen-plus', 1_000_000, 1_000_000) === 2800`；重复 setPriceRule 覆盖旧值；无规则模型返回 0；multiplier=0.5 时 1400。

---

## Task 2: 钱包（建钱包 / 查询 / 充值）

**Files:**
- Modify: `src/Credit/CreditService.php`
- Test: `tests/Credit/CreditServiceTest.php`

```php
/** 建或取钱包 id（owner 唯一）；并发首建冲突时捕获异常重查 */
public function ensureWallet(string $ownerType, string $ownerId): string { ... }

/** @return array|null {id, owner_type, owner_id, balance, frozen} */
public function walletInfo(string $ownerType, string $ownerId): ?array { ... }

/** 充值（分）：balance += amount，流水 kind=grant；amount<=0 抛 InvalidArgumentException */
public function grant(string $ownerType, string $ownerId, int $amountCents): array { ... }
```

**验收用例:** ensureWallet 幂等（两次调用同 id）；grant 后 balance 累加、ledger 出现 kind=grant amount=N 的流水；walletInfo 未知 owner 返回 null。

---

## Task 3: 预检 reserve（原子冻结 + 余额不足异常）

**Files:**
- Modify: `src/Credit/CreditService.php`
- Test: `tests/Credit/CreditServiceTest.php`

```php
/**
 * 预检冻结：frozen += amount，余额不足抛 InsufficientCreditException(need, have)
 * 幂等：同 refId 已有 reserve 流水则直接成功返回（网络重试安全）
 */
public function reserve(string $walletId, int $amountCents, string $refId): void
{
    if ($amountCents <= 0) { return; }            // 0 额度无需冻结（未计价模型）
    if ($this->hasEntry($refId, 'reserve')) { return; }  // 幂等

    $affected = $this->storage->execute(
        'UPDATE ai_wallet SET frozen = frozen + ? WHERE id = ? AND balance - frozen >= ?',
        [$amountCents, $walletId, $amountCents]
    );
    if ($affected === 0) {
        $w = $this->storage->query('SELECT balance, frozen FROM ai_wallet WHERE id = ?', [$walletId]);
        if (empty($w)) { throw new InvalidArgumentException("钱包不存在: {$walletId}"); }
        throw new InsufficientCreditException('余额不足', $amountCents, (int)($w[0]['balance'] - $w[0]['frozen']));
    }
    // 流水 kind=reserve amount=预留额（仅记录，不动 balance）
    $this->appendEntry($walletId, '', 'reserve', $amountCents, $refId);
}
```

**验收用例:** balance=100 冻结 60 成功、frozen=60；再冻 50 → InsufficientCredit（have=40 need=50）；同 refId 重试不重复冻结；充值后可再冻。

---

## Task 4: 清算 settle（幂等真实扣费）+ 解冻 release

**Files:**
- Modify: `src/Credit/CreditService.php`
- Test: `tests/Credit/CreditServiceTest.php`

```php
/**
 * 清算：按计价规则算实付 → balance -= 实付、frozen -= 预留 → 流水 kind=settle
 * 幂等：同 refId 已 settle 直接返回原结果（防重复扣账）
 * 实付可大于预留（真实用量如实扣，允许扣成负数欠费态）
 * 返回 {amount: 实付分, balance: 清算后余额}
 */
public function settle(string $walletId, string $refId, string $model, int $inTok, int $outTok, int $reservedCents): array
{
    if ($row = $this->findEntry($refId, 'settle')) { return ['amount' => (int)$row['amount'], 'balance' => null]; }

    $amount = $this->calcAmount($model, $inTok, $outTok);
    // 先解冻预留（frozen 不够则防御性清零），再扣实付
    $this->storage->execute(
        'UPDATE ai_wallet SET frozen = MAX(frozen - ?, 0) WHERE id = ?', [$reservedCents, $walletId]
    );
    $this->storage->execute(
        'UPDATE ai_wallet SET balance = balance - ? WHERE id = ?', [$amount, $walletId]
    );
    $this->appendEntry($walletId, $model, 'settle', $amount, $refId, $inTok, $outTok);
    return ['amount' => $amount, 'balance' => $this->balanceOf($walletId)];
}
```

> `MAX(...)` 是 MySQL 函数、SQLite 3.32+ 也支持 `max()` 双参标量函数——为绝对可移植，实现里改用 PHP 侧读 frozen 后算 `max(0, frozen - reserved)` 再 UPDATE，并在事务里做（写进实现，此处为可读性用示意）。

```php
/** 失败解冻：frozen -= amount，流水 kind=refund amount=0；幂等同 refId */
public function release(string $walletId, string $refId, int $reservedCents): void { ... }

/** 流水查询（最近优先） */
public function entries(string $walletId, int $limit = 50): array { ... }
```

**验收用例:** 冻结 100 → settle 实付 35：balance 减 35、frozen 归零、流水 amount=35 且带 tokens；重复 settle 同 refId 不再扣；release 后 frozen 归零且余额不变；实付 150 > 预留 100 → balance 允许变负。

---

## Task 5: Billing 两阶段编排（包住任意模型调用）

**Files:**
- Create: `src/Credit/Billing.php`
- Test: `tests/Credit/BillingTest.php`

```php
/**
 * 两阶段计费编排：估算 → reserve → 执行 callable → settle（或异常时 release）
 * callable 返回 ModelResult（有 inputTokens/outputTokens 属性）
 */
final class Billing
{
    const DEFAULT_EST_OUTPUT_TOKENS = 1024;

    private $credit;

    public function __construct(CreditService $credit) { ... }

    /** 粗估 token：字节数/4（中英混排工程近似，宿主可传精确值覆盖） */
    public static function estimateTokens(string $text): int { return (int)ceil(strlen($text) / 4); }

    /**
     * @param callable $call function(): ModelResult
     * @return array {result: ModelResult, amount: int, balance: int|null}
     * @throws InsufficientCreditException 预检失败（callable 未执行）
     * @throws \Throwable callable 自身异常（已自动解冻后原样重抛）
     */
    public function run(string $walletId, string $model, string $refId, callable $call, int $estInput = 0, int $estOutput = self::DEFAULT_EST_OUTPUT_TOKENS): array
    {
        $estAmount = $this->credit->calcAmount($model, $estInput, $estOutput);
        $this->credit->reserve($walletId, $estAmount, $refId);

        try {
            $result = call_user_func($call);
        } catch (\Throwable $e) {
            $this->credit->release($walletId, $refId, $estAmount);
            throw $e;
        }

        $settled = $this->credit->settle($walletId, $refId, $model, (int)$result->inputTokens, (int)$result->outputTokens, $estAmount);
        return ['result' => $result, 'amount' => $settled['amount'], 'balance' => $settled['balance']];
    }
}
```

**验收用例:** 假 callable 返回固定 ModelResult——成功路径 balance 按实付扣、返回 amount；callable 抛异常 → frozen 归零余额不变、异常透传；余额不足 → callable **从未被调用**（用标志变量断言）；refId 复用不重复扣。

---

## Task 6: AccessControl RBAC（角色/资源/授权/判定）

**Files:**
- Create: `src/Access/AccessService.php`
- Create: `src/Access/FixedUserProvider.php`
- Test: `tests/Access/AccessServiceTest.php`

```php
final class AccessService
{
    private $storage;
    private $users;   // UserProviderInterface

    public function __construct(StorageInterface $storage, UserProviderInterface $users) { ... }

    /** 角色 upsert（code 唯一）：内置约定 admin/developer/user，可自定义 */
    public function ensureRole(string $code, string $name): string { ... }

    /** 资源 upsert（type+identifier 唯一）：type ∈ app|workflow|tool */
    public function ensureResource(string $type, string $identifier, string $name = ''): string { ... }

    /** 授权 upsert（role+resource 唯一）：effect ∈ allow|deny */
    public function grant(string $roleCode, string $type, string $identifier, string $effect = 'allow'): void { ... }
    public function revoke(string $roleCode, string $type, string $identifier): void { ... }

    /**
     * 判定：角色并集查 (identifier IN (指定, '*')) 的授权，deny 优先，无授权=false
     * @param string $resource "type:identifier"，如 "app:ai_chat_demo"
     */
    public function check($userId, string $resource): bool
    {
        list($type, $identifier) = explode(':', $resource, 2);
        $roles = $this->users->rolesOf($userId);
        if (empty($roles)) { return false; }

        // SELECT rr.effect FROM ai_role_resource rr
        // JOIN ai_resource r ON r.id = rr.resource_id
        // JOIN ai_role ro ON ro.id = rr.role_id
        // WHERE ro.code IN (...) AND r.type = ? AND r.identifier IN (identifier, '*')
        // deny 优先 → any deny return false; any allow return true
    }

    /** check 的抛异常版本：未通过抛 PermissionDeniedException(userId, resource) */
    public function guard($userId, string $resource): void { ... }
}
```

```php
/** 默认用户→角色映射：roles_map 精确匹配优先，default_roles 兜底 */
final class FixedUserProvider implements UserProviderInterface
{
    public function __construct(array $rolesMap, array $defaultRoles = ['user']) { ... }
    public function rolesOf($userId): array { ... }
}
```

**验收用例:** role user → allow `app:ai_chat_demo` → check 通过；加 deny 后同资源拒绝（deny 优先）；通配 `app:*` 对 user 生效则任意 app 资源通过；未挂角色用户全拒绝；`guard` 抛异常带 userId/resource；`admin` 无授权时也拒绝（扁平模型无隐式超管，超管=显式 `*:*` 通配授权）。

---

## Task 7: Kernel 装配（credit()/guard() 访问器 + 配置）

**Files:**
- Modify: `src/AIKernel.php`
- Modify: `src/Support/Config.php`（如需，一般无需动）
- Test: `tests/KernelBootTest.php` 补用例

```php
// AIKernel 新增（惰性单例，与 gateway()/chat() 同款）：
public function credit(): CreditService { ... }

public function guard(): AccessService
{
    // access.user_provider：已实现 UserProviderInterface 的实例 → 直接用；
    // 未配置 → FixedUserProvider(access.roles_map, access.default_roles)
}
```

内核配置新增段（宿主可覆盖）：

```php
'access' => [
    'roles_map' => [],              // userId => ['role', ...] 精确映射
    'default_roles' => ['user'],    // 兜底角色
],
```

**验收用例:** boot 后 `credit()` / `guard()` 类型正确且惰性单例；`guard()` 在自定义 user_provider 与默认配置两种形态下行为正确。

---

## Task 8: MySQL 契约轮 + FMS CLI 接入（wallet / access / 计费对话）

**Files:**
- Test: `tests/Credit/CreditServiceMysqlTest.php`（env 开关，重点：**两并发 UPDATE 原子性**与 InnoDB 事务）
- Modify: `ctrl/demo/jasonYe/aiflowCli.php`
- Modify: `config/base/aiflow.php`

CLI 新命令：

- `--action=wallet --owner=demo_user [--grant=10000]`：查余额/充值（1 元 = 100 分）
- `--action=access [--grant-role=user --res=app:ai_chat_demo] [--revoke=...]`：看授权/授权/回收
- `--action=price --model=qwen-plus --in=800 --out=2000`：设/查计价
- `chat` / `chatStream` 改造：**guard('app:ai_chat_demo') → Billing::run 包住 gateway 调用**，输出计费行（amount/balance）

流程（chat 为例）：

```php
$kernel = aiflow_kernel();
$kernel->guard()->guard('cli_user', 'app:ai_chat_demo');          // 未授权 → PermissionDeniedException

$walletId = $kernel->credit()->ensureWallet('user', 'cli_user');
$refId = Id::gen();                                                // 一次调用一个幂等键
$billed = $kernel->credit() /* Billing */ ;
$out = $billing->run($walletId, 'qwen-plus', $refId, function () use ($kernel, $channel, $messages) {
    return $kernel->gateway()->chat($channel, $messages);
}, Billing::estimateTokens($question));
// 输出：实付 X 分（in=Y out=Z），余额 N 分
```

**验收（本机 php7.3.4 实跑）:** 未授权时 chat 报 PermissionDenied；`access` 授权后可聊；`wallet` 充值 1 元 → 对话后余额按量减少；余额清零后对话被 InsufficientCredit 拦截且**模型未被调用**。

---

## Task 9: FMS demo 页接入（余额展示 + 计费拦截/展示 + 授权提示）

**Files:**
- Modify: `ctrl/demo/jasonYe/aiChatDemo.php`

改造点：

1. `index` 页头部显示当前钱包余额（新 JSON 端点 `wallet`），附"充值 1 元（demo）"按钮
2. `chat` 端点完整链路：`guard → ensureWallet → Billing::run(chatStream)`；SSE 帧扩展：
   - `meta` 帧追加 `wallet_balance=N`（发送前余额）
   - `done` 帧追加 `amount=X`（本次实付分）与 `balance=M`（剩余）
   - 余额不足：**头未发** → JSON 402 三段式；**已流式中途异常** → error 帧
   - 未授权：JSON 403
3. 前端：余额栏随 done 帧刷新；402/403 错误以气泡展示中文提示

**验收（用户浏览器 + CLI 直调双路径）:** 正常对话一次余额减少且 done 帧带计费；把余额耗尽后发送 → 明确"积分不足"提示；回收 user 角色授权后 → "无权访问"提示。

---

## Task 10: M2 端到端验收 + 收尾

1. aiflow 全量测试绿（SQLite 轮 + MySQL 契约轮）
2. 本机真实验收（php7.3.4 + 千问）：充值 → 授权 → 对话（CLI + 网页）→ 余额扣减 → 流水核对（`entries` 输出与 mysql 查询一致）→ 耗尽拦截 → 再充值恢复
3. 更新设计文档 §10 M2 状态、README 能力矩阵（如 aiflow README 已有能力段）
4. 汇总改动文件清单交用户提交（**Agent 不执行 git commit**）

---

## 风险与对策

| 风险 | 对策 |
|------|------|
| SQLite 无行锁，并发 reserve 理论竞态 | SQLite 库级写锁天然串行化；MySQL 侧 InnoDB 行锁已由契约测试覆盖；生产建议 MySQL |
| 估算 token 偏差大导致频繁余额不足 | `estimateTokens` 只是缺省启发式，宿主可传精确值；预估算默认只按输入+固定输出上限（1024） |
| 实付 > 预留扣成负余额 | 设计如此（真实用量如实扣）；负余额在下次预检必然拦截，账不丢 |
| kind 语义扩展（grant）与旧注释不符 | 无需迁移（VARCHAR）；注释在 003 不发版不动，README 记录语义 |
