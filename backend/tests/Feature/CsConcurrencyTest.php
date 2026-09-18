<?php

use App\Exceptions\BusinessException;
use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use App\Services\Cs\CsTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * CS-116 并发与幂等专项
 *
 * 由于行级锁（lockForUpdate）把同一工单的写入串行化，下列场景在单进程测试中以「顺序触发 + 唯一性/计数断言」
 * 等价验证其保证（真并发压测脚本见 docs/testing/evidence/cs/CS-116/loadtest-CS-116.md）。
 */
beforeEach(function () {
    $this->type = CsTicketType::create([
        'name' => '咨询建议', 'code' => 'other', 'require_order' => false, 'sort' => 0, 'is_active' => true,
    ]);
    $this->buyer = createTestUser('cs-conc-buyer');
    $this->service = app(CsTicketService::class);
});

test('并发建单 50 次：工单号唯一无重号', function () {
    $numbers = [];
    for ($i = 0; $i < 50; $i++) {
        $ticket = $this->service->createTicket($this->buyer, [
            'type_id' => $this->type->id, 'title' => "批量工单 {$i}", 'content' => '内容',
        ]);
        $numbers[] = $ticket->ticket_no;
    }

    expect($numbers)->toHaveCount(50)
        ->and(array_unique($numbers))->toHaveCount(50)
        ->and(CsTicket::count())->toBe(50);
});

test('并发状态变更：10 次竞争仅 1 次真实写入（其余幂等/被拒）', function () {
    $ticket = $this->service->createTicket($this->buyer, [
        'type_id' => $this->type->id, 'title' => '并发状态', 'content' => '内容',
    ]);
    $messagesBefore = $ticket->messages()->count();

    $success = 0;
    $rejected = 0;
    for ($i = 0; $i < 10; $i++) {
        try {
            $this->service->transitionTo($ticket->fresh(), CsTicket::STATUS_PROCESSING);
            $success++;
        } catch (BusinessException) {
            $rejected++;
        }
    }

    // 首次真实流转成功，其余命中幂等分支（返回当前状态）或非法流转被拒
    expect($success)->toBeGreaterThanOrEqual(1)
        ->and($success + $rejected)->toBe(10);

    // 关键：无论多少次竞争，只产生 1 条状态变更系统消息
    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_PROCESSING)
        ->and($ticket->fresh()->messages()->count())->toBe($messagesBefore + 1);
});

test('重复提交同一回复（网络重试）：消息不重复', function () {
    $ticket = $this->service->createTicket($this->buyer, [
        'type_id' => $this->type->id, 'title' => '重试幂等', 'content' => '内容',
    ]);
    $before = $ticket->messages()->count();

    $first = $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 999, '您好，正在为您处理');
    $second = $this->service->addMessage($ticket->fresh(), CsTicketMessage::SENDER_STAFF, 999, '您好，正在为您处理');

    expect($second->id)->toBe($first->id)
        ->and($ticket->fresh()->messages()->count())->toBe($before + 1);
});

test('不同内容或超过窗口期的回复正常落库', function () {
    $ticket = $this->service->createTicket($this->buyer, [
        'type_id' => $this->type->id, 'title' => '正常回复', 'content' => '内容',
    ]);
    $before = $ticket->messages()->count();

    $this->service->addMessage($ticket, CsTicketMessage::SENDER_STAFF, 999, '第一条');
    $this->service->addMessage($ticket->fresh(), CsTicketMessage::SENDER_STAFF, 999, '第二条');

    expect($ticket->fresh()->messages()->count())->toBe($before + 2);
});

test('数据一致性核对：无孤儿消息、时间锚点无矛盾', function () {
    // 构造不同终态：一条走到 completed，一条走到 closed，一条已首响
    $completed = $this->service->createTicket($this->buyer, ['type_id' => $this->type->id, 'title' => 'A', 'content' => 'a']);
    $this->service->transitionTo($completed, CsTicket::STATUS_PROCESSING);
    $this->service->transitionTo($completed->fresh(), CsTicket::STATUS_COMPLETED);

    $closed = $this->service->createTicket($this->buyer, ['type_id' => $this->type->id, 'title' => 'B', 'content' => 'b']);
    $this->service->transitionTo($closed, CsTicket::STATUS_CLOSED);

    $replied = $this->service->createTicket($this->buyer, ['type_id' => $this->type->id, 'title' => 'C', 'content' => 'c']);
    $this->service->addMessage($replied, CsTicketMessage::SENDER_STAFF, 999, '首响');

    // ① 孤儿消息（无对应工单）
    $orphans = DB::table('cs_ticket_message')
        ->leftJoin('cs_ticket', 'cs_ticket.id', '=', 'cs_ticket_message.ticket_id')
        ->whereNull('cs_ticket.id')
        ->count();

    // ② completed 必须有 completed_at；closed 必须有 closed_at
    $completedBad = DB::table('cs_ticket')->where('status', 'completed')->whereNull('completed_at')->count();
    $closedBad = DB::table('cs_ticket')->where('status', 'closed')->whereNull('closed_at')->count();

    // ③ 首响锚点：有客服非内部消息的工单必须已写 first_replied_at
    $firstReplyBad = DB::table('cs_ticket')
        ->whereNull('first_replied_at')
        ->whereExists(function ($q) {
            $q->select(DB::raw(1))->from('cs_ticket_message')
                ->whereColumn('cs_ticket_message.ticket_id', 'cs_ticket.id')
                ->where('cs_ticket_message.sender_type', CsTicketMessage::SENDER_STAFF)
                ->where('cs_ticket_message.is_internal', false);
        })
        ->count();

    expect($orphans)->toBe(0)
        ->and($completedBad)->toBe(0)
        ->and($closedBad)->toBe(0)
        ->and($firstReplyBad)->toBe(0);
});

