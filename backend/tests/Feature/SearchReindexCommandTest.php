<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SystemConfig;
use App\Support\Search\SearchConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 检索索引重建命令（站内搜索 S1-08）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.2 / §4.6
 *
 * 这条命令是索引的**唯一全量兜底**：同步钩子（`Product::saving` / 品牌分类级联）只覆盖
 * 「走应用层写入」的路径，而直接改库、批量导入、迁移回填漏行、开关从关改开都会留下陈旧索引。
 * 因此它的两条硬性质必须钉死：
 *
 * 1. **幂等** —— 重复执行结果一致（第二次 `updated = 0`），中途失败可直接重跑；
 * 2. **只写变化行** —— 否则万级商品每天全表 UPDATE，白白放大 WAL 与复制延迟。
 *
 * 另有两条容易忽略的：`--no-bump` 用于「只刷索引不清缓存」，
 * 以及每日 03:40 的调度必须真的注册进 Schedule（注册不上就等于没有兜底）。
 */
beforeEach(function () {
    Cache::flush();

    $this->category = Category::create([
        'parent_id' => 0, 'name' => '家具', 'sort' => 0, 'status' => 1,
    ]);
    $this->brand = Brand::create(['name' => '北欧良品', 'sort' => 0, 'status' => 1]);

    $this->makeProduct = fn (string $title): Product => Product::create([
        'category_id' => $this->category->id,
        'brand_id' => $this->brand->id,
        'title' => $title,
        'price' => '100.00',
        'status' => 1,
    ]);

    /** 模拟绕过模型 save 的写入（批量导入 / 直接改库）留下的陈旧索引 */
    $this->stale = function (): void {
        DB::table('products')->update(['search_title' => null, 'search_body' => null]);
    };
});

test('TC-SEARCH-S1-08-CMD-001 全量重建回填陈旧索引列，并递增 index_version', function () {
    ($this->makeProduct)('北欧实木沙发');
    ($this->makeProduct)('不锈钢保温杯');
    ($this->stale)();

    expect(DB::table('products')->whereNotNull('search_title')->count())->toBe(0);

    $this->artisan('search:reindex')
        ->expectsOutputToContain('检索索引重建完成：扫描 2 条，写回 2 条')
        ->assertExitCode(0);

    // 索引列回填：标题域含 bigram 切片（`沙发` 必须能命中）
    $rows = DB::table('products')->pluck('search_title')->all();

    expect(DB::table('products')->whereNotNull('search_title')->count())->toBe(2)
        ->and(implode('|', $rows))->toContain('沙发')
        // 品牌名与分类名进正文域（开关默认开）
        ->and(DB::table('products')->value('search_body'))->toContain('家具');

    expect((int) SystemConfig::where('config_key', 'search.index_version')->value('config_value'))->toBe(2);
});

test('TC-SEARCH-S1-08-CMD-002 幂等：第二次执行写回 0 条，索引内容逐字节一致', function () {
    ($this->makeProduct)('北欧实木沙发');
    ($this->makeProduct)('不锈钢保温杯');
    ($this->stale)();

    $this->artisan('search:reindex')->assertExitCode(0);
    $first = DB::table('products')->orderBy('id')->pluck('search_title', 'id')->all();
    $bodyFirst = DB::table('products')->orderBy('id')->pluck('search_body', 'id')->all();

    $this->artisan('search:reindex')
        ->expectsOutputToContain('扫描 2 条，写回 0 条')
        ->assertExitCode(0);

    expect(DB::table('products')->orderBy('id')->pluck('search_title', 'id')->all())->toBe($first)
        ->and(DB::table('products')->orderBy('id')->pluck('search_body', 'id')->all())->toBe($bodyFirst);
});

test('TC-SEARCH-S1-08-CMD-003 --ids 只重建指定商品，其余保持原样', function () {
    $target = ($this->makeProduct)('北欧实木沙发');
    $other = ($this->makeProduct)('不锈钢保温杯');
    ($this->stale)();

    $this->artisan('search:reindex', ['--ids' => (string) $target->id])
        ->expectsOutputToContain('扫描 1 条，写回 1 条')
        ->assertExitCode(0);

    expect(DB::table('products')->where('id', $target->id)->value('search_title'))->toBeString()
        ->and(DB::table('products')->where('id', $target->id)->value('search_title'))->toContain('沙发')
        // 未指定的商品仍是被清空的状态，证明 --ids 真的收敛了范围
        ->and(DB::table('products')->where('id', $other->id)->value('search_title'))->toBeNull();
});

test('TC-SEARCH-S1-08-CMD-004 --ids 全是非法值时友好退出，不误伤全量', function () {
    ($this->makeProduct)('北欧实木沙发');
    ($this->stale)();

    $this->artisan('search:reindex', ['--ids' => 'abc,-3,'])
        ->expectsOutputToContain('--ids 未解析出有效 id')
        ->assertExitCode(0);

    // 关键：不能因为「解析不出 id」就退化成全量重建
    expect(DB::table('products')->whereNotNull('search_title')->count())->toBe(0);
});

test('TC-SEARCH-S1-08-CMD-005 --no-bump 只刷索引不动 index_version（保留命中缓存）', function () {
    ($this->makeProduct)('北欧实木沙发');
    ($this->stale)();

    app(SearchConfig::class)->bumpIndexVersion();   // 先变成 2

    $this->artisan('search:reindex', ['--no-bump' => true])->assertExitCode(0);

    expect((int) SystemConfig::where('config_key', 'search.index_version')->value('config_value'))->toBe(2)
        ->and(DB::table('products')->whereNotNull('search_title')->count())->toBe(1);
});

test('TC-SEARCH-S1-08-CMD-006 软删商品也在重建范围内（恢复上架即用，不必再等一轮）', function () {
    $product = ($this->makeProduct)('北欧实木沙发');
    $product->delete();
    ($this->stale)();

    $this->artisan('search:reindex')
        ->expectsOutputToContain('扫描 1 条，写回 1 条')
        ->assertExitCode(0);

    expect(DB::table('products')->where('id', $product->id)->value('search_title'))->toContain('沙发');
});

test('TC-SEARCH-S1-08-CMD-007 每日 03:40 校准任务确实注册进 Schedule', function () {
    // routes/console.php 只在 ConsoleKernel 被解析并 boot 后才加载，
    // 所以必须先真正跑一条 artisan 命令，再去读 Schedule
    $this->artisan('list')->assertExitCode(0);

    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'search:reindex'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('40 3 * * *');

    // 防重叠：全量重建是长任务，跑得慢时不能叠第二个实例
    expect($events->first()->withoutOverlapping)->toBeTrue();

    // 顺带确认命令确实被 Artisan 发现（否则调度注册的只是一个不存在的名字）
    expect(collect(Artisan::all())->has('search:reindex'))->toBeTrue();
});
