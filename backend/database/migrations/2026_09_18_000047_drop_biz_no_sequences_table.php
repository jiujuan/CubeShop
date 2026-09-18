<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-03：退役 biz_no_sequences 表
 *
 * 历史单号生成器（`NoGeneratorService`）依赖该表做「按前缀行锁 + 自增序号」，
 * 既是单号可枚举（相邻序号可推算日单量）的根因，也是并发下的性能瓶颈。
 * SEC-03 已将生成器改为「{前缀}{Ymd}{10 位随机}」，不再读取本表，
 * 全仓库已无任何引用（grep 验证：app/ tests/ database/ 均不再出现 biz_no_sequences）。
 *
 * 因此这里将其安全退役：幂等删除，避免留在库中成为「无主表」与潜在的枚举面。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('biz_no_sequences');
    }

    public function down(): void
    {
        if (Schema::hasTable('biz_no_sequences')) {
            return;
        }

        Schema::create('biz_no_sequences', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('biz_type', 32)->unique();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });
    }
};
