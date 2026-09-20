<?php

use App\Models\CsFaqCategory;
use App\Services\Cms\CmsCategoryService;
use App\Support\CmsListStyle;
use Illuminate\Database\Migrations\Migration;

/**
 * 新闻中心（CMS 新闻中心，一期）：播种「新闻中心」根栏目 + 两个子栏目
 *
 * 树形（与公告软并入同一套路，按 slug 锚定、name 兜底）：
 *   新闻中心（channel, slug=news, list_style=list）
 *     ├ 图文新闻（channel, slug=news-graphic, list_style=card）
 *     └ 列表新闻（channel, slug=news-list,    list_style=list）
 *
 * 幂等：重跑迁移（或 rollback 后再 migrate）不会重复建根/子栏目。
 * 用 `CmsCategoryService::create()` 复用 level/path/slug 校验，不手写物化路径。
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(CmsCategoryService::class);

        // 根栏目：slug 优先，name 兜底（运营改了 slug 也能继续锚定同一棵）
        $root = CsFaqCategory::query()
            ->where('slug', CsFaqCategory::NEWS_SLUG)
            ->orWhere('name', CsFaqCategory::NEWS_ROOT_NAME)
            ->first();

        if (! $root) {
            $root = $service->create([
                'name' => CsFaqCategory::NEWS_ROOT_NAME,
                'parent_id' => 0,
                'type' => CsFaqCategory::TYPE_CHANNEL,
                'slug' => CsFaqCategory::NEWS_SLUG,
                'list_style' => CmsListStyle::LIST,
                'is_active' => true,
                'show_in_nav' => false,
                'sort' => 900,
            ]);
        }

        $this->ensureChild($service, $root, '图文新闻', 'news-graphic', CmsListStyle::CARD, 10);
        $this->ensureChild($service, $root, '列表新闻', 'news-list', CmsListStyle::LIST, 20);
    }

    /**
     * 幂等建子栏目：同 parent 下（slug 或 name 命中其一）即跳过
     */
    private function ensureChild(
        CmsCategoryService $service,
        CsFaqCategory $root,
        string $name,
        string $slug,
        string $style,
        int $sort,
    ): void {
        $exists = CsFaqCategory::query()
            ->where('parent_id', $root->id)
            ->where(fn ($q) => $q->where('slug', $slug)->orWhere('name', $name))
            ->exists();

        if ($exists) {
            return;
        }

        $service->create([
            'name' => $name,
            'parent_id' => $root->id,
            'type' => CsFaqCategory::TYPE_CHANNEL,
            'slug' => $slug,
            'list_style' => $style,
            'is_active' => true,
            'show_in_nav' => false,
            'sort' => $sort,
        ]);
    }

    public function down(): void
    {
        CsFaqCategory::query()->whereIn('slug', ['news-graphic', 'news-list'])->delete();
        CsFaqCategory::query()->where('slug', CsFaqCategory::NEWS_SLUG)->delete();
    }
};
