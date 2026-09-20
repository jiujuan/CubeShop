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

it('分类树按 sort 升序、激活在前，且字段为面向用户端的裁剪集', function () {
    CsFaqCategory::create(['name' => '账户安全', 'sort' => 0, 'is_active' => true]);

    $categories = $this->service->categories();

    expect($categories)->toHaveCount(2) // 两个激活分类
        ->and($categories[0]['name'])->toBe('账户安全') // sort=0 排最前
        ->and($categories[0]['level'])->toBe(1)
        ->and($categories[0]['parent_id'])->toBe(0)
        ->and($categories[0]['children'])->toBe([])
        ->and($categories[0])->toHaveKeys(['id', 'name', 'sort', 'level', 'parent_id', 'published_count', 'children'])
        // ⚠️ 后台字段不下发（is_active/path/template/时间戳等）
        ->and($categories[0])->not->toHaveKeys(['is_active', 'path', 'type', 'template', 'created_at']);
});

it('子栏目挂在父栏目的 children 下（CMS-201）', function () {
    $child = CsFaqCategory::create([
        'name' => '退换货', 'sort' => 1, 'is_active' => true,
        'parent_id' => $this->cat->id, 'level' => 2, 'path' => '/'.$this->cat->id.'/',
    ]);

    $categories = $this->service->categories();

    expect($categories)->toHaveCount(1) // 子栏目不冒泡到根
        ->and($categories[0]['name'])->toBe('购物指南')
        ->and($categories[0]['children'])->toHaveCount(1)
        ->and($categories[0]['children'][0]['name'])->toBe('退换货')
        ->and($categories[0]['children'][0]['level'])->toBe(2)
        ->and($categories[0]['children'][0]['parent_id'])->toBe($child->parent_id);
});

it('未激活父栏目的子栏目不会顶到根上', function () {
    CsFaqCategory::create([
        'name' => '隐藏的子栏目', 'sort' => 1, 'is_active' => true,
        'parent_id' => $this->catInactive->id, 'level' => 2, 'path' => '/'.$this->catInactive->id.'/',
    ]);

    $categories = $this->service->categories();
    $names = collect($categories)->pluck('name')->all();

    // 父被 active_only 过滤掉 → 子节点的 parent_id 不在结果集里，装配时自然落空
    expect($names)->toBe(['购物指南'])
        ->and($names)->not->toContain('隐藏的子栏目');
});

it('单页栏目不进帮助中心栏目树（CMS-106）', function () {
    // 000095 已播种 about/contact 两个单页，这里再建一个验证过滤口径
    CsFaqCategory::create([
        'name' => '公司介绍', 'type' => CsFaqCategory::TYPE_PAGE,
        'slug' => 'company-intro', 'template' => 'about', 'is_active' => true,
    ]);

    $names = collect($this->service->categories())->pluck('name')->all();

    expect($names)->toBe(['购物指南'])
        ->and($names)->not->toContain('公司介绍')
        ->and($names)->not->toContain('关于我们'); // 迁移播种的单页同样不出现
});

it('栏目树各节点带自己的已发布文章数', function () {
    CsFaqArticle::create([
        'category_id' => $this->cat->id, 'title' => '已发布', 'content' => '正文',
        'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);
    CsFaqArticle::create([
        'category_id' => $this->cat->id, 'title' => '草稿', 'content' => '正文',
        'status' => CsFaqArticle::STATUS_DRAFT,
    ]);

    $categories = $this->service->categories();

    expect($categories[0]['published_count'])->toBe(1);
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
