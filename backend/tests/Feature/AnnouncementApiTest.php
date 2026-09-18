<?php

use App\Models\CsAnnouncement;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

// ============ 用户端（公开，无需登录） ============

test('公开列表只返回已发布且到达发布时间的公告', function () {
    CsAnnouncement::create([
        'title' => '可见公告', 'content_md' => '# 标题', 'status' => CsAnnouncement::STATUS_PUBLISHED,
        'published_at' => now()->subMinutes(5), 'is_top' => true, 'created_by' => 1,
    ]);
    CsAnnouncement::create([
        'title' => '草稿不可见', 'content_md' => '草稿', 'status' => CsAnnouncement::STATUS_DRAFT,
        'created_by' => 1,
    ]);
    CsAnnouncement::create([
        'title' => '下架不可见', 'content_md' => '下架', 'status' => CsAnnouncement::STATUS_OFFLINE,
        'published_at' => now()->subMinutes(5), 'created_by' => 1,
    ]);
    CsAnnouncement::create([
        'title' => '未到时间不可见', 'content_md' => '定时', 'status' => CsAnnouncement::STATUS_PUBLISHED,
        'published_at' => now()->addHour(), 'created_by' => 1,
    ]);

    $res = $this->getJson('/api/announcements');
    $res->assertOk();
    $titles = collect($res->json('data.list'))->pluck('title')->all();

    expect($titles)->toContain('可见公告')
        ->not->toContain('草稿不可见')
        ->not->toContain('下架不可见')
        ->not->toContain('未到时间不可见');
    // 公开资源不暴露精确总量（SEC-04）
    expect($res->json('data.pagination.total'))->toBeNull();
});

test('详情按 public_id 解析，不可见公告返回 404', function () {
    $draft = CsAnnouncement::create([
        'title' => '草稿', 'content_md' => '内容', 'status' => CsAnnouncement::STATUS_DRAFT, 'created_by' => 1,
    ]);
    $published = CsAnnouncement::create([
        'title' => '已发布', 'content_md' => '# 正文', 'status' => CsAnnouncement::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(), 'created_by' => 1,
    ]);

    $this->getJson('/api/announcements/'.$draft->public_id)->assertNotFound();
    $this->getJson('/api/announcements/'.$published->public_id)->assertOk()
        ->assertJsonPath('data.announcement.title', '已发布')
        ->assertJsonPath('data.announcement.id', $published->public_id);
});

// ============ 后台管理（announcement.manage） ============

test('未登录访问后台公告列表返回 401', function () {
    $this->getJson('/api/admin/announcements')->assertUnauthorized();
});

test('管理员可创建公告并派生 HTML 正文', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/announcements', [
        'title' => '新公告', 'content_md' => '# 大标题\n\n这是正文', 'is_top' => true,
    ]);
    $res->assertCreated();
    $id = $res->json('data.id');
    // 未发布 → 前台不可见
    expect($res->json('data.status'))->toBe('draft');

    // markdown 源已派生为 sanitize 后的 HTML 存入 content
    $row = CsAnnouncement::find($id);
    expect($row->content)->toContain('<h1')->and($row->content)->toContain('大标题');

    // 发布
    $pub = $this->withHeaders($this->adminAuth)->postJson("/api/admin/announcements/{$id}/publish");
    $pub->assertOk()->assertJsonPath('data.status', 'published');
    expect($pub->json('data.published_at'))->not->toBeNull();

    // 前台可见
    $this->getJson('/api/announcements/'.$row->public_id)->assertOk();
});

test('后台列表按状态筛选', function () {
    CsAnnouncement::create(['title' => 'A', 'content_md' => 'a', 'status' => CsAnnouncement::STATUS_PUBLISHED, 'published_at' => now()->subMinute(), 'created_by' => 1]);
    CsAnnouncement::create(['title' => 'B', 'content_md' => 'b', 'status' => CsAnnouncement::STATUS_DRAFT, 'created_by' => 1]);

    $published = $this->withHeaders($this->adminAuth)->getJson('/api/admin/announcements?status=published');
    expect(collect($published->json('data.list'))->pluck('title')->all())->toContain('A')->not->toContain('B');

    $draft = $this->withHeaders($this->adminAuth)->getJson('/api/admin/announcements?status=draft');
    expect(collect($draft->json('data.list'))->pluck('title')->all())->toContain('B')->not->toContain('A');
});

test('编辑、下架、删除全流程', function () {
    $row = CsAnnouncement::create(['title' => '初稿', 'content_md' => '原', 'status' => CsAnnouncement::STATUS_DRAFT, 'created_by' => 1]);

    $upd = $this->withHeaders($this->adminAuth)->putJson("/api/admin/announcements/{$row->id}", [
        'title' => '改后', 'content_md' => '新**内容**',
    ]);
    $upd->assertOk()->assertJsonPath('data.title', '改后');
    $content = CsAnnouncement::find($row->id)->content;
    expect($content)->toContain('<strong')->and($content)->toContain('内容');

    // 发布后下架
    $this->withHeaders($this->adminAuth)->postJson("/api/admin/announcements/{$row->id}/publish")->assertOk();
    $off = $this->withHeaders($this->adminAuth)->postJson("/api/admin/announcements/{$row->id}/offline");
    $off->assertOk()->assertJsonPath('data.status', 'offline');

    $this->withHeaders($this->adminAuth)->deleteJson("/api/admin/announcements/{$row->id}")->assertOk();
    expect(CsAnnouncement::find($row->id))->toBeNull();
});
