<?php

use App\Models\CsAnnouncement;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CMS-204：公告软并入内容中心
 *
 * ⚠️ 全局函数名必须唯一（与其他测试文件重名会导致全量跑 fatal），故用 cmsAnn 前缀。
 */
function cmsAnnCategoryId(): int
{
    return (int) CsFaqCategory::query()
        ->where('name', '公告')
        ->where('type', CsFaqCategory::TYPE_CHANNEL)
        ->value('id');
}

/** 新建一条公告文章（住在「公告」栏目下） */
function cmsAnnArticle(array $overrides = []): CsFaqArticle
{
    return CsFaqArticle::create(array_merge([
        'category_id' => cmsAnnCategoryId(),
        'title' => '公告',
        'content_md' => '# 公告正文',
        'status' => CsFaqArticle::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(),
    ], $overrides));
}

/** 取迁移 000098 的实例（require 每次都会重新执行该文件，返回新的匿名类） */
function cmsAnnMigration(): object
{
    return require base_path('database/migrations/2026_09_20_000098_merge_announcement_into_cms.php');
}

test('TC-CMS-T60 迁移播种「公告」栏目：channel 类型、不进导航、根节点路径正确', function () {
    $category = CsFaqCategory::query()->where('name', '公告')->first();

    expect($category)->not->toBeNull()
        ->and($category->type)->toBe(CsFaqCategory::TYPE_CHANNEL)
        // 公告走独立入口，不占帮助中心导航位
        ->and((bool) $category->show_in_nav)->toBeFalse()
        ->and($category->parent_id)->toBe(0)
        ->and($category->level)->toBe(1)
        ->and($category->path)->toBe('/'.$category->id.'/');
});

test('TC-CMS-T61 迁移把 cs_announcement 存量行拷入「公告」栏目，置顶映射为热门', function () {
    // 复位到「尚未并入」的状态：清掉播种栏目，再从原表造两条存量公告
    CsFaqCategory::query()->where('name', '公告')->delete();
    CsAnnouncement::create([
        'title' => '置顶公告', 'content_md' => '正文 A', 'is_top' => true,
        'status' => CsAnnouncement::STATUS_PUBLISHED, 'published_at' => now()->subHour(), 'created_by' => 1,
    ]);
    CsAnnouncement::create([
        'title' => '草稿公告', 'content_md' => '正文 B', 'is_top' => false,
        'status' => CsAnnouncement::STATUS_DRAFT, 'created_by' => 1,
    ]);

    cmsAnnMigration()->up();

    $articles = CsFaqArticle::query()->where('category_id', cmsAnnCategoryId())->orderBy('id')->get();

    expect($articles)->toHaveCount(2)
        ->and($articles[0]->title)->toBe('置顶公告')
        // is_top → is_hot：两边的排序语义一致
        ->and($articles[0]->is_hot)->toBeTrue()
        ->and($articles[1]->is_hot)->toBeFalse()
        // 状态直传
        ->and($articles[1]->status)->toBe(CsAnnouncement::STATUS_DRAFT)
        // 正文 HTML 一并带过来（不重新渲染，逐字保留并入前的展示）
        ->and($articles[0]->content)->not->toBe('');
});

test('TC-CMS-T62 迁移幂等：栏目下已有文章时不再重复灌入；回滚不动原表', function () {
    CsFaqCategory::query()->where('name', '公告')->delete();
    CsAnnouncement::create([
        'title' => '存量公告', 'content_md' => '正文', 'is_top' => false,
        'status' => CsAnnouncement::STATUS_PUBLISHED, 'published_at' => now()->subMinute(), 'created_by' => 1,
    ]);

    $migration = cmsAnnMigration();
    $migration->up();
    // 第二次执行：栏目下已有文章 → 直接返回，不产生副本
    $migration->up();

    $categoryId = cmsAnnCategoryId();
    expect(CsFaqArticle::where('category_id', $categoryId)->count())->toBe(1);

    // 回滚只回收栏目与其下文章
    $migration->down();

    expect(cmsAnnCategoryId())->toBe(0)
        ->and(CsFaqArticle::where('category_id', $categoryId)->count())->toBe(0)
        // ⚠️ AC-204.4：原表一行不能少（它是可回退的存档）
        ->and(CsAnnouncement::query()->count())->toBe(1);
});

test('TC-CMS-T63 公开列表：可见口径与排序与并入前一致（置顶优先、时间倒序）', function () {
    cmsAnnArticle(['title' => '普通公告', 'published_at' => now()->subMinutes(5)]);
    cmsAnnArticle(['title' => '置顶公告', 'is_hot' => true, 'published_at' => now()->subMinutes(30)]);
    cmsAnnArticle(['title' => '草稿不可见', 'status' => CsFaqArticle::STATUS_DRAFT]);
    cmsAnnArticle(['title' => '下架不可见', 'status' => CsFaqArticle::STATUS_OFFLINE]);
    cmsAnnArticle(['title' => '未到时间不可见', 'published_at' => now()->addHour()]);

    $res = $this->getJson('/api/announcements')->assertOk();

    $titles = collect($res->json('data.list'))->pluck('title')->all();

    // 置顶优先（哪怕发布时间更早）
    expect($titles)->toBe(['置顶公告', '普通公告'])
        // 公开资源不暴露精确总量（SEC-04，与并入前一致）
        ->and($res->json('data.pagination.total'))->toBeNull();
});

test('TC-CMS-T64 列表项字段契约不变：id 为字符串、is_top 由 is_hot 映射、summary 按正文现算', function () {
    $article = cmsAnnArticle(['title' => '关于系统维护', 'content_md' => '# 维护通知'."\n\n".'本周日凌晨例行维护', 'is_hot' => true]);

    $item = $this->getJson('/api/announcements')->json('data.list.0');

    expect(array_keys($item))->toEqualCanonicalizing(['id', 'title', 'is_top', 'published_at', 'summary'])
        ->and($item['id'])->toBe((string) $article->id)
        ->and($item['is_top'])->toBeTrue()
        ->and($item['published_at'])->not->toBeNull()
        // 摘要来自正文渲染后的纯文本（不带 markdown 标记）
        ->and($item['summary'])->toContain('维护通知')
        ->and($item['summary'])->not->toContain('#');
});

test('TC-CMS-T65 详情按文章 id 解析；草稿/下架/未到时间返回 404，旧 ULID 链接同样 404', function () {
    $published = cmsAnnArticle(['title' => '已发布公告']);
    $draft = cmsAnnArticle(['title' => '草稿公告', 'status' => CsFaqArticle::STATUS_DRAFT]);
    $future = cmsAnnArticle(['title' => '定时公告', 'published_at' => now()->addHour()]);

    $this->getJson('/api/announcements/'.$published->id)->assertOk()
        ->assertJsonPath('data.announcement.title', '已发布公告')
        ->assertJsonPath('data.announcement.id', (string) $published->id)
        ->assertJsonPath('data.announcement.is_top', false);

    $this->getJson('/api/announcements/'.$draft->id)->assertNotFound();
    $this->getJson('/api/announcements/'.$future->id)->assertNotFound();
    // 并入前存下来的 ULID 链接：id 已改由文章承载，给定 404 而不是 500
    $this->getJson('/api/announcements/01J8Z0000000000000000000AB')->assertNotFound();
});

test('TC-CMS-T66 其他栏目的文章不会被公告接口读到（按栏目隔离）', function () {
    $channel = CsFaqCategory::create(['name' => '售后', 'type' => CsFaqCategory::TYPE_CHANNEL]);
    CsFaqArticle::create([
        'category_id' => $channel->id, 'title' => '帮助中心文章', 'content_md' => '内容',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'published_at' => now()->subMinute(),
    ]);
    $announcement = cmsAnnArticle(['title' => '真公告']);

    $titles = collect($this->getJson('/api/announcements')->json('data.list'))->pluck('title')->all();

    expect($titles)->toBe(['真公告']);

    // 反向：帮助中心文章也不能用公告 id 读出来
    $help = CsFaqArticle::where('category_id', $channel->id)->firstOrFail();
    $this->getJson('/api/announcements/'.$help->id)->assertNotFound();

    // 公告文章按 id 能读到（佐证上面的 404 是「栏目隔离」而非「全都读不到」）
    $this->getJson('/api/announcements/'.$announcement->id)->assertOk();
});

test('TC-CMS-T68 「公告」承载栏目不出现在用户端类目列表里（帮助中心零回归）', function () {
    cmsAnnArticle(['title' => '系统维护通知']);

    // 帮助中心类目树：公告有自己的入口，混进来会让用户在「服务中心 > 帮助中心」看到公告类目
    $helpNames = array_column($this->getJson('/api/cs/faq/categories')->json('data'), 'name');
    expect($helpNames)->not->toContain('公告');

    // CMS 公开类目树（与帮助中心同口径）
    $cmsNames = array_column($this->getJson('/api/cms/categories')->json('data'), 'name');
    expect($cmsNames)->not->toContain('公告');

    // 后台内容管理仍要能看到它，否则没法维护公告
    expect(CsFaqCategory::announcementCarrierId())->not->toBeNull();
});

test('TC-CMS-T69 公告文章不进 sitemap（正式入口是 /announcements/{id}，避免重复内容）', function () {
    $announcement = cmsAnnArticle(['title' => '系统维护通知']);

    $channel = CsFaqCategory::create(['name' => '售后', 'type' => CsFaqCategory::TYPE_CHANNEL]);
    $help = CsFaqArticle::create([
        'category_id' => $channel->id, 'title' => '退货流程', 'content_md' => '内容',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'published_at' => now()->subMinute(),
    ]);

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('/service-center/faq/'.$help->id)
        ->and($xml)->not->toContain('/service-center/faq/'.$announcement->id);
});

test('TC-CMS-T67 存量公告并入后，后台内容管理里可见并可编辑（AC-204.1）', function () {
    seedRoles();

    $cap = app(CaptchaService::class)->generate();
    $token = $this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token');
    $auth = ['Authorization' => 'Bearer '.$token];

    $article = cmsAnnArticle(['title' => '待维护公告']);

    $list = $this->getJson('/api/admin/cs/faq/articles?category_id='.cmsAnnCategoryId(), $auth)
        ->assertOk()
        ->json('data.list');

    expect(array_column($list, 'title'))->toContain('待维护公告');

    // 在内容管理里改成「置顶」（即 is_hot），前台立刻按置顶排在前面
    $this->putJson('/api/admin/cs/faq/articles/'.$article->id, [
        'title' => '维护后的公告', 'content_md' => '新正文', 'is_hot' => true,
    ], $auth)->assertOk();

    $item = $this->getJson('/api/announcements')->json('data.list.0');
    expect($item['title'])->toBe('维护后的公告')
        ->and($item['is_top'])->toBeTrue();
});
