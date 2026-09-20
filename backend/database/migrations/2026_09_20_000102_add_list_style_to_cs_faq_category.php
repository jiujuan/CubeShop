<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新闻中心（CMS 新闻中心，一期）：栏目增加「列表形态」标记
 *
 * 仅 `type=channel` 的栏目有意义：card=图文卡片 / list=列表行。
 * 前台新闻中心页用它区分「图文新闻」与「列表新闻」两种渲染，运营可在后台切换。
 * 不复用既有 `template` 列（那是单页模板语义，混用会污染两个概念）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cs_faq_category', function (Blueprint $table) {
            $table->string('list_style', 16)
                ->nullable()
                ->default('list')
                ->after('template')
                ->comment('列表形态：card=图文卡片 / list=列表行（仅 channel 栏目有意义）');
        });
    }

    public function down(): void
    {
        Schema::table('cs_faq_category', function (Blueprint $table) {
            $table->dropColumn('list_style');
        });
    }
};
