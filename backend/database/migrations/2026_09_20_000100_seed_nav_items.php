<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 播种初始导航（导航可管理化）
 *
 * 把现有「已启用的一级分类」按展示顺序登记为引用型条目，
 * 并把原来硬编码在前台的「热销推荐」迁为 custom 条目 —— 迁进来才可控。
 *
 * 「首页」不登记：它永远在第一位、永远存在，硬编码最省（需求明确「除了首页」）。
 *
 * ⚠️ 幂等：nav_items 已有任何行则整段跳过（存量环境可能已手工编排过）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('nav_items')->exists()) {
            return;
        }

        $now = now();
        $rows = [];

        // 热销推荐：原来硬编码在 ShopHeader 的 /search?sort=sales_desc，排最前
        $rows[] = [
            'type' => 'custom',
            'title' => '热销推荐',
            'url' => '/search?sort=sales_desc',
            'category_id' => null,
            'target' => '_self',
            'sort' => 0, // 稍后统一重排
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $categories = Category::query()
            ->where('status', 1)
            ->where('parent_id', 0)
            ->orderByDesc('sort')
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($categories as $category) {
            $rows[] = [
                'type' => 'category',
                'title' => null, // 标题取自分类，改名自动跟随
                'url' => null,
                'category_id' => $category->id,
                'target' => '_self',
                'sort' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // 按数组顺序统一重排 sort：越靠前越大（与 categories.sort 体例一致）
        $total = count($rows);
        foreach ($rows as $index => &$row) {
            $row['sort'] = ($total - $index) * 10;
        }
        unset($row);

        DB::table('nav_items')->insert($rows);
    }

    public function down(): void
    {
        // 只回收本次播种的数据：引用型（category_id 非空）+ 明确的「热销推荐」
        DB::table('nav_items')
            ->where(function ($q) {
                $q->whereNotNull('category_id')
                    ->orWhere('title', '热销推荐');
            })
            ->delete();
    }
};
