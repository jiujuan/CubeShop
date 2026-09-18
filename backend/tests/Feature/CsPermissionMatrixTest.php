<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Models\CsTicketType;
use App\Models\SysUser;
use App\Services\Common\CaptchaService;
use App\Services\Cs\CsTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-116 权限矩阵专项
 *
 * 账号类型：未登录 / 普通买家 / 有 cs.ticket.view 的运营 / 有 cs.ticket.handle 的客服 / 超管。
 * 接口：用户端 6 + 后台 5，共 11 个，逐条断言 401 / 403 / 200 / 404。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $this->type = CsTicketType::create([
        'name' => '咨询建议', 'code' => 'other', 'require_order' => false, 'sort' => 0, 'is_active' => true,
    ]);

    $this->buyer = createTestUser('cs-pm-buyer');
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->buyer->createToken('t')->plainTextToken];

    $ticket = app(CsTicketService::class)->createTicket($this->buyer, [
        'type_id' => $this->type->id, 'title' => '权限用例', 'content' => '内容',
    ]);
    $this->ticketId = $ticket->id;

    $category = CsFaqCategory::create(['name' => '权限分类', 'sort' => 0, 'is_active' => true]);
    $article = CsFaqArticle::create([
        'category_id' => $category->id, 'title' => '权限文章', 'content' => '内容',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'published_at' => now(),
    ]);
    $this->articleId = $article->id;

    $this->userEndpoints = [
        ['label' => 'user.faq.categories', 'method' => 'GET', 'uri' => '/api/cs/faq/categories'],
        ['label' => 'user.faq.articles', 'method' => 'GET', 'uri' => '/api/cs/faq/articles'],
        ['label' => 'user.faq.detail', 'method' => 'GET', 'uri' => "/api/cs/faq/articles/{$this->articleId}"],
        ['label' => 'user.faq.feedback', 'method' => 'POST', 'uri' => "/api/cs/faq/articles/{$this->articleId}/feedback", 'body' => ['helpful' => true]],
        ['label' => 'user.ticket-types', 'method' => 'GET', 'uri' => '/api/cs/ticket-types'],
        ['label' => 'user.tickets.index', 'method' => 'GET', 'uri' => '/api/cs/tickets'],
    ];

    $this->adminEndpoints = [
        ['label' => 'admin.tickets.index', 'method' => 'GET', 'uri' => '/api/admin/cs/tickets'],
        ['label' => 'admin.tickets.show', 'method' => 'GET', 'uri' => "/api/admin/cs/tickets/{$this->ticketId}"],
        ['label' => 'admin.tickets.status', 'method' => 'PUT', 'uri' => "/api/admin/cs/tickets/{$this->ticketId}/status", 'body' => ['status' => 'processing']],
        ['label' => 'admin.tickets.assign', 'method' => 'PUT', 'uri' => "/api/admin/cs/tickets/{$this->ticketId}/assign", 'body' => ['assignee_id' => null]],
        ['label' => 'admin.faq.categories', 'method' => 'GET', 'uri' => '/api/admin/cs/faq/categories'],
    ];
});

/** 登录并返回 Bearer 头（管理员/运营账号走 /api/auth/login） */
function csPmLogin(string $username, string $password): array
{
    $cap = app(CaptchaService::class)->generate();
    $token = test()->postJson('/api/auth/login', [
        'username' => $username, 'password' => $password,
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token');

    return ['Authorization' => 'Bearer '.$token];
}

/** 创建一个仅持有指定权限码的后台账号并登录 */
function csPmAdminWith(array $permissions): array
{
    $user = SysUser::create([
        'username' => 'cspm'.uniqid(), 'password' => 'Cs@12345', 'nickname' => 'CS', 'status' => 1,
    ]);
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return csPmLogin($user->username, 'Cs@12345');
}

function csPmStatus(array $auth, array $endpoint): int
{
    return test()->json($endpoint['method'], $endpoint['uri'], $endpoint['body'] ?? [], $auth)->getStatusCode();
}

/** 逐条断言并汇总差异，便于定位 */
function csPmAssertMatrix(array $auth, array $endpoints, array $expected): void
{
    $actual = [];
    foreach ($endpoints as $endpoint) {
        $actual[$endpoint['label']] = csPmStatus($auth, $endpoint);
    }

    expect($actual)->toBe($expected);
}

test('未登录访问 11 个接口全部 401', function () {
    $all = array_merge($this->userEndpoints, $this->adminEndpoints);
    $expected = array_fill_keys(array_column($all, 'label'), 401);

    csPmAssertMatrix([], $all, $expected);
});

test('普通买家：用户端 6 接口 200，后台 5 接口 403', function () {
    csPmAssertMatrix($this->buyerAuth, $this->userEndpoints, array_fill_keys(array_column($this->userEndpoints, 'label'), 200));
    csPmAssertMatrix($this->buyerAuth, $this->adminEndpoints, array_fill_keys(array_column($this->adminEndpoints, 'label'), 403));
});

test('运营（仅 cs.ticket.view）：后台查看接口 200，处理与 FAQ 接口 403', function () {
    $auth = csPmAdminWith(['cs.ticket.view']);

    csPmAssertMatrix($auth, $this->adminEndpoints, [
        'admin.tickets.index' => 200,
        'admin.tickets.show' => 200,
        'admin.tickets.status' => 403,
        'admin.tickets.assign' => 403,
        'admin.faq.categories' => 403,
    ]);
});

test('客服（cs.ticket.view + cs.ticket.handle）：工单接口 200，FAQ 接口 403', function () {
    $auth = csPmAdminWith(['cs.ticket.view', 'cs.ticket.handle']);

    csPmAssertMatrix($auth, $this->adminEndpoints, [
        'admin.tickets.index' => 200,
        'admin.tickets.show' => 200,
        'admin.tickets.status' => 200,
        'admin.tickets.assign' => 200,
        'admin.faq.categories' => 403,
    ]);
});

test('超管：后台 5 接口全部 200', function () {
    $cap = app(CaptchaService::class)->generate();
    $adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    csPmAssertMatrix($adminAuth, $this->adminEndpoints, array_fill_keys(array_column($this->adminEndpoints, 'label'), 200));
});

test('买家不能查看他人工单详情（404）', function () {
    $other = createTestUser('cs-pm-other');
    $otherTicket = app(CsTicketService::class)->createTicket($other, [
        'type_id' => $this->type->id, 'title' => '他人工单', 'content' => '内容',
    ]);

    $this->getJson("/api/cs/tickets/{$otherTicket->id}", $this->buyerAuth)->assertStatus(404);
});
