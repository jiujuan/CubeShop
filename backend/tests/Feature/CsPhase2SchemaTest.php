<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * CS-201 二期数据扩展 —— schema 核对
 *
 * 固化「建表 SQL + 字段核对表」中可断言的部分：
 * 1. 两张新表（cs_quick_reply / cs_announcement）字段、默认值与索引；
 * 2. 决策 D8：服务配置复用 system_configs（`cs.` 前缀），**不得**存在第二套 cs_service_config；
 * 3. cs_ticket 满意度与时间锚点字段逐项核对（一期已建，本任务只核对）；
 * 4. 迁移可回滚、可重复执行。
 */
const MIGRATION_CS201 = 'migrations/2026_09_17_000045_create_cs_quick_reply_and_announcement_tables.php';

/** 取某表的索引（跨库，DB 无关） */
function cs201Indexes(string $table): array
{
    return Schema::getIndexes($table);
}

function cs201HasIndexOn(string $table, array $columns): bool
{
    foreach (cs201Indexes($table) as $index) {
        if (array_values($index['columns']) === $columns) {
            return true;
        }
    }

    return false;
}

// ---------- 表与字段 ----------

it('CS-201 两张新表齐全', function () {
    foreach (['cs_quick_reply', 'cs_announcement'] as $t) {
        expect(Schema::hasTable($t))->toBeTrue("表 {$t} 缺失");
    }
});

it('CS-201 cs_quick_reply 字段齐备且 type_id 可空', function () {
    foreach (['id', 'type_id', 'title', 'content', 'sort', 'created_by', 'updated_by',
        'created_at', 'updated_at'] as $c) {
        expect(Schema::hasColumn('cs_quick_reply', $c))->toBeTrue("cs_quick_reply.{$c} 缺失");
    }

    DB::table('cs_quick_reply')->insert([
        'type_id' => null, 'title' => '通用模板', 'content' => '您好，请问有什么可以帮您？',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = DB::table('cs_quick_reply')->where('title', '通用模板')->first();

    expect($row)->not->toBeNull()
        ->and($row->type_id)->toBeNull()
        ->and((int) $row->sort)->toBe(0, 'sort 默认值应为 0');
});

it('CS-201 cs_announcement 字段齐备且默认 draft / is_top=false', function () {
    foreach (['id', 'title', 'content', 'is_top', 'status', 'published_at', 'created_by',
        'created_at', 'updated_at'] as $c) {
        expect(Schema::hasColumn('cs_announcement', $c))->toBeTrue("cs_announcement.{$c} 缺失");
    }

    DB::table('cs_announcement')->insert([
        'title' => '服务时间调整', 'content' => '国庆期间客服在线时间 9:00-18:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = DB::table('cs_announcement')->where('title', '服务时间调整')->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('draft', 'status 默认值应为 draft')
        ->and($row->published_at)->toBeNull()
        ->and((int) $row->is_top)->toBe(0, 'is_top 默认值应为 false');
});

// ---------- 索引 ----------

it('CS-201 索引符合设计（快捷回复 [type_id,sort] / 公告 [status,is_top,published_at]）', function () {
    expect(cs201HasIndexOn('cs_quick_reply', ['type_id', 'sort']))->toBeTrue('cs_quick_reply 缺 [type_id,sort] 索引');
    expect(cs201HasIndexOn('cs_announcement', ['status', 'is_top', 'published_at']))->toBeTrue('cs_announcement 缺 [status,is_top,published_at] 索引');
});

// ---------- 决策 D8 ----------

it('CS-201 决策 D8：服务配置复用 system_configs，且不存在第二套 cs_service_config', function () {
    // 承载表存在且 config_key 唯一
    expect(Schema::hasTable('system_configs'))->toBeTrue('system_configs 缺失，D8 需重新决策');
    expect(cs201HasIndexOn('system_configs', ['config_key']))->toBeTrue('system_configs.config_key 缺唯一索引');

    // cs. 前缀 key 可写可读（CS-207 将以此口径读写服务配置）
    DB::table('system_configs')->insert([
        'config_key' => 'cs.auto_close_hours',
        'config_value' => '72',
        'description' => '工单超时自动关闭小时数',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('system_configs')->where('config_key', 'cs.auto_close_hours')->value('config_value'))
        ->toBe('72');

    // 反例守卫：不得再建第二套配置表（否则两处配置并存、口径分叉）
    expect(Schema::hasTable('cs_service_config'))->toBeFalse('禁止新增 cs_service_config（决策 D8：配置统一走 system_configs）');
});

it('CS-201 system_configs.config_key 唯一约束生效（防重复配置）', function () {
    $row = [
        'config_key' => 'cs.satisfaction_enabled',
        'config_value' => '1',
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('system_configs')->insert($row);

    expect(fn () => DB::table('system_configs')->insert($row))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

// ---------- cs_ticket 字段核对 ----------

it('CS-201 cs_ticket 满意度与时间锚点字段齐备（对照一期 CS-101 清单）', function () {
    // 满意度（CS-205 依赖） + 时间锚点（CS-209 自动关闭、CS-210 看板依赖）
    foreach (['satisfaction', 'satisfaction_remark', 'satisfaction_at',
        'first_replied_at', 'completed_at', 'closed_at', 'close_reason'] as $c) {
        expect(Schema::hasColumn('cs_ticket', $c))->toBeTrue("cs_ticket.{$c} 缺失");
    }
});

it('CS-201 cs_ticket 满意度字段可读写（1-5 与留言、时间）', function () {
    $typeId = DB::table('cs_ticket_type')->insertGetId([
        'name' => '售后', 'code' => 'after_sale', 'require_order' => false, 'sort' => 0,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('cs_ticket')->insert([
        'ticket_no' => 'TK-CS201-0001', 'user_id' => 1, 'type_id' => $typeId,
        'title' => 'T', 'content' => 'C', 'status' => 'completed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $at = now();
    DB::table('cs_ticket')->where('ticket_no', 'TK-CS201-0001')->update([
        'satisfaction' => 5,
        'satisfaction_remark' => '客服很耐心',
        'satisfaction_at' => $at,
    ]);

    $row = DB::table('cs_ticket')->where('ticket_no', 'TK-CS201-0001')->first();

    expect((int) $row->satisfaction)->toBe(5)
        ->and($row->satisfaction_remark)->toBe('客服很耐心')
        ->and($row->satisfaction_at)->not->toBeNull();
});

// ---------- 迁移可回滚 / 可重复执行 ----------

it('CS-201 迁移可回滚且可重复执行，既有 cs_ticket 数据不受影响', function () {
    $typeId = DB::table('cs_ticket_type')->insertGetId([
        'name' => '咨询', 'code' => 'consult', 'require_order' => false, 'sort' => 0,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('cs_ticket')->insert([
        'ticket_no' => 'TK-CS201-KEEP', 'user_id' => 1, 'type_id' => $typeId,
        'title' => '留存校验', 'content' => 'C', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /** @var object{up: callable, down: callable} $migration */
    $migration = require database_path(MIGRATION_CS201);

    // down 删除两表
    $migration->down();
    expect(Schema::hasTable('cs_quick_reply'))->toBeFalse()
        ->and(Schema::hasTable('cs_announcement'))->toBeFalse();

    // 既有工单仍在（新增表不影响一期数据）
    expect(DB::table('cs_ticket')->where('ticket_no', 'TK-CS201-KEEP')->exists())->toBeTrue();

    // 重复执行 down（幂等）后重新 up
    $migration->down();
    $migration->up();
    expect(Schema::hasTable('cs_quick_reply'))->toBeTrue()
        ->and(Schema::hasTable('cs_announcement'))->toBeTrue();
});
