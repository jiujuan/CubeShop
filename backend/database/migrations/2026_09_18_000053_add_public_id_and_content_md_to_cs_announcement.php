<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 公告功能落地（P-Announcement）：补全 cs_announcement 对外标识与 markdown 正文源。
 *
 * - public_id：P2-11 终态要求所有对外实体统一用 ULID 对外标识，web 端详情按 public_id 解析；
 *   存量行（本迁移前已存在）在此回填，避免 web 详情按 public_id 解析不到。
 * - content_md：公告正文以 markdown 源存储，content(HTML) 由模型 saving 钩子经
 *   MarkdownRenderer + HtmlSanitizer 派生（与帮助中心文章、商品详情同一套不变式）。
 *   存量行的 content_md 留空（content 原值保留，旧 HTML 不二次渲染）。
 *
 * 双库兼容：char(26) 在 SQLite/PG 均为定长字符串；回填用 ULID 与 trait 生成规则一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cs_announcement', 'public_id')) {
            Schema::table('cs_announcement', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->unique()->after('id');
            });
        }

        if (! Schema::hasColumn('cs_announcement', 'content_md')) {
            Schema::table('cs_announcement', function (Blueprint $table) {
                $table->text('content_md')->nullable()->after('content');
            });
        }

        // 存量行回填 public_id（仅缺失者），保持与 HasPublicId::creating 同规则
        $rows = DB::table('cs_announcement')->whereNull('public_id')->get();
        foreach ($rows as $row) {
            DB::table('cs_announcement')
                ->where('id', $row->id)
                ->update(['public_id' => (string) Str::ulid()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cs_announcement', 'content_md')) {
            Schema::table('cs_announcement', function (Blueprint $table) {
                $table->dropColumn('content_md');
            });
        }

        if (Schema::hasColumn('cs_announcement', 'public_id')) {
            Schema::table('cs_announcement', function (Blueprint $table) {
                $table->dropColumn('public_id');
            });
        }
    }
};
