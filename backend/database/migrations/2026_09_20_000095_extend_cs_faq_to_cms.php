<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 内容中心 CMS（一期）：把「帮助中心」的分类/文章表扩展为通用 CMS 底座
 *
 * 决策：原地演进 cs_faq_* 而不新建 cms_* 表 —— 需求要求复用现有文章管理，
 * 且重命名表名/权限码的回归成本远大于语义收益（见 docs/design/plan/CMS_Tasks.md §1）。
 *
 * 新增能力：
 * - 栏目树：parent_id + level + path（物化路径，用于子树查询与防环）
 * - 栏目类型：type=channel（挂文章） / page（单页，走模板字段）
 * - 单页：slug + template + page_fields(JSON)
 *
 * 双库兼容（SQLite / PostgreSQL）：
 * - JSON 统一 $table->json()（SQLite→text，PG→jsonb）
 * - slug 唯一索引单独建：SQLite 的 ALTER TABLE ADD COLUMN 不允许直接带 UNIQUE
 * - name 的唯一性只存在于控制器 validate（建表本就无 unique 约束），父子化后无需改表
 */
return new class extends Migration
{
    /** 默认单页（幂等：已存在同 slug 则跳过） */
    private const DEFAULT_PAGES = [
        ['name' => '关于我们', 'slug' => 'about', 'template' => 'about', 'icon' => 'Info', 'sort' => 100],
        ['name' => '联系我们', 'slug' => 'contact', 'template' => 'contact', 'icon' => 'Phone', 'sort' => 110],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('cs_faq_category')) {
            return;
        }

        Schema::table('cs_faq_category', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父栏目 id，0=根');
            $table->unsignedTinyInteger('level')->default(1)->comment('层级，根为 1');
            $table->string('path', 255)->default('')->comment('物化路径，如 /1/5/');
            $table->string('type', 16)->default('channel')->comment('channel=栏目 page=单页');
            $table->string('slug', 64)->nullable()->comment('单页 URL 标识，唯一');
            $table->string('template', 32)->nullable()->comment('单页模板 key（CmsPageTemplate）');
            $table->boolean('show_in_nav')->default(false)->comment('是否进入前台导航');
            $table->string('icon', 32)->nullable();

            $table->index(['parent_id', 'sort'], 'cs_faq_category_parent_sort_index');
            $table->index(['type', 'is_active'], 'cs_faq_category_type_active_index');
        });

        // 唯一索引单独建：SQLite 不支持在 ADD COLUMN 时附带 UNIQUE 约束
        Schema::table('cs_faq_category', function (Blueprint $table) {
            $table->unique('slug', 'cs_faq_category_slug_unique');
        });

        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->json('page_fields')->nullable()->comment('单页结构化字段（普通文章为 null）');
            $table->string('cover_image', 255)->nullable()->comment('列表封面');
        });

        // 回填存量分类的物化路径（其余新列由默认值覆盖：parent_id=0 / level=1 / type=channel）
        foreach (DB::table('cs_faq_category')->pluck('id') as $id) {
            DB::table('cs_faq_category')->where('id', $id)->update(['path' => '/'.$id.'/']);
        }

        // 播种默认单页
        $now = now();
        foreach (self::DEFAULT_PAGES as $page) {
            if (DB::table('cs_faq_category')->where('slug', $page['slug'])->exists()) {
                continue;
            }

            $id = DB::table('cs_faq_category')->insertGetId([
                'name' => $page['name'],
                'parent_id' => 0,
                'level' => 1,
                'path' => '',
                'type' => 'page',
                'slug' => $page['slug'],
                'template' => $page['template'],
                'show_in_nav' => true,
                'icon' => $page['icon'],
                'sort' => $page['sort'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 根节点物化路径 = /{自身 id}/
            DB::table('cs_faq_category')->where('id', $id)->update(['path' => '/'.$id.'/']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cs_faq_category')) {
            return;
        }

        DB::table('cs_faq_category')
            ->whereIn('slug', array_column(self::DEFAULT_PAGES, 'slug'))
            ->delete();

        Schema::table('cs_faq_category', function (Blueprint $table) {
            $table->dropUnique('cs_faq_category_slug_unique');
            $table->dropIndex('cs_faq_category_parent_sort_index');
            $table->dropIndex('cs_faq_category_type_active_index');
        });

        Schema::table('cs_faq_category', function (Blueprint $table) {
            $table->dropColumn([
                'parent_id', 'level', 'path', 'type', 'slug', 'template', 'show_in_nav', 'icon',
            ]);
        });

        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->dropColumn(['page_fields', 'cover_image']);
        });
    }
};
