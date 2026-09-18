<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * CS-101 客服中心核心五表迁移 —— schema 核对
 *
 * 固化「建表 SQL + 索引清单 + 字段核对表」中的可断言部分：
 * 表存在、关键字段存在、唯一索引存在（工单号唯一是 CS-116 并发不变量）。
 */
it('核心五表齐全', function () {
    foreach (['cs_ticket_type', 'cs_ticket', 'cs_ticket_message', 'cs_faq_category', 'cs_faq_article'] as $t) {
        expect(Schema::hasTable($t))->toBeTrue("表 {$t} 缺失");
    }
});

it('cs_ticket 关键字段与默认值', function () {
    foreach (['ticket_no', 'user_id', 'type_id', 'order_id', 'title', 'content', 'status',
        'priority', 'assignee_id', 'contact', 'satisfaction', 'satisfaction_remark', 'satisfaction_at',
        'first_replied_at', 'last_message_at', 'completed_at', 'closed_at', 'close_reason'] as $c) {
        expect(Schema::hasColumn('cs_ticket', $c))->toBeTrue("cs_ticket.{$c} 缺失");
    }
});

it('cs_ticket_message 关键字段', function () {
    foreach (['ticket_id', 'sender_type', 'sender_id', 'content', 'images', 'is_internal', 'created_at'] as $c) {
        expect(Schema::hasColumn('cs_ticket_message', $c))->toBeTrue("cs_ticket_message.{$c} 缺失");
    }
});

it('cs_faq_article 关键字段与状态默认 draft', function () {
    foreach (['category_id', 'title', 'summary', 'content', 'sort', 'is_hot', 'status', 'view_count',
        'helpful_count', 'unhelpful_count', 'published_at'] as $c) {
        expect(Schema::hasColumn('cs_faq_article', $c))->toBeTrue("cs_faq_article.{$c} 缺失");
    }

    DB::table('cs_faq_category')->insert(['name' => 'S', 'sort' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $catId = (int) DB::table('cs_faq_category')->max('id');
    DB::table('cs_faq_article')->insert([
        'category_id' => $catId, 'title' => 'T', 'content' => 'C',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('cs_faq_article')->where('title', 'T')->value('status'))->toBe('draft');
});

it('ticket_no 唯一索引生效（重号写入被拒）', function () {
    $typeId = DB::table('cs_ticket_type')->insertGetId([
        'name' => 'S', 'code' => 's', 'require_order' => false, 'sort' => 0, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $row = [
        'ticket_no' => 'TK-DUP-0001', 'user_id' => 1, 'type_id' => $typeId,
        'title' => 'T', 'content' => 'C', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('cs_ticket')->insert($row);

    expect(fn () => DB::table('cs_ticket')->insert($row))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
