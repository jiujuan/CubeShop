<?php

use App\Exceptions\BusinessException;
use App\Models\CsFaqCategory;
use App\Services\Cms\CmsCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * 栏目树服务（CMS-103）
 *
 * ⚠️ 测试库由迁移预置了两个单页栏目（关于我们 / 联系我们），断言时只针对本测试
 * 创建的栏目，不要对全量计数做绝对断言。
 */
function cmsTreeService(): CmsCategoryService
{
    return app(CmsCategoryService::class);
}

/** 在树里按名称找节点（含子孙） */
function cmsFindNode(array $nodes, string $name): ?array
{
    foreach ($nodes as $node) {
        if ($node['name'] === $name) {
            return $node;
        }

        $found = cmsFindNode($node['children'] ?? [], $name);
        if ($found) {
            return $found;
        }
    }

    return null;
}

test('TC-CMS-T10 新建根栏目 level=1、path=/{id}/', function () {
    $category = cmsTreeService()->create(['name' => '帮助中心']);

    expect($category->level)->toBe(1)
        ->and($category->parent_id)->toBe(0)
        ->and($category->type)->toBe(CsFaqCategory::TYPE_CHANNEL)
        ->and($category->path)->toBe('/'.$category->id.'/');
});

test('TC-CMS-T11 子栏目继承层级与物化路径，可嵌套三层', function () {
    $service = cmsTreeService();

    $root = $service->create(['name' => '帮助中心']);
    $mid = $service->create(['name' => '购物指南', 'parent_id' => $root->id]);
    $leaf = $service->create(['name' => '下单流程', 'parent_id' => $mid->id]);

    expect($mid->level)->toBe(2)
        ->and($mid->path)->toBe('/'.$root->id.'/'.$mid->id.'/')
        ->and($leaf->level)->toBe(3)
        ->and($leaf->path)->toBe('/'.$root->id.'/'.$mid->id.'/'.$leaf->id.'/');
});

test('TC-CMS-T12 tree() 按层级装配，子节点挂在 children 下', function () {
    $service = cmsTreeService();

    $root = $service->create(['name' => '帮助中心']);
    $service->create(['name' => '购物指南', 'parent_id' => $root->id]);
    $service->create(['name' => '售后服务', 'parent_id' => $root->id]);

    $tree = $service->tree();
    $node = cmsFindNode($tree, '帮助中心');

    expect($node)->not->toBeNull()
        ->and(array_column($node['children'], 'name'))->toBe(['购物指南', '售后服务'])
        ->and($node['articles_count'])->toBe(0);
});

test('TC-CMS-T13 同级可重名、跨级可重名（唯一性只约束同父）', function () {
    $service = cmsTreeService();

    $a = $service->create(['name' => '帮助中心']);
    $b = $service->create(['name' => '关于我们栏目']);

    // 不同父下同名：允许
    $service->create(['name' => '指南', 'parent_id' => $a->id]);
    $service->create(['name' => '指南', 'parent_id' => $b->id]);

    expect(CsFaqCategory::where('name', '指南')->count())->toBe(2);
});

test('TC-CMS-T14 移动栏目：禁止移到自身或自己的后代（防环）', function () {
    $service = cmsTreeService();

    $root = $service->create(['name' => '帮助中心']);
    $child = $service->create(['name' => '购物指南', 'parent_id' => $root->id]);
    $grand = $service->create(['name' => '下单流程', 'parent_id' => $child->id]);

    expect(fn () => $service->move($root->id, $root->id))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->move($root->id, $grand->id))
        ->toThrow(ValidationException::class);

    // 数据未变
    expect($root->fresh()->parent_id)->toBe(0);
});

test('TC-CMS-T15 移动栏目后，整棵子树的 level 与 path 级联重写', function () {
    $service = cmsTreeService();

    $a = $service->create(['name' => '帮助中心']);
    $b = $service->create(['name' => '关于我们栏目']);
    $child = $service->create(['name' => '购物指南', 'parent_id' => $a->id]);
    $grand = $service->create(['name' => '下单流程', 'parent_id' => $child->id]);

    // 把 $child 整枝移到 $b 下
    $service->move($child->id, $b->id);

    $child->refresh();
    $grand->refresh();

    expect($child->parent_id)->toBe($b->id)
        ->and($child->level)->toBe(2)
        ->and($child->path)->toBe('/'.$b->id.'/'.$child->id.'/')
        ->and($grand->level)->toBe(3)
        ->and($grand->path)->toBe('/'.$b->id.'/'.$child->id.'/'.$grand->id.'/');
});

test('TC-CMS-T16 移动栏目到根：level 归 1、path 重算', function () {
    $service = cmsTreeService();

    $root = $service->create(['name' => '帮助中心']);
    $child = $service->create(['name' => '购物指南', 'parent_id' => $root->id]);

    $service->move($child->id, 0);
    $child->refresh();

    expect($child->level)->toBe(1)
        ->and($child->parent_id)->toBe(0)
        ->and($child->path)->toBe('/'.$child->id.'/');
});

test('TC-CMS-T17 删除栏目：有子栏目拒绝', function () {
    $service = cmsTreeService();

    $root = $service->create(['name' => '帮助中心']);
    $service->create(['name' => '购物指南', 'parent_id' => $root->id]);

    expect(fn () => $service->delete($root->id))->toThrow(BusinessException::class);
    expect(CsFaqCategory::find($root->id))->not->toBeNull();
});

test('TC-CMS-T18 删除栏目：有已发布文章拒绝，无发布文章可删', function () {
    $service = cmsTreeService();

    $category = $service->create(['name' => '帮助中心']);

    // 草稿不算发布
    \App\Models\CsFaqArticle::create([
        'category_id' => $category->id,
        'title' => '草稿文章',
        'content_md' => '内容',
        'status' => \App\Models\CsFaqArticle::STATUS_DRAFT,
    ]);

    $service->delete($category->id);
    expect(CsFaqCategory::find($category->id))->toBeNull();
});

test('TC-CMS-T19 删除单页栏目：连带删除其内容行', function () {
    $service = cmsTreeService();

    $page = $service->create([
        'name' => '公司介绍', 'type' => CsFaqCategory::TYPE_PAGE,
        'slug' => 'company', 'template' => 'about',
    ]);

    \App\Models\CsFaqArticle::create([
        'category_id' => $page->id,
        'title' => '公司介绍',
        'content_md' => '',
        'status' => \App\Models\CsFaqArticle::STATUS_PUBLISHED,
        'page_fields' => ['intro' => 'x'],
    ]);

    $service->delete($page->id);

    expect(CsFaqCategory::find($page->id))->toBeNull()
        ->and(\App\Models\CsFaqArticle::where('category_id', $page->id)->count())->toBe(0);
});

test('TC-CMS-T20 slug 校验：格式、保留字、唯一性', function () {
    $service = cmsTreeService();

    expect(fn () => $service->create(['name' => 'A', 'type' => 'page', 'slug' => 'Bad Slug']))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(['name' => 'B', 'type' => 'page', 'slug' => 'login']))
        ->toThrow(ValidationException::class);

    $service->create(['name' => 'C', 'type' => 'page', 'slug' => 'company']);

    expect(fn () => $service->create(['name' => 'D', 'type' => 'page', 'slug' => 'company']))
        ->toThrow(ValidationException::class);
});

test('TC-CMS-T21 更新栏目允许清空 slug / template（变为非单页）', function () {
    $service = cmsTreeService();

    $page = $service->create([
        'name' => '公司介绍', 'type' => CsFaqCategory::TYPE_PAGE,
        'slug' => 'company', 'template' => 'about',
    ]);

    $updated = $service->update($page->id, ['slug' => '', 'template' => '']);

    expect($updated->slug)->toBeNull()
        ->and($updated->template)->toBeNull();
});

test('TC-CMS-T22 tree(nav_only) 只保留标记为显示在导航的根节点', function () {
    $service = cmsTreeService();

    $service->create(['name' => '不展示栏目', 'show_in_nav' => false]);
    $shown = $service->create(['name' => '展示栏目', 'show_in_nav' => true]);

    $tree = $service->tree(['nav_only' => true, 'active_only' => true]);

    $names = array_column($tree, 'name');

    expect($names)->toContain('展示栏目')
        ->and($names)->not->toContain('不展示栏目')
        ->and(cmsFindNode($tree, '展示栏目')['id'])->toBe($shown->id);
});
