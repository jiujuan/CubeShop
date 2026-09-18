<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Services\Cs\FaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-104：FaqService 单元测试
 */
beforeEach(function () {
    $this->service = app(FaqService::class);

    $this->cat = CsFaqCategory::create(['name' => '购物指南', 'sort' => 1, 'is_active' => true]);
    $this->catInactive = CsFaqCategory::create(['name' => '隐藏分类', 'sort' => 2, 'is_active' => false]);
});

it('关键词归一化：去空格并截断到 50 字', function () {
    expect($this->service->normalizeKeyword('  退款  '))->toBe('退款')
        ->and($this->service->normalizeKeyword(''))->toBeNull()
        ->and($this->service->normalizeKeyword(null))->toBeNull()
        ->and(mb_strlen($this->service->normalizeKeyword(str_repeat('测', 80))))->toBe(50);
});

it('只返回已发布文章', function () {
    CsFaqArticle::create(['category_id' => $this->cat->id, 'title' => 'A', 'content' => '草稿内容', 'status' => CsFaqArticle::STATUS_DRAFT]);
    CsFaqArticle::create(['category_id' => $this->cat->id, 'title' => 'B', 'content' => '下架内容', 'status' => CsFaqArticle::STATUS_OFFLINE]);
    CsFaqArticle::create(['category_id' => $this->cat->id, 'title' => 'C', 'content' => '已发布内容', 'status' => CsFaqArticle::STATUS_PUBLISHED]);

    $result = $this->service->articles($this->cat->id, null, 10);

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->title)->toBe('C');
});

it('分类排序按 sort 升序、激活在前', function () {
    CsFaqCategory::create(['name' => '账户安全', 'sort' => 0, 'is_active' => true]);

    $categories = $this->service->categories();

    expect($categories)->toHaveCount(2) // 两个激活分类
        ->and($categories->first()->name)->toBe('账户安全'); // sort=0 排最前
});

it('浏览量自增用 increment，并发读改写不丢', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->cat->id, 'title' => '并发', 'content' => '并发正文', 'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    for ($i = 0; $i < 10; $i++) {
        $this->service->detail($article->id);
    }

    expect($article->fresh()->view_count)->toBe(10);
});

it('反馈分别累加 helpful / unhelpful', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->cat->id, 'title' => '反馈', 'content' => '反馈正文', 'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $this->service->feedback($article->id, true);
    $this->service->feedback($article->id, false);
    $this->service->feedback($article->id, false);

    $fresh = $article->fresh();
    expect($fresh->helpful_count)->toBe(1)
        ->and($fresh->unhelpful_count)->toBe(2);
});
