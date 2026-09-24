<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 同义词表（站内搜索 S1-10 补做，设计 §4.8）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.4（降级链第 3 步）/ §4.8
 *
 * 一行 = 一条替换规则：`from_word` → `to_words`（候选数组）。
 * 检索零结果后按规则展开成多组查询重跑，命中则 `relaxed=true`。
 *
 * 约定（与 Service 层的匹配逻辑绑定，改动须两侧同步）：
 * - `from_word` 一律**归一化 + 小写**后入库（SearchTokenizer::normalize + mb_strtolower），
 *   匹配时对小写化的归一化关键词做 `mb_strpos` 子串命中 —— 不做正则，避免规则词里的
 *   特殊字符变成操作符；
 * - `to_words` 内每个词同样归一化，且不含 `from_word` 自身（否则展开重查等于原查询，白跑）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            // 长度 50：词级替换，不是短语翻译；unique 防止同一 from 词配两套候选造成歧义
            $table->string('from_word', 50)->unique()->comment('被替换词（归一化+小写）');
            $table->jsonb('to_words')->comment('替换候选，jsonb 数组（已归一化）');
            $table->boolean('status')->default(true)->comment('1 启用 / 0 停用');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
    }
};
