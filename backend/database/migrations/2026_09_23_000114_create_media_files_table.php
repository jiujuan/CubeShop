<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 图片资产注册表（媒体治理 P0，设计见 docs/design/CubeShop_Media_Library_v1.0.md §3）
 *
 * 采用「旁路注册表」思路：**不动任何业务表字段**，另起一张 `media_files` 做资产侧索引。
 * 业务表继续存字符串（只是由绝对 URL 改为相对路径，见迁移 000115），两边以 `path` 关联。
 *
 * `usage_count` 是**扫描得出的非实时值**（{@see \App\Services\Common\MediaRegistry}），
 * 因此基于它的删除必须保守：先软删（`deleted_at` 即 30 天回收窗口的起点），
 * 到期后由 `php artisan media:prune` 显式执行物理删除，且默认只通知不删。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_files')) {
            return;
        }

        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 32)->default('public')->comment('存储磁盘，默认 public');
            $table->string('path', 500)->comment('相对路径 uploads/products/20260918/xxx.png');
            $table->char('md5', 32)->nullable()->comment('文件内容哈希，去重键');
            $table->string('mime', 64)->nullable()->comment('image/webp 等');
            $table->unsignedBigInteger('size')->default(0)->comment('字节数');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('original_name', 255)->nullable()->comment('原始文件名，供后台搜索');
            $table->string('module', 32)->default('common')->comment('来源模块 product/brand/cms/review/refund/ticket/profile/banner');
            $table->unsignedBigInteger('uploaded_by')->nullable()->comment('后台上传者 admin id，前台为 null');
            $table->unsignedInteger('usage_count')->default(0)->comment('业务引用计数，扫描得出（非实时）');
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->comment('软删即进入回收窗口，默认保留 30 天');

            $table->unique(['disk', 'path'], 'media_files_disk_path_unique');
            $table->index('md5', 'media_files_md5_index');
            $table->index(['module', 'created_at'], 'media_files_module_created_index');
            $table->index(['deleted_at', 'usage_count'], 'media_files_reclaim_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
