<?php

use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Cs\CsTicketService;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-106 用户端工单接口 + CS-107 图片上传与站内通知 集成测试
 *
 * 买家端 sanctum 为无状态守卫，beforeEach 通过注册接口拿真实 token。
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $this->preSale = CsTicketType::create(['name' => '售前咨询', 'code' => 'pre_sale', 'require_order' => false, 'sort' => 1, 'is_active' => true]);
    $this->logistics = CsTicketType::create(['name' => '物流问题', 'code' => 'logistics', 'require_order' => true, 'sort' => 2, 'is_active' => true]);

    // 注册买家拿 token
    $cap = app(CaptchaService::class)->generate();
    $username = 'cstk'.uniqid();
    $token = $this->postJson('/api/auth/register', [
        'username' => $username,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->auth = ['Authorization' => 'Bearer '.$token];
    $this->buyer = \App\Models\User::where('username', $username)->first();

    // 订单摘要 fixture
    $category = \App\Models\Category::create(['name' => '测试分类', 'sort' => 1, 'status' => 1]);
    $this->product = Product::create(['category_id' => $category->id, 'title' => '测试商品', 'main_image' => '/storage/p/x.png', 'price' => 10, 'status' => 1]);
    $this->order = Order::create([
        'order_no' => 'CS'.time().rand(1000, 9999),
        'user_id' => $this->buyer->id, 'status' => 'paid',
        'total_amount' => 100, 'pay_amount' => 100,
        'discount_amount' => 0, 'promotion_discount' => 0, 'freight_amount' => 0,
        'address_snapshot' => json_encode(['name' => '张三']),
    ]);
    OrderItem::create(['order_id' => $this->order->id, 'product_id' => $this->product->id, 'sku_id' => 1, 'product_title' => '测试商品', 'sku_image' => '/storage/p/x.png', 'price' => 100, 'quantity' => 1, 'total_amount' => 100]);
});

function createTicket($auth, $typeId, array $extra = [])
{
    return test()->postJson('/api/cs/tickets', array_merge([
        'type_id' => $typeId,
        'title' => '咨询问题',
        'content' => '请帮忙处理',
    ], $extra), $auth);
}

// ---------- CS-106 工单接口 ----------

it('工单类型接口返回激活类型且含 require_order 标记', function () {
    CsTicketType::create(['name' => '隐藏', 'code' => 'hidden', 'require_order' => false, 'is_active' => false]);

    $res = $this->withHeaders($this->auth)->getJson('/api/cs/ticket-types');

    $res->assertOk();
    $codes = collect($res->json('data'))->pluck('code')->all();
    expect($codes)->toContain('pre_sale')->toContain('logistics')
        ->and($codes)->not->toContain('hidden');
    $byCode = collect($res->json('data'))->keyBy('code');
    expect($byCode['logistics']['require_order'])->toBeTrue();
});

it('提交工单返回工单号且初始用户消息落库', function () {
    $res = createTicket($this->auth, $this->preSale->id);

    $res->assertCreated();
    $id = $res->json('data.ticket.id');
    $ticket = CsTicket::find(tid($id));
    expect($ticket)->not->toBeNull()
        ->and($ticket->messages()->where('sender_type', CsTicketMessage::SENDER_USER)->count())->toBe(1);
});

it('必须关联订单的类型缺 order_id 返回 422', function () {
    createTicket($this->auth, $this->logistics->id)->assertStatus(422);
});

it('传入他人订单返回 40000', function () {
    $other = \App\Models\User::forceCreate(['username' => 'other'.uniqid(), 'phone' => '13800000999', 'password' => bcrypt('Test@1234'), 'status' => 1]);
    $otherOrder = Order::create(['order_no' => 'OTH'.time(), 'user_id' => $other->id, 'status' => 'paid', 'total_amount' => 1, 'pay_amount' => 1, 'discount_amount' => 0, 'promotion_discount' => 0, 'freight_amount' => 0, 'address_snapshot' => '{}']);

    createTicket($this->auth, $this->logistics->id, ['order_id' => $otherOrder->id])->assertStatus(400);
});

it('标题超长返回 422', function () {
    createTicket($this->auth, $this->preSale->id, ['title' => str_repeat('长', 200)])->assertStatus(422);
});

it('图片超过 9 张返回 422', function () {
    createTicket($this->auth, $this->preSale->id, ['images' => array_fill(0, 10, '/storage/cs/a.png')])->assertStatus(422);
});

it('未登录提交返回 401', function () {
    $this->postJson('/api/cs/tickets', ['type_id' => $this->preSale->id, 'title' => 'x', 'content' => 'y'])->assertUnauthorized();
});

it('我的工单列表只含本人工单', function () {
    createTicket($this->auth, $this->preSale->id);
    $other = \App\Models\User::forceCreate(['username' => 'other2'.uniqid(), 'phone' => '13800000998', 'password' => bcrypt('Test@1234'), 'status' => 1]);
    app(CsTicketService::class)->createTicket($other, ['type_id' => $this->preSale->id, 'title' => '他人', 'content' => '内容']);

    $res = $this->withHeaders($this->auth)->getJson('/api/cs/tickets');
    $res->assertOk();
    expect($res->json('data.list'))->toHaveCount(1)
        ->and($res->json('data.list.0.title'))->toBe('咨询问题');
});

it('列表按状态筛选正确', function () {
    createTicket($this->auth, $this->preSale->id); // pending
    $t2 = app(CsTicketService::class)->createTicket($this->buyer, ['type_id' => $this->preSale->id, 'title' => '处理中', 'content' => '内容']);
    app(CsTicketService::class)->transitionTo($t2, CsTicket::STATUS_PROCESSING, 1);

    $pending = $this->withHeaders($this->auth)->getJson('/api/cs/tickets?status=pending')->json('data.pagination.total');
    $processing = $this->withHeaders($this->auth)->getJson('/api/cs/tickets?status=processing')->json('data.pagination.total');
    expect($pending)->toBe(1)->and($processing)->toBe(1);
});

it('详情消息流按时间正序且不含内部备注', function () {
    $ticket = app(CsTicketService::class)->createTicket($this->buyer, ['type_id' => $this->preSale->id, 'title' => '详情', 'content' => '首条']);
    app(CsTicketService::class)->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '内部备注', null, true); // 内部备注
    app(CsTicketService::class)->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '公开回复');

    $res = $this->withHeaders($this->auth)->getJson('/api/cs/tickets/'.$ticket->id);
    $res->assertOk();
    $msgs = $res->json('data.ticket.messages');
    expect($msgs)->toHaveCount(2) // 首条用户 + 公开回复，内部备注被过滤
        ->and(collect($msgs)->pluck('is_internal')->all())->not->toContain(true);
});

it('详情含关联订单摘要与商品首图', function () {
    $ticket = createTicket($this->auth, $this->logistics->id, ['order_id' => $this->order->id]);
    $id = $ticket->json('data.ticket.id');

    $res = $this->withHeaders($this->auth)->getJson('/api/cs/tickets/'.$id);
    $order = $res->json('data.order');
    expect($order['order_no'])->toBe($this->order->order_no)
        ->and($order['pay_amount'])->toBe('100.00')
        ->and($order['product_image'])->toBe('/storage/p/x.png');
});

it('追加回复成功且自动回流 processing', function () {
    $ticket = app(CsTicketService::class)->createTicket($this->buyer, ['type_id' => $this->preSale->id, 'title' => '回', 'content' => '首']); // pending
    $svc = app(CsTicketService::class);
    $svc->transitionTo($ticket, CsTicket::STATUS_PROCESSING, 1);
    $svc->transitionTo($ticket, CsTicket::STATUS_COMPLETED, 1); // completed

    $res = $this->withHeaders($this->auth)->postJson('/api/cs/tickets/'.$ticket->id.'/messages', ['content' => '还有问题'], $this->auth);
    $res->assertOk();
    expect(CsTicket::find($ticket->id)->status)->toBe(CsTicket::STATUS_PROCESSING);
});

it('用户关闭成功，再次关闭返回冲突 409', function () {
    $ticket = app(CsTicketService::class)->createTicket($this->buyer, ['type_id' => $this->preSale->id, 'title' => '关', 'content' => '首']);

    $this->withHeaders($this->auth)->postJson('/api/cs/tickets/'.$ticket->id.'/close')->assertOk();
    $this->withHeaders($this->auth)->postJson('/api/cs/tickets/'.$ticket->id.'/close')->assertStatus(409);
});

it('建单限流：连续 11 次第 11 次 429', function () {
    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = createTicket($this->auth, $this->preSale->id, ['title' => '限流'.$i])->status();
    }
    expect($statuses[9])->toBe(201) // 第 10 次仍成功
        ->and($statuses[10])->toBe(429); // 第 11 次被限流
});

// ---------- CS-107 上传与通知 ----------

it('图片上传返回 url 且落盘 cs 目录', function () {
    $file = \Illuminate\Http\UploadedFile::fake()->image('proof.jpg');

    $res = $this->withHeaders($this->auth)->postJson('/api/cs/upload-image', ['image' => $file]);
    $res->assertOk();
    expect($res->json('data.url'))->toContain('/storage/uploads/cs/');
});

it('非图片文件被拒 422', function () {
    $file = \Illuminate\Http\UploadedFile::fake()->create('doc.txt', 10);
    $this->withHeaders($this->auth)->postJson('/api/cs/upload-image', ['image' => $file])->assertStatus(422);
});

it('未登录上传返回 401', function () {
    $file = \Illuminate\Http\UploadedFile::fake()->image('proof.jpg');
    $this->postJson('/api/cs/upload-image', ['image' => $file])->assertUnauthorized();
});

it('建单后 operator 角色收到站内信', function () {
    Notification::query()->delete();
    createTicket($this->auth, $this->preSale->id);

    expect(Notification::where('receiver_type', 'admin')->where('type', 'cs_ticket_new')->count())->toBeGreaterThanOrEqual(1);
});

it('客服公开回复通知用户，内部备注不通知', function () {
    Notification::query()->delete();
    $ticket = app(CsTicketService::class)->createTicket($this->buyer, ['type_id' => $this->preSale->id, 'title' => '通知', 'content' => '首']);
    $svc = app(CsTicketService::class);

    $svc->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '公开回复'); // 应通知
    expect(Notification::where('receiver_type', 'customer')->where('type', 'cs_ticket_reply')->where('user_id', $this->buyer->id)->count())->toBe(1);

    $svc->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '内部备注', null, true); // 不通知
    expect(Notification::where('receiver_type', 'customer')->where('type', 'cs_ticket_reply')->where('user_id', $this->buyer->id)->count())->toBe(1); // 不变
});

it('状态变更后用户收到站内信', function () {
    Notification::query()->delete();
    $ticket = app(CsTicketService::class)->createTicket($this->buyer, ['type_id' => $this->preSale->id, 'title' => '状态', 'content' => '首']);
    app(CsTicketService::class)->transitionTo($ticket, CsTicket::STATUS_PROCESSING, 1);

    expect(Notification::where('receiver_type', 'customer')->where('type', 'cs_ticket_status')->where('user_id', $this->buyer->id)->count())->toBeGreaterThanOrEqual(1);
});
