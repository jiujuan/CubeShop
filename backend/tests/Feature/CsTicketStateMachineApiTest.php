<?php

use App\Exceptions\BusinessException;
use App\Models\CsTicket;
use App\Models\CsTicketType;
use App\Models\User;
use App\Services\Common\CaptchaService;
use App\Services\Cs\CsTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-116 状态机专项（API 层 + 服务层矩阵）
 *
 * ① 5 状态 × 5 目标 = 25 条路径全覆盖：合法 → 成功并写系统消息；非法 → 40009 且无副作用；
 *    自流转 → 幂等（不改状态、不写系统消息）。
 * ② 后台状态接口的 HTTP 语义（合法 200 / 非法 409 / 目标不在白名单 422）。
 * ③ 用户关闭接口幂等（重复关闭 409）。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $this->type = CsTicketType::create([
        'name' => '咨询建议', 'code' => 'other', 'require_order' => false, 'sort' => 0, 'is_active' => true,
    ]);
    $this->buyer = createTestUser('cs-sm-buyer');
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->buyer->createToken('t')->plainTextToken];

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/** 建单并把前置状态强制设为 $status（仅用于构造测试前置，不代表合法流转） */
function csSmMakeTicket(string $status): CsTicket
{
    $service = app(CsTicketService::class);
    $ticket = $service->createTicket(test()->buyer, [
        'type_id' => test()->type->id,
        'title' => '状态机用例',
        'content' => '初始内容',
    ]);

    if ($status !== CsTicket::STATUS_PENDING) {
        $ticket->forceFill(['status' => $status])->save();
    }

    return $ticket->fresh();
}

dataset('csSmTransitions', function () {
    $statuses = [
        CsTicket::STATUS_PENDING,
        CsTicket::STATUS_PROCESSING,
        CsTicket::STATUS_WAITING_USER,
        CsTicket::STATUS_COMPLETED,
        CsTicket::STATUS_CLOSED,
    ];

    $cases = [];
    foreach ($statuses as $from) {
        foreach ($statuses as $to) {
            $cases["{$from} → {$to}"] = [$from, $to];
        }
    }

    return $cases;
});

test('状态机 25 条路径：合法成功 / 非法 40009 且无副作用', function (string $from, string $to) {
    $ticket = csSmMakeTicket($from);
    $service = app(CsTicketService::class);
    $messagesBefore = $ticket->messages()->count();

    // 自流转：幂等，不写系统消息
    if ($from === $to) {
        $result = $service->transitionTo($ticket, $to);
        expect($result->status)->toBe($to)
            ->and($ticket->messages()->count())->toBe($messagesBefore);

        return;
    }

    $legal = in_array($to, CsTicket::TRANSITIONS[$from] ?? [], true);

    if ($legal) {
        $result = $service->transitionTo($ticket, $to);
        expect($result->status)->toBe($to)
            ->and($ticket->messages()->count())->toBe($messagesBefore + 1);

        // 时间锚点：completed/closed 必须落对应时间
        if ($to === CsTicket::STATUS_COMPLETED) {
            expect($result->completed_at)->not->toBeNull();
        }
        if ($to === CsTicket::STATUS_CLOSED) {
            expect($result->closed_at)->not->toBeNull();
        }
    } else {
        expect(fn () => $service->transitionTo($ticket, $to))->toThrow(BusinessException::class);
        $after = $ticket->fresh();
        expect($after->status)->toBe($from)
            ->and($ticket->messages()->count())->toBe($messagesBefore);
    }
})->with('csSmTransitions');

test('后台状态接口：pending→processing 合法返回 200', function () {
    $ticket = csSmMakeTicket(CsTicket::STATUS_PENDING);

    $this->putJson("/api/admin/cs/tickets/{$ticket->id}/status", ['status' => CsTicket::STATUS_PROCESSING], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('data.status', CsTicket::STATUS_PROCESSING);
});

test('后台状态接口：非法流转返回 409 且状态不变', function () {
    $ticket = csSmMakeTicket(CsTicket::STATUS_PENDING);
    $before = $ticket->messages()->count();

    $this->putJson("/api/admin/cs/tickets/{$ticket->id}/status", ['status' => CsTicket::STATUS_COMPLETED], $this->adminAuth)
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);

    expect($ticket->fresh()->status)->toBe(CsTicket::STATUS_PENDING)
        ->and($ticket->messages()->count())->toBe($before);
});

test('后台状态接口：目标不在白名单（pending）返回 422', function () {
    $ticket = csSmMakeTicket(CsTicket::STATUS_PENDING);

    $this->putJson("/api/admin/cs/tickets/{$ticket->id}/status", ['status' => CsTicket::STATUS_PENDING], $this->adminAuth)
        ->assertStatus(422);
});

test('用户关闭接口：首次 200，重复关闭 409（幂等保护）', function () {
    $ticket = csSmMakeTicket(CsTicket::STATUS_PROCESSING);

    $this->postJson("/api/cs/tickets/{$ticket->id}/close", [], $this->buyerAuth)
        ->assertOk()
        ->assertJsonPath('data.status', CsTicket::STATUS_CLOSED);

    $this->postJson("/api/cs/tickets/{$ticket->id}/close", [], $this->buyerAuth)
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);
});
