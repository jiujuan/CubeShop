<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchKeyword;
use App\Models\SysOperationLog;
use App\Models\SystemConfig;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 站内搜索后台配置接口（站内搜索 S1-08）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §6 后台部分
 *
 * 这一组钉的是**配置层的行为契约**，不是引擎行为（引擎由 S1-04/S1-05 覆盖）。
 * 三条最容易写错、也最值得钉住：
 * 1. **任何配置变更都要递增 `index_version`** —— 命中集缓存 key 含版本号，
 *    忘了递增的表现是「后台保存成功、前台毫无变化」，极难排查；
 * 2. **`engine` 缺省 / null = 不改动，`auto` 才是清空** —— 空串在 HTTP 层会被全局中间件
 *    `ConvertEmptyStringsToNull` 吃掉，所以「清空」只能用显式取值表达；
 * 3. **开关只认白名单** —— 越权键静默忽略，不能靠前端约束（前端可绕过）。
 *
 * 权限口径同媒体库：`admin`（super_admin）持有 `search.manage`；`operator` **不持有**
 * （切引擎会改变全站检索行为，风险由超管承担）。
 */
beforeEach(function () {
    seedRoles();
    Cache::flush();

    $login = function (string $username, string $password): array {
        $cap = app(CaptchaService::class)->generate();

        return ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $cap['captcha_id'],
            'captcha_code' => $cap['debug_code'],
        ])->json('data.token')];
    };

    $this->adminAuth = $login('admin', 'Admin@123');
    $this->operatorAuth = $login('operator', 'Operator@123');

    $this->category = Category::create([
        'parent_id' => 0, 'name' => '家具', 'sort' => 0, 'status' => 1,
    ]);

    $this->makeProduct = fn (string $title): Product => Product::create([
        'category_id' => $this->category->id,
        'title' => $title,
        'price' => '100.00',
        'status' => 1,
    ]);
});

test('TC-SEARCH-S1-08-001 后台搜索配置仅 search.manage 持有者可用', function () {
    // 未登录：401（不是 403，路由在 auth:sanctum 之后）
    $this->getJson('/api/admin/search/config')->assertStatus(401);

    // operator 不持 search.manage：403
    $this->withHeaders($this->operatorAuth)
        ->getJson('/api/admin/search/config')->assertStatus(403);

    $this->withHeaders($this->operatorAuth)
        ->postJson('/api/admin/search/reindex')->assertStatus(403);

    expect(SystemConfig::where('config_key', 'search.engine')->exists())->toBeFalse();
});

test('TC-SEARCH-S1-08-002 配置详情：引擎取值 + 可用清单 + 开关 + 索引版本', function () {
    $data = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/search/config')->assertOk()->json('data');

    // 未配置 → 空串（自动：PG 可用则用，否则降级）
    expect($data['engine'])->toBe('')
        ->and($data['index_version'])->toBe(1)
        ->and(collect($data['engines'])->pluck('key')->all())->toBe(['', 'postgres', 'like']);

    // switches 的键本身含点号，不能走 json('data.switches.search.cache_ttl') 的点号路径
    expect($data['switches'])->toHaveKeys([
        'search.index_taxonomy_names', 'search.cache_ttl', 'search.expose_debug',
    ])->and($data['switches']['search.index_taxonomy_names'])->toBe('1')
        ->and($data['switches']['search.cache_ttl'])->toBe('60')
        ->and($data['switches']['search.expose_debug'])->toBe('0');

    // current 标记：空串那一档是「自动」
    expect(collect($data['engines'])->firstWhere('current', true)['key'])->toBe('');
});

test('TC-SEARCH-S1-08-003 切引擎写库 + 递增 index_version，再读回即生效', function () {
    $resp = $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/search/config', ['engine' => 'like'])
        ->assertOk();

    expect($resp->json('data.engine'))->toBe('like')
        // 引擎配置里空串 = 自动，'like' 才是显式强制
        ->and($resp->json('data.index_version'))->toBe(2);

    expect(SystemConfig::where('config_key', 'search.engine')->value('config_value'))->toBe('like')
        ->and((int) SystemConfig::where('config_key', 'search.index_version')->value('config_value'))->toBe(2);

    $data = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/search/config')->json('data');

    expect($data['engine'])->toBe('like')
        ->and(collect($data['engines'])->firstWhere('current', true)['key'])->toBe('like')
        // off 是 like 的别名：配置 off 时前端也应该在 like 那一档亮起
        ->and($data['index_version'])->toBe(2);
});

test('TC-SEARCH-S1-08-004 engine 缺省/null = 不改动，auto = 清空库值跟随 env', function () {
    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/search/config', ['engine' => 'postgres'])->assertOk();

    // null：不改动（等价于不带这个字段）
    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/search/config', ['engine' => null])
        ->assertOk()->assertJsonPath('data.engine', 'postgres');

    expect(SystemConfig::where('config_key', 'search.engine')->value('config_value'))->toBe('postgres');

    // auto：删掉库值 → 回落 .env / 自动降级
    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/search/config', ['engine' => 'auto'])
        ->assertOk()->assertJsonPath('data.engine', '');

    expect(SystemConfig::where('config_key', 'search.engine')->exists())->toBeFalse();
});

test('TC-SEARCH-S1-08-005 非法引擎取值一律 422（含空串与未实现的阶段二引擎）', function () {
    $auth = $this->adminAuth;

    foreach (['elasticsearch', 'meilisearch', 'postgresql', 'LIKE '] as $bad) {
        $this->withHeaders($auth)
            ->putJson('/api/admin/search/config', ['engine' => $bad])
            ->assertStatus(422);
    }

    // 空串被全局中间件转成 null → 落到 nullable，视为「不改动」，不是 422
    $this->withHeaders($auth)
        ->putJson('/api/admin/search/config', ['engine' => ''])
        ->assertOk()->assertJsonPath('data.engine', '');

    expect(SystemConfig::where('config_key', 'search.engine')->exists())->toBeFalse();
});

test('TC-SEARCH-S1-08-006 开关白名单：名单外键静默忽略，名单内键写库且递增版本', function () {
    $data = $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/search/config', [
            'switches' => [
                'search.cache_ttl' => '120',
                'search.expose_debug' => true,
                // 越权键：既不能写库，也不能出现在回显里
                'search.engine' => 'like',
                'payment.sandbox' => '1',
            ],
        ])
        ->assertOk()->json('data');

    expect($data['switches']['search.cache_ttl'])->toBe('120')
        // 布尔值归一成 '1' / '0'
        ->and($data['switches']['search.expose_debug'])->toBe('1')
        // 未提交的开关回落默认值，而不是消失
        ->and($data['switches']['search.index_taxonomy_names'])->toBe('1')
        ->and($data['index_version'])->toBe(2);

    expect(SystemConfig::where('config_key', 'search.engine')->exists())->toBeFalse()
        ->and(SystemConfig::where('config_key', 'payment.sandbox')->exists())->toBeFalse()
        ->and(SystemConfig::where('config_key', 'search.cache_ttl')->value('config_value'))->toBe('120');
});

test('TC-SEARCH-S1-08-007 配置变更写操作日志（含前后值，可回溯是谁把引擎切了）', function () {
    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/search/config', ['engine' => 'like', 'switches' => ['search.cache_ttl' => '30']])
        ->assertOk();

    $log = SysOperationLog::where('module', 'search')->where('action', 'config.update')->firstOrFail();
    $content = json_decode((string) $log->content, true);

    expect($log->actor_type)->toBe(SysOperationLog::ACTOR_ADMIN)
        ->and($content['before']['engine'])->toBe('')
        ->and($content['after']['engine'])->toBe('like')
        ->and($content['after']['switches']['search.cache_ttl'])->toBe('30')
        ->and($content['index_version'])->toBe(2);
});

test('TC-SEARCH-S1-08-008 热搜词列表：热度倒序 + 关键词过滤 + 后台暴露精确 total', function () {
    SearchKeyword::create(['keyword' => '沙发', 'hit_count' => 9, 'result_count' => 3]);
    SearchKeyword::create(['keyword' => '沙发套', 'hit_count' => 5, 'result_count' => 0]);
    SearchKeyword::create(['keyword' => '保温杯', 'hit_count' => 2, 'result_count' => 8]);

    $data = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/search/keywords')->assertOk()->json('data');

    // 后台接口不受 SEC-04 管控，total 必须是精确值
    expect($data['pagination']['total'])->toBe(3)
        ->and($data['pagination']['total_pages'])->toBe(1)
        ->and(collect($data['list'])->pluck('keyword')->all())->toBe(['沙发', '沙发套', '保温杯']);

    // result_count = 0 是有热度的信号：有人搜、搜不到 —— 运营据此配同义词或补商品
    expect($data['list'][1]['hit_count'])->toBe(5)
        ->and($data['list'][1]['result_count'])->toBe(0);

    $filtered = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/search/keywords?keyword='.urlencode('沙发'))->json('data');

    expect($filtered['pagination']['total'])->toBe(2)
        ->and(collect($filtered['list'])->pluck('keyword')->all())->toBe(['沙发', '沙发套']);

    // page_size 夹在 1~100：传 100000 会被收敛，不能让它原样落到 LIMIT
    $clamped = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/search/keywords?page_size=100000')->json('data.pagination');

    expect($clamped['page_size'])->toBe(100);
});

test('TC-SEARCH-S1-08-009 手动重建索引：回填陈旧索引列 + 递增版本 + 落操作日志', function () {
    $stale = ($this->makeProduct)('北欧实木沙发');
    ($this->makeProduct)('不锈钢保温杯');

    // 模拟「直接改库 / 批量导入」造成的陈旧：绕过模型 save，索引列被清空
    DB::table('products')->update(['search_title' => null, 'search_body' => null]);

    $data = $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/search/reindex')->assertOk()->json('data');

    expect($data['updated'])->toBe(2)
        ->and($data['index_version'])->toBe(2)
        ->and($data['elapsed_ms'])->toBeInt();

    // 重建后能搜到（前台走 LIKE 引擎也算命中，说明索引列确实回填了）
    $this->getJson('/api/search?keyword='.urlencode('沙发'))
        ->assertOk()->assertJsonPath('data.list.0.id', $stale->public_id);

    $log = SysOperationLog::where('module', 'search')->where('action', 'reindex')->firstOrFail();

    expect(json_decode((string) $log->content, true)['updated'])->toBe(2);
});
