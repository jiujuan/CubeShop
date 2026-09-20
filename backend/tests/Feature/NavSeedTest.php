<?php

use App\Models\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 迁移 000100 的初始播种（导航可管理化）
 *
 * 上线时不能让前台导航变空：要把「原本硬编码的导航」迁进来 ——
 * 一级分类按展示顺序登记为引用型，硬编码的「热销推荐」登记为自定义项。
 * 「首页」不登记（永远在第一位、永远存在，硬编码最省）。
 */
test('TC-NAV-040 播种了热销推荐，且排在最前', function () {
    $items = NavItem::query()->orderByDesc('sort')->orderBy('id')->get();

    expect($items)->not->toBeEmpty()
        ->and($items->first()->type)->toBe(NavItem::TYPE_CUSTOM)
        ->and($items->first()->title)->toBe('热销推荐')
        ->and($items->first()->url)->toBe('/search?sort=sales_desc');
});

test('TC-NAV-041 播种不包含「首页」', function () {
    expect(NavItem::where('title', '首页')->exists())->toBeFalse();
});

test('TC-NAV-042 播种的分类条目按分类 sort 顺序登记', function () {
    $categoryItems = NavItem::query()
        ->where('type', NavItem::TYPE_CATEGORY)
        ->orderByDesc('sort')
        ->orderBy('id')
        ->get();

    $expected = App\Models\Category::query()
        ->where('status', 1)
        ->where('parent_id', 0)
        ->orderByDesc('sort')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($categoryItems->pluck('category_id')->map(fn ($id) => (int) $id)->all())->toBe($expected);
});

test('TC-NAV-043 播种后公开接口首项即热销推荐', function () {
    $this->getJson('/api/nav')->assertOk()
        ->assertJsonPath('data.0.title', '热销推荐');
});
