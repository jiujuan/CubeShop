<?php

use App\Models\CsQuickReply;
use App\Models\CsTicket;
use App\Models\CsTicketType;
use App\Models\Order;
use App\Models\User;
use App\Services\Cs\CsQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-203：CsQuickReplyService 单元测试
 *
 * 覆盖：forType（通用+专属+排序）、render（三个占位符）、无匹配空集合。
 */
beforeEach(function () {
    seedRoles();
    $this->service = new CsQuickReplyService();
    $this->type = CsTicketType::create([
        'name' => '售后', 'code' => 'aftersale', 'is_active' => true, 'sort' => 1,
    ]);
});

it('forType 返回通用与类型专属并按 sort/id 排序', function () {
    // 通用模板两个（sort 5 / 1），专属 1 个（sort 3），其他类型 1 个（应被排除）
    CsQuickReply::create(['title' => '通用A', 'content' => 'c', 'type_id' => null, 'sort' => 5]);
    CsQuickReply::create(['title' => '通用B', 'content' => 'c', 'type_id' => null, 'sort' => 1]);
    CsQuickReply::create(['title' => '专属', 'content' => 'c', 'type_id' => $this->type->id, 'sort' => 3]);
    CsQuickReply::create(['title' => '其他类型', 'content' => 'c', 'type_id' => 999, 'sort' => 1]);

    $list = $this->service->forType($this->type->id);

    expect($list)->toHaveCount(3)
        ->and($list->pluck('title')->all())->toBe(['通用B', '专属', '通用A']);
});

it('render 正确替换三个占位符', function () {
    $user = User::create([
        'username' => 'u'.uniqid(), 'nickname' => '小明', 'password' => bcrypt('x'), 'status' => 1,
    ]);
    $order = Order::create([
        'order_no' => 'NO123', 'user_id' => $user->id, 'status' => Order::STATUS_PAID,
        'total_amount' => 1, 'freight_amount' => 0, 'discount_amount' => 0,
        'promotion_discount' => 0, 'pay_amount' => 1,
        'address_snapshot' => ['contact_name' => 'x', 'contact_phone' => '13800000000', 'full_address' => 'addr'],
    ]);
    $ticket = CsTicket::create([
        'ticket_no' => 'TK20260101001', 'user_id' => $user->id, 'type_id' => $this->type->id,
        'title' => 't', 'content' => 'c', 'status' => CsTicket::STATUS_PENDING, 'order_id' => $order->id,
    ]);

    $out = $this->service->render(
        '亲爱 {user_nickname} 您好，工单{ticket_no}已受理，订单{order_no}正在处理',
        $ticket
    );

    expect($out)->toBe('亲爱 小明 您好，工单TK20260101001已受理，订单NO123正在处理');
});

it('forType 无匹配时返回空集合不报错', function () {
    // 只存在「其他类型」模板，当前类型应取不到任何（通用也没有）
    CsQuickReply::create(['title' => '其他', 'content' => 'c', 'type_id' => 999, 'sort' => 1]);

    expect($this->service->forType($this->type->id))->toHaveCount(0);
});

it('render 缺失关联时占位符替换为空串而非报错', function () {
    $user = User::create([
        'username' => 'u'.uniqid(), 'nickname' => null, 'password' => bcrypt('x'), 'status' => 1,
    ]);
    $ticket = CsTicket::create([
        'ticket_no' => 'TKX', 'user_id' => $user->id, 'type_id' => $this->type->id,
        'title' => 't', 'content' => 'c', 'status' => CsTicket::STATUS_PENDING,
    ]);

    expect($this->service->render('{user_nickname}/{ticket_no}/{order_no}', $ticket))
        ->toBe('/TKX/');
});
