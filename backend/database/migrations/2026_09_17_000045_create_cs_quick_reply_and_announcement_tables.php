<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS-201（客户服务中心二期 · 批次 A）：快捷回复 / 服务公告建表
 *
 * cs_quick_reply  客服快捷回复模板（CS-203/204 消费）
 * cs_announcement 服务公告（CS-213 服务中心首页展示）
 *
 * 【决策 D8 —— 服务配置承载方式（CS-201 落实）】
 * 项目**已存在** `system_configs`（`config_key` 唯一 128 / `config_value` text / `description` 255，
 * 见 2026_09_15_000011_create_system_configs_table.php），因此按 D8 **复用既有表**，
 * **不新建 `cs_service_config`**：二期服务配置一律以 `cs.` 前缀命名 config_key
 * （如 `cs.auto_close_hours`），由 CS-207 的服务配置接口读写。
 * ⚠️ 后续禁止新增第二套配置表，避免两处配置并存导致口径分叉（AC-201.2）。
 *
 * 双库兼容约定（SQLite / PostgreSQL，同 CS-101）：
 * - JSON 统一用 $table->json()（SQLite→text，PG→jsonb）
 * - 不使用数据库专有类型与专有全文索引（搜索走 LIKE）
 * - 快捷回复与公告均**不做软删除**（与工单一致，决策 D4 同源：配置类数据只停用/下架）
 */
return new class extends Migration
{
    public function up(): void
    {
        // 快捷回复模板
        Schema::create('cs_quick_reply', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('type_id')->nullable()->comment('绑定工单类型，null 表示通用');
            $table->string('title', 64)->comment('模板标题（工作台插入按钮上显示）');
            $table->text('content')->comment('回复正文');
            $table->smallInteger('sort')->default(0);
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人 sys_user.id');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('最后修改人 sys_user.id');
            $table->timestamps();

            // 工作台按「当前工单类型 + 通用」取模板并按 sort 排序
            $table->index(['type_id', 'sort']);
        });

        // 服务公告
        Schema::create('cs_announcement', function (Blueprint $table) {
            $table->id();
            $table->string('title', 128);
            $table->text('content');
            $table->boolean('is_top')->default(false)->comment('置顶');
            $table->string('status', 16)->default('draft')->comment('draft/published/offline');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人 sys_user.id');
            $table->timestamps();

            // 前台列表：已发布 + 置顶优先 + 发布时间倒序
            $table->index(['status', 'is_top', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_announcement');
        Schema::dropIfExists('cs_quick_reply');
    }
};
