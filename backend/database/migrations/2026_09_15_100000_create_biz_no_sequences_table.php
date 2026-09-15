<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 业务单号序列表：{prefix, biz_date} 唯一，行内自增生成 6 位日期内序列
 * （NoGeneratorService 依赖；替代"微秒+随机"的高碰撞实现）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biz_no_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('biz_date', 8)->comment('业务日期 YYYYMMDD');
            $table->string('prefix', 8)->comment('单号前缀 CS/PAY/RF');
            $table->unsignedBigInteger('current_value')->default(0)->comment('当前已用序列值');
            $table->unique(['biz_date', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biz_no_sequences');
    }
};
