<?php

use App\Models\CsAnnouncement;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 后台公告管理接口（P-Announcement，权限 announcement.manage）
 *
 * ⚠️ 本模块自 CMS-204 起已废弃（公告软并入内容中心，见 CmsAnnouncementMergeTest）；
 * 这里只保留对**已发布路由**的回归断言，确保旧调用方拿到确定的响应而不是 404。
 *
 * 用户端 `/api/announcements` 的契约测试已移到 CmsAnnouncementMergeTest
 * —— 该接口现在读的是 CMS「公告」栏目下的文章，不再是 cs_announcement 表。
 */
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

    // ⚠️ CMS-204 起用户端已改读内容中心的「公告」栏目，本模块的写入**不会**出现在前台。
    // 这里断言「不出现」而不是「出现」—— 把废弃路径的实际后果固化下来，避免误以为它还有效。
    $titles = collect($this->getJson('/api/announcements')->json('data.list'))->pluck('title')->all();
    expect($titles)->not->toContain('新公告');
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
