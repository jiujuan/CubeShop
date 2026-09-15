<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1.1 F01 / T-015：商品评价表
 *
 * 一个订单行项目（order_items）允许且仅允许一条评价（order_item_id 唯一）。
 * rating 1~5；status 受 review.audit_mode 控制（关闭时直接 approved）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('order_item_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('sku_id')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('content')->nullable();
            $table->json('images')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->string('status', 16)->default('approved')->index();
            $table->string('reject_reason', 255)->nullable();
            $table->text('reply_content')->nullable();
            $table->timestamp('reply_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
