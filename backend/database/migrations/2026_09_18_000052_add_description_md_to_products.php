<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品详情改用 markdown 编辑器（md-editor-v3，与帮助中心文章编辑器一致）
 *
 * 新增 `description_md`：markdown 源，是唯一可编辑载体；`description` 退化为
 * 「渲染(markdown) + 净化」派生的 HTML 产物（用户端 DetailView 仍用 v-html 渲染）。
 *
 * 不回填存量：存量商品 `description` 是 HTML 富文本，本次只加列、不改动已有数据。
 * 编辑旧商品时加载器只读取 `description_md`（为空则编辑器空白），保存时不传
 * `description_md` 即不会触发派生钩子，`description` 原值得以保留；作者想换用
 * markdown 时在编辑器里重新输入即可。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('description_md')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('description_md');
        });
    }
};
