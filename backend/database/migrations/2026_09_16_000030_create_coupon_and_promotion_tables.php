<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-031（F06）：优惠券 / 用户券 / 满减活动 三表
 *
 * 字段依据 docs/design/v1.1/CubeShop_V1.1_Backend_Design.md §1.2（二期）。
 * 金额统一 DECIMAL(12,2)；JSON 字段沿用项目既有 `jsonb()`（SQLite 下由框架映射为 text）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 优惠券模板
        Schema::create('coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 100)->comment('券名称');
            $table->string('type', 20)->comment('fixed=满减券 / percent=折扣券');
            $table->decimal('amount', 12, 2)->nullable()->comment('fixed 面额');
            $table->smallInteger('percent')->nullable()->comment('percent 折扣 1~99');
            $table->decimal('min_spend', 12, 2)->default(0)->comment('使用门槛');
            $table->decimal('max_discount', 12, 2)->nullable()->comment('折扣券封顶');
            $table->string('scope', 20)->default('all')->comment('all / category / product');
            $table->jsonb('scope_refs')->default('[]')->comment('分类/商品 id 数组');
            $table->integer('total_count')->comment('发放总量');
            $table->integer('issued_count')->default(0)->comment('已领取（条件更新防超发）');
            $table->integer('used_count')->default(0)->comment('已核销');
            $table->integer('per_user_limit')->default(1)->comment('每人限领');
            $table->string('valid_type', 20)->comment('absolute=绝对时间 / relative=领取后 N 天');
            $table->timestamp('valid_from')->nullable()->comment('绝对有效期起');
            $table->timestamp('valid_to')->nullable()->comment('绝对有效期止');
            $table->integer('valid_days')->nullable()->comment('相对有效期天数');
            $table->string('status', 20)->default('active')->comment('active / stopped');
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'valid_to']);
        });

        // 用户持有的券
        Schema::create('user_coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->comment('买家 users.id');
            $table->unsignedBigInteger('coupon_id')->comment('券模板');
            $table->string('status', 20)->default('unused')->comment('unused / used / expired / returned');
            $table->unsignedBigInteger('used_order_id')->nullable()->comment('核销订单');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expire_at')->comment('领取时按 valid_type 计算固化');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expire_at');
            $table->index('coupon_id');
            $table->index('used_order_id');
        });

        // 满减活动
        Schema::create('promotions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 100)->comment('活动名称');
            $table->jsonb('rules')->comment('[{"min":100,"discount":10}, ...] 多级梯度');
            $table->string('scope', 20)->default('all')->comment('all / category / product');
            $table->jsonb('scope_refs')->default('[]')->comment('分类/商品 id 数组');
            $table->timestamp('start_at')->comment('活动开始');
            $table->timestamp('end_at')->comment('活动结束');
            $table->string('status', 20)->default('active')->comment('active / stopped');
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('user_coupons');
        Schema::dropIfExists('coupons');
    }
};
