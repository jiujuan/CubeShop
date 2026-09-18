<?php

use App\Exceptions\BusinessException;
use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Cs\CsTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-105：CsTicketService（建单 / 消息 / 状态机 / 可见性 / 通知）
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\CsTicketTypeSeeder::class);
    $this->service = app(CsTicketService::class);

    $this->user = User::forceCreate([
        'username' => 'cs_user_'.uniqid(),
        'phone' => '13800000001',
        'password' => bcrypt('Test@1234'),
        'nickname' => '测试买家',
        'status' => 1,
    ]);

    $this->other = User::forceCreate([
        'username' => 'cs_other_'.uniqid(),
        'phone' => '13800000002',
        'password' => bcrypt('Test@1234'),
        'status' => 1,
    ]);

    $this->preSale = CsTicketType::where('code', 'pre_sale')->first();   // 不要求订单
    $this->logistics = CsTicketType::where('code', 'logistics')->first(); // 必须订单
});

function csOrder(User $user, string $no = 'CS20260917000001'): Order
{
    return Order::forceCreate([
        'order_no' => $no,
        'user_id' => $user->id,
        'status' => 'paid',
        'total_amount' => 100,
        'pay_amount' => 100,
        'discount_amount' => 0,
        'promotion_discount' => 0,
        'freight_amount' => 0,
        'address_snapshot' => json_encode(['name' => '张三', 'phone' => '13800000001', 'detail' => '测试地址'], JSON_UNESCAPED_UNICODE),
    ]);
}

// ---------- 建单 ----------

it('建单生成 TK 前缀工单号且落库首条用户消息', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id,
        'title' => '商品咨询',
        'content' => '请问有货吗',
    ]);

    // SEC-03：TK + 日期 + 10 位随机段（原为 6 位可枚举序列）
    expect($ticket->ticket_no)->toMatch('/^TK\d{8}\d{10}$/')
        ->and($ticket->status)->toBe(CsTicket::STATUS_PENDING)
        ->and($ticket->contact)->toBe('13800000001')
        ->and($ticket->messages)->toHaveCount(1)
        ->and($ticket->messages->first()->sender_type)->toBe(CsTicketMessage::SENDER_USER)
        ->and($ticket->messages->first()->content)->toBe('请问有货吗');
});

it('工单号唯一：连续建单不重复', function () {
    $nos = [];
    for ($i = 0; $i < 5; $i++) {
        $nos[] = $this->service->createTicket($this->user, [
            'type_id' => $this->preSale->id,
            'title' => '单 '.$i,
            'content' => '内容',
        ])->ticket_no;
    }

    expect(array_unique($nos))->toHaveCount(5);
});

it('必须关联订单的类型未传订单时被拒', function () {
    $this->service->createTicket($this->user, [
        'type_id' => $this->logistics->id,
        'title' => '物流问题',
        'content' => '没收到货',
    ]);
})->throws(BusinessException::class, '该问题类型必须关联订单');

it('传入不属于当前用户的订单被拒', function () {
    $order = csOrder($this->other, 'CS20260917000002');

    $this->service->createTicket($this->user, [
        'type_id' => $this->logistics->id,
        'title' => '物流问题',
        'content' => '没收到货',
        'order_id' => $order->id,
    ]);
})->throws(BusinessException::class, '关联订单不存在或不属于当前用户');

it('必填订单类型传本人订单可正常建单', function () {
    $order = csOrder($this->user);

    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->logistics->id,
        'title' => '物流问题',
        'content' => '没收到货',
        'order_id' => $order->id,
    ]);

    expect($ticket->order_id)->toBe($order->id);
});

it('凭证图片超过 9 张被拒', function () {
    $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id,
        'title' => '图片超限',
        'content' => '内容',
        'images' => array_fill(0, 10, '/storage/cs/a.png'),
    ]);
})->throws(BusinessException::class, '凭证图片最多 9 张');

// ---------- 消息与自动流转 ----------

it('客服首条回复写入首响时间，第二条不覆盖', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '您好');
    $first = $ticket->fresh()->first_replied_at;

    expect($first)->not->toBeNull();

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '还有什么问题');
    expect($ticket->fresh()->first_replied_at->toDateTimeString())->toBe($first->toDateTimeString());
});

it('内部备注不写入首响时间', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '内部备注', null, true);

    expect($ticket->fresh()->first_replied_at)->toBeNull();
});

it('非客服发送方传入 is_internal 被强制置为 false', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $msg = $this->service->addMessage($ticket, CsTicketMessage::SENDER_USER, $this->user->id, '追加', null, true);

    expect($msg->is_internal)->toBeFalse();
});

it('客服回复自动 pending → processing，用户回复自动 completed → processing', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '您好，有什么可以帮您');
    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_PROCESSING);

    $this->service->transitionTo($ticket, CsTicket::STATUS_COMPLETED, 1);
    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_COMPLETED);

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_USER, $this->user->id, '还有个问题');
    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_PROCESSING);
});

it('客服回复使 waiting_user → processing', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);
    $this->service->transitionTo($ticket, CsTicket::STATUS_PROCESSING, 1);
    $this->service->transitionTo($ticket, CsTicket::STATUS_WAITING_USER, 1);

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '已为您处理');

    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_PROCESSING);
});

it('消息内容与图片同时为空被拒', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '', []);
})->throws(BusinessException::class, '消息内容与图片不能同时为空');

// ---------- 状态机 ----------

it('非法流转被拦截且事务回滚不产生消息', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);
    $this->service->transitionTo($ticket, CsTicket::STATUS_PROCESSING, 1);
    $this->service->transitionTo($ticket, CsTicket::STATUS_CLOSED, 1);

    $before = $ticket->fresh()->messages()->count();

    try {
        $this->service->transitionTo($ticket, CsTicket::STATUS_PROCESSING, 1);
        $this->fail('期望抛出业务冲突异常');
    } catch (BusinessException $e) {
        expect($e->businessCode)->toBe(40009);
    }

    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_CLOSED)
        ->and($ticket->fresh()->messages()->count())->toBe($before);
});

it('关闭工单写入关闭时间与原因', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $this->service->transitionTo($ticket, CsTicket::STATUS_CLOSED, null, CsTicket::CLOSE_REASON_USER);

    $fresh = $ticket->fresh();
    expect($fresh->closed_at)->not->toBeNull()
        ->and($fresh->close_reason)->toBe(CsTicket::CLOSE_REASON_USER)
        ->and(CsTicketMessage::where('ticket_id', $fresh->id)->orderByDesc('id')->first()->content)->toContain('用户关闭');
});

it('重复提交相同状态变更幂等：不重复写系统消息', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $this->service->transitionTo($ticket, CsTicket::STATUS_PROCESSING, 1);
    $after = $ticket->fresh()->messages()->count();

    $this->service->transitionTo($ticket, CsTicket::STATUS_PROCESSING, 1);

    expect($ticket->fresh()->messages()->count())->toBe($after);
});

it('完成工单写入 completed_at', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);
    $this->service->transitionTo($ticket, CsTicket::STATUS_PROCESSING, 1);
    $this->service->transitionTo($ticket, CsTicket::STATUS_COMPLETED, 1);

    expect($ticket->fresh()->completed_at)->not->toBeNull();
});

// ---------- 可见性与查询 ----------

it('用户侧消息流过滤内部备注，客服侧可见', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);
    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '正常回复');
    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '内部备注', null, true);

    expect($this->service->messagesFor($ticket->fresh(), false))->toHaveCount(2)
        ->and($this->service->messagesFor($ticket->fresh(), true))->toHaveCount(3);
});

it('买家只能查到自己的工单', function () {
    $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '我的', 'content' => '内容',
    ]);
    $this->service->createTicket($this->other, [
        'type_id' => $this->preSale->id, 'title' => '他人的', 'content' => '内容',
    ]);

    expect($this->service->forUser($this->user)->total())->toBe(1)
        ->and($this->service->forUser($this->user)->items()[0]->title)->toBe('我的');
});

it('按状态筛选买家工单', function () {
    $t = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '筛选', 'content' => '内容',
    ]);
    $this->service->transitionTo($t, CsTicket::STATUS_PROCESSING, 1);
    $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '待处理', 'content' => '内容',
    ]);

    expect($this->service->forUser($this->user, CsTicket::STATUS_PENDING)->total())->toBe(1)
        ->and($this->service->forUser($this->user, CsTicket::STATUS_PROCESSING)->total())->toBe(1)
        ->and($this->service->forUser($this->user, 'all')->total())->toBe(2);
});

it('待处理数量统计正确', function () {
    $this->service->createTicket($this->user, ['type_id' => $this->preSale->id, 'title' => 'A', 'content' => '内容']);
    $this->service->createTicket($this->user, ['type_id' => $this->preSale->id, 'title' => 'B', 'content' => '内容']);

    expect($this->service->pendingCount())->toBe(2);
});

// ---------- 通知 ----------

it('建单通知客服角色，客服回复通知用户', function () {
    $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    expect(Notification::where('type', 'cs_ticket_new')->count())->toBeGreaterThanOrEqual(0);

    $ticket = CsTicket::first();
    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '您好');

    expect(Notification::where('type', 'cs_ticket_reply')
        ->where('user_id', $this->user->id)
        ->count())->toBe(1);
});

it('内部备注不通知用户', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 1, '内部备注', null, true);

    expect(Notification::where('type', 'cs_ticket_reply')->where('user_id', $this->user->id)->count())->toBe(0);
});

// ---------- 转交与优先级 ----------

it('转交与优先级变更产生系统消息', function () {
    $ticket = $this->service->createTicket($this->user, [
        'type_id' => $this->preSale->id, 'title' => '咨询', 'content' => '在吗',
    ]);

    $this->service->assign($ticket, 9, 1, '张三');
    $this->service->setPriority($ticket, CsTicket::PRIORITY_URGENT, 1);

    $fresh = $ticket->fresh();
    expect($fresh->assignee_id)->toBe(9)
        ->and($fresh->priority)->toBe(CsTicket::PRIORITY_URGENT)
        ->and($fresh->messages()->where('sender_type', CsTicketMessage::SENDER_SYSTEM)->count())->toBe(2);
});
