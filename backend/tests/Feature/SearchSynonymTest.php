<?php

use App\Models\Product;
use App\Models\SearchSynonym;
use App\Models\SysOperationLog;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * 同义词 + 联想限流（V1.2 站内搜索 S1-10 补做）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.4（降级链第 3 步）/ §4.8
 *
 * 三组契约：
 * 1. **同义词是降级链第 3 步** —— 原查询 AND→OR 都零结果后才展开重查；
 *    展开命中 relaxed=true。原查询自己能查到时同义词绝不参与（不稀释精确率）。
 * 2. **入库即归一化** —— from/to 过 normalize + 小写，匹配是子串级；
 *    to_words 里的 from 自身被剔除（等于重跑原查询，白费）。
 * 3. **suggest 限流** —— 同 IP 每分钟 N 次（SEARCH_SUGGEST_RATE_LIMIT），
 *    联想是按键即打的高频接口，也是刷词频的入口。
 *
 * 测试跑 SQLite（LIKE 引擎），PG 引擎的展开逻辑共享同一段 Service 代码，
 * 且只经由 ProductSearchEngine 接口 —— 引擎无关。
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

    $this->category = \App\Models\Category::create([
        'parent_id' => 0, 'name' => '数码配件', 'sort' => 0, 'status' => 1,
    ]);

    // ⚠️ 刻意不放「手机」二字：否则「手机壳」AND 零结果后 OR 放宽就命中了，
    // 同义词（第 3 步）永远不会被走到 —— 那是设计的正确行为，但测不到目标分支
    $this->makeProduct = fn (string $title): Product => Product::create([
        'category_id' => $this->category->id,
        'title' => $title,
        'price' => '29.90',
        'status' => 1,
    ]);

    ($this->makeProduct)('iPhone保护套硅胶防摔透明');
    ($this->makeProduct)('airpods耳机套');
});

// ---------------------------------------------------------------- 检索展开

describe('降级链第 3 步：同义词展开', function () {
    it('原查询零结果 + 配了规则 → 展开命中，relaxed=true', function () {
        config(['services.search.expose_debug' => '1']);
        SearchSynonym::create(['from_word' => '手机壳', 'to_words' => ['保护套'], 'status' => 1]);

        $res = $this->getJson('/api/search?keyword=手机壳');

        $res->assertOk();
        expect($res->json('data.list'))->toHaveCount(1)
            ->and($res->json('data.list.0.title'))->toContain('保护套')
            ->and($res->json('data.meta.relaxed'))->toBeTrue();

        // 展开命中 = 有结果，原词照常进词频表（result_count 记最终命中数）
        $kw = \App\Models\SearchKeyword::where('keyword', '手机壳')->first();
        expect($kw)->not->toBeNull()
            ->and($kw->result_count)->toBe(1);
    });

    it('原查询自己能命中 → 同义词不参与', function () {
        // 原词直接命中（「保护套」AND 两 bigram 都在标题里），relaxed 必须是 false
        config(['services.search.expose_debug' => '1']);
        SearchSynonym::create(['from_word' => '保护贴', 'to_words' => ['钢化膜'], 'status' => 1]);

        $res = $this->getJson('/api/search?keyword=保护套');

        expect($res->json('data.list'))->not->toBeEmpty()
            ->and($res->json('data.meta.relaxed'))->toBeFalse();
    });

    it('没配规则 → 走推荐位兜底，不 500', function () {
        $res = $this->getJson('/api/search?keyword=手机壳');

        expect($res->json('data.list'))->toBe([])
            ->and($res->json('data.meta.recommendations'))->not->toBeEmpty();
    });

    it('synonyms_enabled=0 → 不展开，直接推荐位', function () {
        config(['services.search.expose_debug' => '1']);
        \App\Models\SystemConfig::create(['config_key' => 'search.synonyms_enabled', 'config_value' => '0']);
        SearchSynonym::create(['from_word' => '手机壳', 'to_words' => ['保护套'], 'status' => 1]);

        $res = $this->getJson('/api/search?keyword=手机壳');

        expect($res->json('data.list'))->toBe([]);
    });

    it('新增规则即时生效（模型事件失效词表缓存，无需 bumpIndexVersion）', function () {
        // 先搜一次：原词零结果，走推荐位（此时把「展开查询」的空缓存也焐不上——还没规则）
        $this->getJson('/api/search?keyword=手机壳')->assertOk();

        SearchSynonym::create(['from_word' => '手机壳', 'to_words' => ['保护套'], 'status' => 1]);

        // ⚠️ activeMap 缓存 300s，靠 saved 事件 forget —— 这里若实现成手动 flush
        // 漏了写路径，本用例就会挂（这正是要钉住的回归点）
        $res = $this->getJson('/api/search?keyword=手机壳');

        expect($res->json('data.list'))->toHaveCount(1);
    });
});

// ---------------------------------------------------------------- 后台 CRUD

describe('同义词后台 CRUD（GET/POST/PUT/DELETE /admin/search/synonyms）', function () {
    it('创建：归一化 + 小写 + 去重 + 剔除 from 自身', function () {
        $res = $this->postJson('/api/admin/search/synonyms', [
            'from_word' => 'ＩＰｈｏｎｅ　Ｃase',  // 全角英文 + 全角空格
            'to_words' => ['苹果手机', '苹果手机', '苹果手机', 'IPHONE CASE', '', '  '],
            'status' => true,
        ], $this->adminAuth);

        $res->assertStatus(201);
        // to_words 去重后剩 1 个；「iphone case」与 from 相同被剔除
        expect($res->json('data.from_word'))->toBe('iphone case')
            ->and($res->json('data.to_words'))->toBe(['苹果手机']);
    });

    it('to_words 全部被剔除（与 from 相同）→ 422', function () {
        // 归一化后与 from 相同 → 被剔除 → 候选为空 → 422
        // （注意 normalize 不删词内空格：「手机 壳」≠「手机壳」，别用错前提）
        $this->postJson('/api/admin/search/synonyms', [
            'from_word' => '手机壳',
            'to_words' => ['手机壳'],
        ], $this->adminAuth)->assertStatus(422);
    });

    it('from_word 重复 → 422（unique）', function () {
        SearchSynonym::create(['from_word' => '手机壳', 'to_words' => ['保护套']]);

        $this->postJson('/api/admin/search/synonyms', [
            'from_word' => '手机壳',
            'to_words' => ['手机套'],
        ], $this->adminAuth)->assertStatus(422);
    });

    it('更新：before/after 落操作日志', function () {
        $row = SearchSynonym::create(['from_word' => '手机壳', 'to_words' => ['保护套']]);

        $res = $this->putJson("/api/admin/search/synonyms/{$row->id}", [
            'from_word' => '手机壳',
            'to_words' => ['保护套', '手机套'],
            'status' => false,
        ], $this->adminAuth);

        $res->assertOk()
            ->assertJsonPath('data.status', 0)
            ->assertJsonPath('data.to_words.1', '手机套');

        $log = SysOperationLog::query()->where('action', 'synonym.update')->latest('id')->first();
        $content = json_decode((string) $log->content, true);
        expect($log)->not->toBeNull()
            ->and($content['before']['to_words'])->toBe(['保护套'])
            ->and($content['after']['to_words'])->toBe(['保护套', '手机套']);
    });

    it('删除：行没了 + 词表缓存即时失效', function () {
        $row = SearchSynonym::create(['from_word' => '手机壳', 'to_words' => ['保护套']]);
        // 焐热缓存
        expect(SearchSynonym::activeMap())->toHaveKey('手机壳');

        $this->deleteJson("/api/admin/search/synonyms/{$row->id}", [], $this->adminAuth)->assertOk();

        expect(SearchSynonym::count())->toBe(0)
            ->and(SearchSynonym::activeMap())->toBe([]);
    });

    it('列表：keyword 同时筛 from 与 to，page_size 收敛', function () {
        SearchSynonym::create(['from_word' => '手机壳', 'to_words' => ['保护套']]);
        SearchSynonym::create(['from_word' => '保护贴', 'to_words' => ['钢化膜']]);

        // to 词也能筛到：运营找「这条词配过没有」时不记得记的是哪侧
        $byTo = $this->getJson('/api/admin/search/synonyms?keyword=钢化膜', $this->adminAuth);
        expect($byTo->json('data.pagination.total'))->toBe(1)
            ->and($byTo->json('data.list.0.from_word'))->toBe('保护贴');

        $clamped = $this->getJson('/api/admin/search/synonyms?page_size=9999', $this->adminAuth);
        expect($clamped->json('data.pagination.page_size'))->toBe(100);
    });

    it('operator（无 search.manage）→ 403', function () {
        SearchSynonym::create(['from_word' => '手机壳', 'to_words' => ['保护套']]);

        $this->getJson('/api/admin/search/synonyms', $this->operatorAuth)->assertForbidden();
        $this->postJson('/api/admin/search/synonyms', [
            'from_word' => 'a', 'to_words' => ['b'],
        ], $this->operatorAuth)->assertForbidden();
    });
});

// ---------------------------------------------------------------- suggest 限流

describe('GET /search/suggest 限流（search-suggest）', function () {
    it('超过每分钟上限 → 429，额度由 env 可配', function () {
        config(['services.search.suggest_rate_limit' => 2]);

        $this->getJson('/api/search/suggest?keyword=保')->assertOk();
        $this->getJson('/api/search/suggest?keyword=保')->assertOk();

        $res = $this->getJson('/api/search/suggest?keyword=保');
        $res->assertStatus(429);
        expect($res->json('message'))->not->toBe('');
    });

    it('不同 IP 互不影响', function () {
        config(['services.search.suggest_rate_limit' => 1]);

        $this->getJson('/api/search/suggest?keyword=保')->assertOk();

        // 换 IP（模拟另一客户端）：Laravel 测试基座从 server 变量取 REMOTE_ADDR
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/search/suggest?keyword=保')->assertOk();
    });
});
