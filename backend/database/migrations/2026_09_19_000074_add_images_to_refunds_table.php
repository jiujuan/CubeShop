<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款表扩展「图片凭证」能力（后台退款处理增强）
 *
 * - images：用户申请时上传的凭证图片（商品问题实拍等），供后台详情查看
 * - admin_images：后台审核时上传的说明图片（同意/拒绝理由佐证）
 *
 * 编号 000074 有意跳过 WMS 计划预排的 P5 区间（000072~000073），避免与后续
 * 库存同步迁移冲突；本迁移属于退款域能力增强，与 WMS 强相关但独立。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            // 用户申请凭证图（URL 数组）
            $table->json('images')->nullable()->after('reason');
            // 后台处理说明图（URL 数组）
            $table->json('admin_images')->nullable()->after('admin_remark');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['images', 'admin_images']);
        });
    }
};
