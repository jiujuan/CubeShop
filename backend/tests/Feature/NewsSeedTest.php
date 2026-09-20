<?php

use App\Models\CsFaqCategory;
use App\Services\Cms\CmsCategoryService;
use App\Support\CmsListStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * CMS 新闻中心（一期）：迁移 000103 播种「新闻中心」根 + 两个子栏目（幂等）
 */
it('新闻中心根与两个子栏目已播种且 slug 唯一', function () {
    expect(CsFaqCategory::where('slug', 'news')->count())->toBe(1);
    expect(CsFaqCategory::where('slug', 'news-graphic')->count())->toBe(1);
    expect(CsFaqCategory::where('slug', 'news-list')->count())->toBe(1);

    $root = CsFaqCategory::where('slug', 'news')->firstOrFail();
    expect($root->list_style)->toBe(CmsListStyle::LIST);
    expect($root->type)->toBe(CsFaqCategory::TYPE_CHANNEL);

    $graphic = CsFaqCategory::where('slug', 'news-graphic')->firstOrFail();
    expect($graphic->list_style)->toBe(CmsListStyle::CARD);
    expect($graphic->parent_id)->toBe($root->id);

    $list = CsFaqCategory::where('slug', 'news-list')->firstOrFail();
    expect($list->list_style)->toBe(CmsListStyle::LIST);
    expect($list->parent_id)->toBe($root->id);
});

it('种子幂等：重复执行 up 不会重复建栏目（同 slug 被唯一校验拒绝）', function () {
    $service = app(CmsCategoryService::class);

    expect(fn () => $service->create([
        'name' => '新闻中心', 'parent_id' => 0, 'type' => 'channel', 'slug' => 'news',
    ]))->toThrow(ValidationException::class);

    // 根仍唯一（不会因二次播种出现第二个）
    expect(CsFaqCategory::where('slug', 'news')->count())->toBe(1);
    expect(CsFaqCategory::query()->whereIn('slug', ['news', 'news-graphic', 'news-list'])->count())->toBe(3);
});
