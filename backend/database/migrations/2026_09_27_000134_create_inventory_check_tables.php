<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 库存盘点单与明细
 *
 * 与 WMS 库存差异（wms_inventory_diffs）是两件事，勿混用：
 * - wms_inventory_diffs：仓库系统 vs 平台库存的**系统间对账**差异
 * - inventory_checks：人工去货架点数 vs 平台账面的**实物校准**
 *
 * 盘点不冻结库存：开单到过账可能跨数天，期间正常出入库照常发生，
 * 故过账时以「过账时刻的实时库存」计算差异；system_qty 仅作展示用的开单快照。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_checks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('check_no', 32)->unique()->comment('盘点单号 PCyyyymmdd####');
            $table->string('title', 128)->nullable()->comment('盘点名称');
            $table->string('scope_type', 16)->comment('all/category/brand/keyword/custom');
            $table->string('scope_value', 255)->nullable()->comment('分类ID/品牌ID/关键词');
            $table->string('status', 16)->default('draft')->comment('draft/counting/posted/cancelled');
            $table->integer('item_count')->default(0)->comment('明细行数');
            $table->integer('counted_count')->default(0)->comment('已录入行数');
            $table->integer('diff_count')->default(0)->comment('有差异行数');
            $table->integer('total_diff_qty')->default(0)->comment('合计差异数量（绝对值之和）');
            $table->text('remark')->nullable();
            // 操作人字段不建外键：与 inventory_logs.operator_id 同体例，避免删除账号被约束挡住
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人 sys_user.id');
            $table->unsignedBigInteger('posted_by')->nullable()->comment('过账人 sys_user.id');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('inventory_check_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('check_id');
            $table->unsignedBigInteger('sku_id');
            // 冗余快照：导出/展示不必 join，也不受后续改名影响
            $table->string('sku_code', 64);
            $table->string('product_title', 255)->nullable();
            $table->string('specs_text', 255)->nullable()->comment('规格签名文本，如 颜色:红色|尺码:M');
            $table->integer('system_qty')->default(0)->comment('开单时账面库存（快照，仅供展示）');
            $table->integer('locked_qty')->default(0)->comment('开单时锁定库存（快照）');
            $table->integer('counted_qty')->nullable()->comment('实盘数量，NULL=未盘');
            $table->integer('diff_qty')->nullable()->comment('过账时实际调整量（实盘-过账时刻账面）');
            $table->string('status', 16)->default('pending')->comment('pending/counted/posted/skipped');
            $table->string('remark', 255)->nullable();
            $table->timestamps();

            $table->unique(['check_id', 'sku_id']);
            $table->index(['check_id', 'status']);

            $table->foreign('check_id')->references('id')->on('inventory_checks')->cascadeOnDelete();
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_check_items');
        Schema::dropIfExists('inventory_checks');
    }
};
