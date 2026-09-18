<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * CS-117 一期端到端冒烟（接口通道，可重复执行）
 *
 * 覆盖 `smoke-CS-117.md` 的 10 步链路：
 *   1-2 帮助中心（分类/搜索/详情/有帮助反馈）
 *   3-4 买家提单（物流类型 + 关联订单 + 2 张凭证）→ 工单号/pending/列表可见
 *   5-8 客服处理（待处理数 → 内部备注不可见不通知 → 回复自动流转 + 站内信 + first_replied_at
 *                 → 置等待回复 → 用户回复回 processing）
 *   9-10 完成（系统消息）→ 用户关闭 → 再次关闭 40009
 *
 * 与 `CsTicketApiTest` / `AdminCsTicketApiTest` 的单点用例互补：本文件只关心**跨端链路**不变量。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    Storage::fake('public');

    $this->logistics = CsTicketType::create([
        'name' => '物流问题', 'code' => 'logistics', 'require_order' => true, 'sort' => 1, 'is_active' => true,
    ]);

    $this->faqCategory = CsFaqCategory::create(['name' => '物流配送', 'sort' => 1, 'is_active' => true]);
    $this->faqArticle = CsFaqArticle::create([
        'category_id' => $this->faqCategory->id,
        'title' => '运费与配送时效说明',
        'summary' => '常见配送问题',
        'content' => '默认 48 小时内发货，偏远地区顺延。',
        'sort' => 1, 'is_hot' => true, 'status' => CsFaqArticle::STATUS_PUBLISHED,
        'published_at' => now(),
    ]);

    // 买家（注册接口拿真实 sanctum token）
    $buyerCap = app(CaptchaService::class)->generate();
    $this->buyerUsername = 'e2e'.uniqid();
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => $this->buyerUsername,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $buyerCap['debug_code'],
        'captcha_id' => $buyerCap['captcha_id'],
    ])->json('data.token')];
    $this->buyer = User::where('username', $this->buyerUsername)->first();

    // 客服（超管 admin，拥有 cs.ticket.view + cs.ticket.handle）
    $adminCap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $adminCap['captcha_id'], 'captcha_code' => $adminCap['debug_code'],
    ])->json('data.token')];

    $category = \App\Models\Category::create(['name' => '测试分类', 'sort' => 1, 'status' => 1]);
    $this->product = Product::create([
        'category_id' => $category->id, 'title' => '测试商品', 'main_image' => '/storage/p/x.png',
        'price' => 100, 'status' => 1,
    ]);
    $this->order = Order::create([
        'order_no' => 'E2E'.time().rand(1000, 9999), 'user_id' => $this->buyer->id, 'status' => 'paid',
        'total_amount' => 100, 'pay_amount' => 100, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0, 'address_snapshot' => '{}',
    ]);
    OrderItem::create([
        'order_id' => $this->order->id, 'product_id' => $this->product->id, 'sku_id' => 1,
        'product_title' => '测试商品', 'price' => 100, 'quantity' => 1, 'total_amount' => 100,
    ]);
});

/** 买家 UploadedFile → 走真实上传接口拿 URL（步骤 3 的 2 张凭证） */
function uploadVoucher(array $auth): string
{
    return test()->withHeaders($auth)
        ->postJson('/api/cs/upload-image', ['image' => UploadedFile::fake()->image('proof.jpg')])
        ->assertOk()
        ->json('data.url');
}

function createE2eTicket(array $auth, int $typeId, int $orderId, array $images): CsTicket
{
    $res = test()->withHeaders($auth)->postJson('/api/cs/tickets', [
        'type_id' => $typeId, 'title' => '包裹一直没收到', 'content' => '下单三天了还没物流信息',
        'order_id' => $orderId, 'images' => $images,
    ]);
    $res->assertCreated();

    return CsTicket::findOrFail(tid($res->json('data.ticket.id')));
}

// ---------- 步骤 1-2：帮助中心 ----------

it('步骤 1-2：帮助中心分类可见、关键词命中文章、详情可读、有帮助反馈生效', function () {
    // 步骤 1：服务中心首页读分类
    $this->withHeaders($this->buyerAuth)->getJson('/api/cs/faq/categories')
        ->assertOk()
        ->assertJsonPath('data.0.name', '物流配送')
        ->assertJsonPath('data.0.published_count', 1);

    // 步骤 2a：关键词搜索命中
    $search = $this->withHeaders($this->buyerAuth)->getJson('/api/cs/faq/articles?keyword=配送')
        ->assertOk();
    expect(collect($search->json('data.list'))->pluck('id'))->toContain($this->faqArticle->id);

    // 步骤 2b：打开详情
    $detail = $this->withHeaders($this->buyerAuth)->getJson('/api/cs/faq/articles/'.$this->faqArticle->id)
        ->assertOk();
    expect($detail->json('data.article.id'))->toBe($this->faqArticle->id)
        ->and($detail->json('data.article.content'))->toContain('48 小时');

    // 步骤 2c：提交「有帮助」
    $this->withHeaders($this->buyerAuth)
        ->postJson('/api/cs/faq/articles/'.$this->faqArticle->id.'/feedback', ['helpful' => true])
        ->assertOk()
        ->assertJsonPath('data.helpful_count', 1);
});

// ---------- 步骤 3-4：提单 ----------

it('步骤 3-4：物流类型关联订单 + 2 张凭证提交成功，工单号/状态 pending/我的列表可见', function () {
    $images = [uploadVoucher($this->buyerAuth), uploadVoucher($this->buyerAuth)];
    expect($images)->toHaveCount(2);

    $res = $this->withHeaders($this->buyerAuth)->postJson('/api/cs/tickets', [
        'type_id' => $this->logistics->id, 'title' => '包裹一直没收到', 'content' => '下单三天了还没物流信息',
        'order_id' => $this->order->id, 'images' => $images,
    ]);

    $res->assertCreated();
    // 步骤 4：工单号前缀 TK、状态 pending、关联订单
    expect($res->json('data.ticket.ticket_no'))->toStartWith('TK')
        ->and($res->json('data.ticket.status'))->toBe(CsTicket::STATUS_PENDING)
        ->and($res->json('data.ticket.order_id'))->toBe(\App\Support\PublicId::encode(\App\Support\PublicId::SCOPE_ORDER, $this->order->id));

    // 详情：首条用户消息携带 2 张凭证
    $detail = $this->withHeaders($this->buyerAuth)->getJson('/api/cs/tickets/'.$res->json('data.ticket.id'))->assertOk();
    $firstMessage = collect($detail->json('data.ticket.messages'))->firstWhere('sender_type', CsTicketMessage::SENDER_USER);
    expect($firstMessage['images'])->toHaveCount(2);

    // 我的工单列表可见
    $list = $this->withHeaders($this->buyerAuth)->getJson('/api/cs/tickets?status=pending')->assertOk();
    expect(collect($list->json('data.list'))->pluck('ticket_no'))->toContain($res->json('data.ticket.ticket_no'));
});

it('步骤 3 守卫：物流类型缺 order_id 返回 422', function () {
    $this->withHeaders($this->buyerAuth)->postJson('/api/cs/tickets', [
        'type_id' => $this->logistics->id, 'title' => '包裹没到', 'content' => '没收到',
    ])->assertStatus(422);
});

// ---------- 步骤 5-8：客服处理 ----------

it('步骤 5-8：待处理数 +1 → 内部备注不可见不通知 → 回复自动流转 + 站内信 + first_replied_at → 等待回复 → 用户回复回 processing', function () {
    $ticket = createE2eTicket($this->buyerAuth, $this->logistics->id, $this->order->id, []);

    // 步骤 5：后台工作台待处理数 +1，详情可打开
    $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets?status=pending')
        ->assertOk()
        ->assertJsonPath('data.meta.pending_count', 1);
    $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id)
        ->assertOk()
        ->assertJsonPath('data.ticket.status', CsTicket::STATUS_PENDING)
        ->assertJsonPath('data.user_summary.id', $this->buyer->id)
        ->assertJsonPath('data.order.order_no', $this->order->order_no);

    // 步骤 6：写内部备注 → 状态不变、用户端不可见、用户未收到通知
    Notification::query()->delete();
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/tickets/'.$ticket->id.'/messages', [
        'content' => '内部核查：仓库漏发', 'is_internal' => true,
    ])->assertOk();

    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_PENDING)
        ->and(Notification::where('user_id', $this->buyer->id)->where('receiver_type', Notification::RECEIVER_CUSTOMER)->count())->toBe(0);

    $buyerView = $this->withHeaders($this->buyerAuth)->getJson('/api/cs/tickets/'.$ticket->id)->assertOk();
    expect(collect($buyerView->json('data.ticket.messages'))->pluck('is_internal')->unique()->all())->toBe([false]);
    expect(collect($buyerView->json('data.ticket.messages'))->pluck('content'))->not->toContain('内部核查：仓库漏发');

    // 步骤 7：客服公开回复 → pending → processing、用户收到站内信、first_replied_at 落库
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/tickets/'.$ticket->id.'/messages', [
        'content' => '您好，已联系仓库补发，预计明日发出',
    ])->assertOk();

    $ticket->refresh();
    expect($ticket->status)->toBe(CsTicket::STATUS_PROCESSING)
        ->and($ticket->first_replied_at)->not->toBeNull()
        ->and(Notification::where('user_id', $this->buyer->id)
            ->where('receiver_type', Notification::RECEIVER_CUSTOMER)->count())->toBeGreaterThanOrEqual(1);

    // 步骤 8：客服置「等待用户回复」→ 用户追加回复 → 回到 processing
    $ticket->refresh();
    $firstRepliedAt = $ticket->first_replied_at;

    $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/tickets/'.$ticket->id.'/status', [
        'status' => CsTicket::STATUS_WAITING_USER,
    ])->assertOk();
    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_WAITING_USER);

    $this->withHeaders($this->buyerAuth)->postJson('/api/cs/tickets/'.$ticket->id.'/messages', [
        'content' => '好的，麻烦尽快，谢谢',
    ])->assertOk();

    $ticket->refresh();
    expect($ticket->status)->toBe(CsTicket::STATUS_PROCESSING)
        // 首响锚点不被后续消息覆盖
        ->and($ticket->first_replied_at->equalTo($firstRepliedAt))->toBeTrue();
});

// ---------- 步骤 9-10：完成与关闭 ----------

it('步骤 9-10：客服完成 → 用户端可见完成与系统消息 → 用户关闭 → 再次关闭 40009', function () {
    $ticket = createE2eTicket($this->buyerAuth, $this->logistics->id, $this->order->id, []);

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/tickets/'.$ticket->id.'/messages', [
        'content' => '已为您处理',
    ])->assertOk(); // pending → processing

    // 步骤 9：标记完成
    $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/tickets/'.$ticket->id.'/status', [
        'status' => CsTicket::STATUS_COMPLETED,
    ])->assertOk();

    $ticket->refresh();
    expect($ticket->status)->toBe(CsTicket::STATUS_COMPLETED)
        ->and($ticket->completed_at)->not->toBeNull();

    // 用户端可见完成状态与系统消息
    $buyerView = $this->withHeaders($this->buyerAuth)->getJson('/api/cs/tickets/'.$ticket->id)->assertOk();
    expect($buyerView->json('data.ticket.status'))->toBe(CsTicket::STATUS_COMPLETED);
    expect(collect($buyerView->json('data.ticket.messages'))->pluck('sender_type'))->toContain(CsTicketMessage::SENDER_SYSTEM);

    // 步骤 10：用户关闭 → closed
    $this->withHeaders($this->buyerAuth)->postJson('/api/cs/tickets/'.$ticket->id.'/close')->assertOk();
    $ticket->refresh();
    expect($ticket->status)->toBe(CsTicket::STATUS_CLOSED)
        ->and($ticket->closed_at)->not->toBeNull()
        ->and($ticket->close_reason)->toBe(CsTicket::CLOSE_REASON_USER);

    // 再次关闭 → 40009（已关闭不可再流转）
    $this->withHeaders($this->buyerAuth)->postJson('/api/cs/tickets/'.$ticket->id.'/close')
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);
});
