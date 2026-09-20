<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CMS-204：把「服务公告」软并入内容中心（决策 D9 选项①）
 *
 * 落点：新增一个 `type=channel` 的「公告」栏目，把 `cs_announcement` 存量行拷成
 * 该栏目下的文章；公开 `GET /api/announcements` 改读这批文章，**响应契约一字不改**
 * （字段名与类型完全沿用，前端零改动），后台统一走 `/api/admin/cs/faq/*`。
 *
 * 三条刻意的不变式：
 * 1. **原表保留**：`cs_announcement` 一行不删、一刻不改 —— 它是这次并入的可回退存档
 *    （`down()` 也只回收本迁移造出来的东西）。
 * 2. **幂等**：仅当「公告」栏目下**尚无文章**时才灌数据。重复执行、或管理员已经在新
 *    入口里维护过内容，都不会被二次灌入覆盖。
 * 3. **语义映射**：公告的「置顶」在 CMS 里就是「热门」（`is_top → is_hot`）——
 *    两边的排序语义一致（都是「优先展示」），故不额外加列。
 *
 * ⚠️ 「公告」栏目靠**名字**定位（与 `AnnouncementController` 同一常量口径）。
 * 后台把这个栏目改名后，前台公告位会读不到数据 —— 见 AnnouncementController 的说明。
 */
return new class extends Migration
{
    /** 公告寄存的栏目名（与 App\Http\Controllers\AnnouncementController 保持一致） */
    private const CATEGORY_NAME = '公告';

    /** 栏目排序：排在帮助中心各类目之后，不与业务栏目争位 */
    private const CATEGORY_SORT = 200;

    public function up(): void
    {
        if (! Schema::hasTable('cs_faq_category') || ! Schema::hasTable('cs_faq_article')
            || ! Schema::hasTable('cs_announcement')) {
            return;
        }

        $categoryId = $this->ensureCategory();

        // 幂等闸门：栏目下已有文章 = 已经并入过（或已在新入口维护），不再重复灌入
        if (DB::table('cs_faq_article')->where('category_id', $categoryId)->exists()) {
            return;
        }

        $now = now();
        $sort = 0;

        foreach (DB::table('cs_announcement')->orderBy('id')->get() as $row) {
            DB::table('cs_faq_article')->insert([
                'category_id' => $categoryId,
                'title' => $row->title,
                // summary 留空 → 公开接口按 content 现算摘要，与并入前的输出完全一致
                'summary' => null,
                'content' => $row->content ?? '',
                'content_md' => $row->content_md,
                'sort' => $sort++,
                // 公告的「置顶」即 CMS 的「热门」
                'is_hot' => (bool) $row->is_top,
                // 两表状态枚举同为 draft/published/offline，直传
                'status' => $row->status,
                'view_count' => 0,
                'helpful_count' => 0,
                'unhelpful_count' => 0,
                'published_at' => $row->published_at,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cs_faq_category')) {
            return;
        }

        $categoryId = $this->findCategory();

        if ($categoryId === null) {
            return;
        }

        // 只回收「公告」栏目与它下面的文章；cs_announcement 原表刻意不动（它是存档）
        if (Schema::hasTable('cs_faq_article')) {
            DB::table('cs_faq_article')->where('category_id', $categoryId)->delete();
        }

        DB::table('cs_faq_category')->where('id', $categoryId)->delete();
    }

    /** 取「公告」栏目 id，不存在则播种（含根节点物化路径回填） */
    private function ensureCategory(): int
    {
        $existing = $this->findCategory();

        if ($existing !== null) {
            return $existing;
        }

        $now = now();
        $id = (int) DB::table('cs_faq_category')->insertGetId([
            'name' => self::CATEGORY_NAME,
            'parent_id' => 0,
            'level' => 1,
            'path' => '',
            'type' => 'channel',
            'slug' => null,
            'template' => null,
            // 不进前台导航：公告走独立入口（公告页 + 首页公告位），不占帮助中心导航位
            'show_in_nav' => false,
            'icon' => 'Megaphone',
            'sort' => self::CATEGORY_SORT,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 根节点物化路径 = /{自身 id}/
        DB::table('cs_faq_category')->where('id', $id)->update(['path' => '/'.$id.'/']);

        return $id;
    }

    private function findCategory(): ?int
    {
        $id = DB::table('cs_faq_category')
            ->where('name', self::CATEGORY_NAME)
            ->where('type', 'channel')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
};
