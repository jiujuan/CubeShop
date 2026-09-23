<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 搜索词频表（站内搜索 S1-06）
     *
     * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.7
     *
     * 用途有二：**热搜榜**（`/search/hot`）与**联想优先级 ①**（`search_keywords` 前缀命中
     * 排在商品标题前缀之前 —— 用户搜过的词优先）。
     *
     * `result_count` 记的是**最近一次**搜索的结果数（不是累计），用于后台发现「有热度但零结果」
     * 的词 —— 那是最该配同义词或补商品的信号。
     */
    public function up(): void
    {
        Schema::create('search_keywords', function (Blueprint $table) {
            $table->id();
            // 归一化后的词（trim + 全角转半角 + 空白折叠），unique 保证自增是单行更新
            $table->string('keyword', 100)->unique();
            $table->unsignedInteger('hit_count')->default(0);
            $table->unsignedInteger('result_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            // 1 = 正常参与热搜与联想；0 = 后台屏蔽（竞品词/敏感词）
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();

            $table->index(['status', 'hit_count']);
            $table->index('last_hit_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_keywords');
    }
};
