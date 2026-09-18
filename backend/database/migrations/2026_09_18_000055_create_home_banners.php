<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 首页广告位（P-HomeBanner）
 *
 * 统一承载首页三块可运营内容，后台（home.manage）维护：
 * - banner  ：主轮播图（多张，按 sort_order 轮播）
 * - promo   ：banner 下方广告图宫格（如 手机数码/家居生活/美妆个护/新用户福利）
 * - bottom  ：页面底部广告图（2 张）
 *
 * 每条记录含图片、大标题、小标题、跳转链接，均可后台编辑；is_enabled=false 不在前台展示。
 * 不做软删除（运营配置类数据）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_banners', function (Blueprint $table) {
            $table->id();
            // ULID 对外标识（P2-11 约定：核心出口表一律 public_id）
            $table->char('public_id', 26)->unique()->comment('ULID 对外标识');
            $table->string('position', 20)->comment('位置：banner=主轮播 promo=中部广告位 bottom=底部广告位');
            $table->string('image', 500)->comment('图片地址');
            $table->string('title', 64)->comment('大标题');
            $table->string('subtitle', 128)->nullable()->comment('小标题');
            $table->string('link_url', 500)->nullable()->comment('跳转链接（站内路由或完整 URL）');
            $table->unsignedInteger('sort_order')->default(0)->comment('排序，小者在前');
            $table->boolean('is_enabled')->default(true)->comment('是否启用');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建管理员 sys_user.id');
            $table->timestamps();

            $table->index(['position', 'is_enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_banners');
    }
};
