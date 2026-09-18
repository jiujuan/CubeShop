<?php

use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Common\CaptchaService;
use App\Services\Cs\CsTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-108 后台工单列表/详情 + CS-109 处理动作 集成测试
 *
 * 鉴权：admin（super_admin，拥有 cs.ticket.view / cs.ticket.handle）走成功路径；
 *       operator 未授予 CS 权限，用于校验 403 分支。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $capOp = app(CaptchaService::class)->generate();
    $this->operatorAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'operator', 'password' => 'Operator@123',
        'captcha_id' => $capOp['captcha_id'], 'captcha_code' => $capOp['debug_code'],
    ])->json('data.token')];

    $this->type = CsTicketType::create(['name' => '售前咨询', 'code' => 'pre_sale', 'require_order' => false, 'sort' => 1, 'is_active' => true]);

    // 买家 + 订单 fixture（用于 show 摘要测试）
    $this->buyer = User::create(['username' => 'cstk'.uniqid(), 'phone' => '13800001234', 'password' => bcrypt('Test@1234'), 'status' => 1]);
    $category = Category::create(['name' => '测试分类', 'sort' => 1, 'status' => 1]);
    $this->product = Product::create(['category_id' => $category->id, 'title' => '测试商品', 'main_image' => '/storage/p/x.png', 'price' => 10, 'status' => 1]);
    $this->order = Order::create([
        'order_no' => 'CS'.time().rand(1000, 9999), 'user_id' => $this->buyer->id, 'status' => 'paid',
        'total_amount' => 100, 'pay_amount' => 100, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0, 'address_snapshot' => '{}',
    ]);
    OrderItem::create(['order_id' => $this->order->id, 'product_id' => $this->product->id, 'sku_id' => 1, 'product_title' => '测试商品', 'sku_image' => '/storage/p/x.png', 'price' => 100, 'quantity' => 1, 'total_amount' => 100]);
});

function makeTicket($buyer, $typeId, array $extra = []): CsTicket
{
    return app(CsTicketService::class)->createTicket($buyer, array_merge([
        'type_id' => $typeId, 'title' => '咨询问题', 'content' => '请帮忙处理',
    ], $extra));
}

it('operator 缺权限访问工单列表返回 403', function () {
    $this->withHeaders($this->operatorAuth)->getJson('/api/admin/cs/tickets')
        ->assertForbidden()
        ->assertJsonPath('code', 40003);
});

it('admin 列表返回分页结构且含待处理统计', function () {
    makeTicket($this->buyer, $this->type->id); // pending
    $t2 = makeTicket($this->buyer, $this->type->id);
    app(CsTicketService::class)->transitionTo($t2, CsTicket::STATUS_PROCESSING, 1);

    $res = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets');
    $res->assertOk();

    expect($res->json('data.pagination'))->toHaveKeys(['page', 'page_size', 'total', 'total_pages'])
        ->and($res->json('data.meta.pending_count'))->toBe(1)
        ->and($res->json('data.list'))->toHaveCount(2);
});

it('列表支持按状态筛选', function () {
    makeTicket($this->buyer, $this->type->id); // pending
    $t2 = makeTicket($this->buyer, $this->type->id);
    app(CsTicketService::class)->transitionTo($t2, CsTicket::STATUS_PROCESSING, 1);

    $pending = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets?status=pending')->json('data.pagination.total');
    $processing = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets?status=processing')->json('data.pagination.total');
    expect($pending)->toBe(1)->and($processing)->toBe(1);
});

it('详情返回工单、用户脱敏摘要、订单摘要与可用操作', function () {
    $ticket = makeTicket($this->buyer, $this->type->id, ['order_id' => $this->order->id]);

    $res = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id);
    $res->assertOk();

    expect($res->json('data.ticket.ticket_no'))->toBe($ticket->ticket_no)
        ->and($res->json('data.user_summary.phone_masked'))->toBe('138****1234')
        ->and($res->json('data.user_summary.ticket_count'))->toBeGreaterThanOrEqual(1)
        ->and($res->json('data.order.order_no'))->toBe($this->order->order_no)
        ->and($res->json('data.order.product_image'))->toBe('/storage/p/x.png')
        ->and($res->json('data.actions'))->toHaveKeys(['can_reply', 'can_complete', 'can_close']);
});

it('内部备注回复仅客服可见、不触发状态流转、不通知用户', function () {
    Notification::query()->delete();
    $ticket = makeTicket($this->buyer, $this->type->id); // pending

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/tickets/'.$ticket->id.'/messages', [
        'content' => '内部讨论', 'is_internal' => true,
    ]);
    $res->assertOk();

    // 后台详情可见内部备注
    $detail = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id)->json('data.ticket.messages');
    $internal = collect($detail)->where('is_internal', true)->first();
    expect($internal)->not->toBeNull()
        ->and($internal['content'])->toBe('内部讨论');

    // 状态保持 pending（内部备注不自动流转）
    expect(CsTicket::find($ticket->id)->status)->toBe(CsTicket::STATUS_PENDING);

    // 内部备注不通知用户
    expect(Notification::where('receiver_type', 'customer')->where('type', 'cs_ticket_reply')->where('user_id', $this->buyer->id)->count())->toBe(0);
});

it('客服公开回复驱动 pending → processing 自动流转', function () {
    $ticket = makeTicket($this->buyer, $this->type->id); // pending

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/tickets/'.$ticket->id.'/messages', ['content' => '已受理'])
        ->assertOk();

    expect(CsTicket::find($ticket->id)->status)->toBe(CsTicket::STATUS_PROCESSING)
        ->and(CsTicket::find($ticket->id)->first_replied_at)->not->toBeNull();
});

it('状态变更非法流转返回 409', function () {
    $ticket = makeTicket($this->buyer, $this->type->id); // pending 仅可 → processing / closed

    $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/tickets/'.$ticket->id.'/status', ['status' => CsTicket::STATUS_COMPLETED])
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);

    expect(CsTicket::find($ticket->id)->status)->toBe(CsTicket::STATUS_PENDING); // 未改变
});

it('转交客服更新 assignee_id 并记系统消息', function () {
    $ticket = makeTicket($this->buyer, $this->type->id);
    $assignee = \App\Models\SysUser::where('username', 'operator')->first();

    $res = $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/tickets/'.$ticket->id.'/assign', ['assignee_id' => $assignee->id]);
    $res->assertOk();

    expect(CsTicket::find($ticket->id)->assignee_id)->toBe($assignee->id)
        ->and($res->json('data.assignee_name'))->toBe('operator')
        ->and(CsTicketMessage::where('ticket_id', $ticket->id)->where('sender_type', CsTicketMessage::SENDER_SYSTEM)->count())->toBe(1);
});

it('优先级标记紧急', function () {
    $ticket = makeTicket($this->buyer, $this->type->id);

    $res = $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/tickets/'.$ticket->id.'/priority', ['priority' => CsTicket::PRIORITY_URGENT]);
    $res->assertOk();

    expect(CsTicket::find($ticket->id)->priority)->toBe(CsTicket::PRIORITY_URGENT)
        ->and($res->json('data.priority_label'))->toBe('紧急');
});

it('批量转交多个工单', function () {
    $t1 = makeTicket($this->buyer, $this->type->id);
    $t2 = makeTicket($this->buyer, $this->type->id);
    $assignee = \App\Models\SysUser::where('username', 'operator')->first();

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/tickets/batch-assign', [
        'ids' => [$t1->id, $t2->id], 'assignee_id' => $assignee->id,
    ]);
    $res->assertOk();

    expect($res->json('data.affected'))->toBe(2)
        ->and(CsTicket::find($t1->id)->assignee_id)->toBe($assignee->id)
        ->and(CsTicket::find($t2->id)->assignee_id)->toBe($assignee->id);
});

it('待处理统计 meta 反映 pending 工单数量', function () {
    makeTicket($this->buyer, $this->type->id); // pending
    makeTicket($this->buyer, $this->type->id); // pending
    $t3 = makeTicket($this->buyer, $this->type->id);
    app(CsTicketService::class)->transitionTo($t3, CsTicket::STATUS_PROCESSING, 1); // 不再 pending

    $meta = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets')->json('data.meta.pending_count');
    expect($meta)->toBe(2);
});
