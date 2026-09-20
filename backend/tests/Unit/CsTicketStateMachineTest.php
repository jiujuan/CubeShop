<?php

use App\Exceptions\BusinessException;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-102：工单状态机、模型 casts/关联、默认数据种子
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\CsTicketTypeSeeder::class);
    $this->seed(\Database\Seeders\CsFaqCategorySeeder::class);
});

// ---------- 状态流转矩阵 ----------

it('合法流转全部允许，非法流转全部拒绝', function () {
    $matrix = [
        // [当前状态, 目标状态, 是否允许]
        [CsTicket::STATUS_PENDING, CsTicket::STATUS_PROCESSING, true],
        [CsTicket::STATUS_PENDING, CsTicket::STATUS_CLOSED, true],
        [CsTicket::STATUS_PENDING, CsTicket::STATUS_COMPLETED, false],
        [CsTicket::STATUS_PENDING, CsTicket::STATUS_WAITING_USER, false],

        [CsTicket::STATUS_PROCESSING, CsTicket::STATUS_WAITING_USER, true],
        [CsTicket::STATUS_PROCESSING, CsTicket::STATUS_COMPLETED, true],
        [CsTicket::STATUS_PROCESSING, CsTicket::STATUS_CLOSED, true],
        [CsTicket::STATUS_PROCESSING, CsTicket::STATUS_PENDING, false],

        [CsTicket::STATUS_WAITING_USER, CsTicket::STATUS_PROCESSING, true],
        [CsTicket::STATUS_WAITING_USER, CsTicket::STATUS_CLOSED, true],
        [CsTicket::STATUS_WAITING_USER, CsTicket::STATUS_COMPLETED, false],

        [CsTicket::STATUS_COMPLETED, CsTicket::STATUS_CLOSED, true],
        [CsTicket::STATUS_COMPLETED, CsTicket::STATUS_PROCESSING, true], // 用户追问重新打开

        [CsTicket::STATUS_CLOSED, CsTicket::STATUS_PROCESSING, false],
        [CsTicket::STATUS_CLOSED, CsTicket::STATUS_PENDING, false],
    ];

    foreach ($matrix as [$from, $to, $allowed]) {
        $ticket = new CsTicket(['status' => $from]);

        expect($ticket->canTransitTo($to))->toBe($allowed, "{$from} → {$to} 期望 ".var_export($allowed, true));
    }
});

it('非法流转调用 assertTransitable 抛出 40009 业务冲突', function () {
    $ticket = new CsTicket(['status' => CsTicket::STATUS_CLOSED]);

    try {
        $ticket->assertTransitable(CsTicket::STATUS_PROCESSING);
        $this->fail('期望抛出业务冲突异常');
    } catch (BusinessException $e) {
        expect($e->businessCode)->toBe(40009);
    }
});

it('closed 为终态，无任何可流转目标', function () {
    expect(CsTicket::TRANSITIONS[CsTicket::STATUS_CLOSED])->toBe([]);
});

it('状态标签覆盖全部状态', function () {
    foreach (array_keys(CsTicket::TRANSITIONS) as $status) {
        expect(CsTicket::STATUS_LABELS)->toHaveKey($status);
    }
});

it('状态默认值为 pending，优先级默认值为 0', function () {
    $type = CsTicketType::first();
    $ticket = CsTicket::create([
        'ticket_no' => 'TK20260917000099',
        'user_id' => 1,
        'type_id' => $type->id,
        'title' => '默认状态校验',
        'content' => '内容',
    ])->refresh();

    expect($ticket->status)->toBe(CsTicket::STATUS_PENDING)
        ->and($ticket->priority)->toBe(CsTicket::PRIORITY_NORMAL);
});

// ---------- 可见性判定 ----------

it('已关闭工单不可回复也不可关闭，其余状态均可', function () {
    expect((new CsTicket(['status' => CsTicket::STATUS_CLOSED]))->canReply())->toBeFalse()
        ->and((new CsTicket(['status' => CsTicket::STATUS_CLOSED]))->canClose())->toBeFalse()
        ->and((new CsTicket(['status' => CsTicket::STATUS_COMPLETED]))->canReply())->toBeTrue()
        ->and((new CsTicket(['status' => CsTicket::STATUS_PENDING]))->canClose())->toBeTrue();
});

// ---------- 模型 ----------

it('消息模型 casts 生效：images 数组、is_internal 布尔', function () {
    $message = new CsTicketMessage([
        'images' => ['/storage/cs/a.png'],
        'is_internal' => 1,
    ]);

    expect($message->images)->toBeArray()
        ->and($message->images)->toBe(['/storage/cs/a.png'])
        ->and($message->is_internal)->toBeTrue();
});

it('消息发送方常量与标签完整', function () {
    expect(CsTicketMessage::SENDER_LABELS)
        ->toHaveKeys([CsTicketMessage::SENDER_USER, CsTicketMessage::SENDER_STAFF, CsTicketMessage::SENDER_SYSTEM]);
});

it('文章有帮助率在无反馈时为 null，有反馈时按比例计算', function () {
    $empty = new CsFaqArticle(['helpful_count' => 0, 'unhelpful_count' => 0]);
    $rated = new CsFaqArticle(['helpful_count' => 3, 'unhelpful_count' => 1]);

    expect($empty->helpfulRate())->toBeNull()
        ->and($rated->helpfulRate())->toBe(0.75);
});

// ---------- 默认数据种子 ----------

it('默认工单类型为 8 个，且物流/质量/退换货必须关联订单', function () {
    expect(CsTicketType::query()->count())->toBe(8);

    foreach (['logistics', 'quality', 'return'] as $code) {
        expect(CsTicketType::where('code', $code)->value('require_order'))->toBeTrue();
    }

    foreach (['pre_sale', 'payment', 'account', 'complaint', 'other'] as $code) {
        expect(CsTicketType::where('code', $code)->value('require_order'))->toBeFalse();
    }
});

it('工单类型种子幂等：重复执行不产生重复记录', function () {
    $this->seed(\Database\Seeders\CsTicketTypeSeeder::class);
    $this->seed(\Database\Seeders\CsTicketTypeSeeder::class);

    expect(CsTicketType::query()->count())->toBe(8);
});

it('默认帮助中心分类为 5 个且每类一篇草稿文章', function () {
    // CMS-101 起迁移另播种 2 个单页栏目（type=page，关于我们/联系我们），故按类型分别断言
    // CMS-204 起迁移再播种 1 个「公告」承载栏目：它是 channel，但不属于帮助中心类目，
    // 故这里按名字把它排除后再数（8 = 5 帮助分类 + 2 单页 + 1 公告承载栏目）。
    expect(CsFaqCategory::where('type', CsFaqCategory::TYPE_CHANNEL)
        ->where('name', '!=', CsFaqCategory::ANNOUNCEMENT_CATEGORY_NAME)->count())->toBe(5)
        ->and(CsFaqCategory::query()->count())->toBe(8)
        ->and(CsFaqArticle::query()->count())->toBe(5)
        ->and(CsFaqArticle::where('status', CsFaqArticle::STATUS_PUBLISHED)->count())->toBe(0);
});

it('分类种子幂等：重复执行不产生重复记录', function () {
    $this->seed(\Database\Seeders\CsFaqCategorySeeder::class);

    expect(CsFaqCategory::where('type', CsFaqCategory::TYPE_CHANNEL)
        ->where('name', '!=', CsFaqCategory::ANNOUNCEMENT_CATEGORY_NAME)->count())->toBe(5)
        ->and(CsFaqCategory::query()->count())->toBe(8)
        ->and(CsFaqArticle::query()->count())->toBe(5);
});

it('模型关联可用：工单 → 类型 / 消息 / 用户 / 订单', function () {
    $type = CsTicketType::where('code', 'pre_sale')->first();
    $ticket = CsTicket::create([
        'ticket_no' => 'TK20260917000001',
        'user_id' => 1,
        'type_id' => $type->id,
        'title' => '咨询商品',
        'content' => '请问有货吗',
    ]);
    $ticket->messages()->create([
        'sender_type' => CsTicketMessage::SENDER_USER,
        'sender_id' => 1,
        'content' => '请问有货吗',
    ]);

    expect($ticket->type->code)->toBe('pre_sale')
        ->and($ticket->messages)->toHaveCount(1)
        ->and($ticket->messages->first()->content)->toBe('请问有货吗')
        ->and($ticket->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($ticket->order()->getRelated())->toBeInstanceOf(\App\Models\Order::class);
});
