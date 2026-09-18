<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS-101（客户服务中心一期）：客服中心核心五表
 *
 * cs_ticket_type    工单类型配置
 * cs_ticket         服务工单主表
 * cs_ticket_message 工单沟通消息（含内部备注）
 * cs_faq_category   帮助中心分类
 * cs_faq_article    帮助中心文章
 *
 * 双库兼容约定（SQLite / PostgreSQL）：
 * - JSON 统一用 $table->json()（SQLite→text，PG→jsonb）
 * - 不使用数据库专有类型与数据库专有全文索引（搜索走 LIKE，见 CS-104）
 * - 工单不做软删除（服务凭证只能关闭不能删，决策 D4）
 */
return new class extends Migration
{
    public function up(): void
    {
        // 工单类型配置
        Schema::create('cs_ticket_type', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->comment('类型名称');
            $table->string('code', 32)->unique()->comment('类型编码');
            $table->boolean('require_order')->default(false)->comment('是否必须关联订单');
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });

        // 服务工单主表
        Schema::create('cs_ticket', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 32)->unique()->comment('工单号 TK+ymd+6位序列');
            $table->unsignedBigInteger('user_id')->comment('买家 users.id');
            $table->unsignedBigInteger('type_id')->comment('工单类型');
            $table->unsignedBigInteger('order_id')->nullable()->comment('关联订单');
            $table->string('title', 128);
            $table->text('content');
            $table->string('status', 16)->default('pending')
                ->comment('pending/processing/waiting_user/completed/closed');
            $table->smallInteger('priority')->default(0)->comment('0普通 1紧急');
            $table->unsignedBigInteger('assignee_id')->nullable()->comment('处理客服 sys_user.id');
            $table->string('contact', 64)->nullable()->comment('联系方式');
            $table->smallInteger('satisfaction')->nullable()->comment('满意度 1-5');
            $table->string('satisfaction_remark', 255)->nullable();
            $table->timestamp('satisfaction_at')->nullable();
            $table->timestamp('first_replied_at')->nullable()->comment('首次客服回复时间');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 32)->nullable()->comment('user/system/staff/timeout');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['assignee_id', 'status']);
            $table->index('order_id');
            $table->index('type_id');
        });

        // 工单沟通消息
        Schema::create('cs_ticket_message', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->string('sender_type', 16)->comment('user/staff/system');
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('content')->nullable();
            $table->json('images')->nullable()->comment('图片 URL 列表');
            $table->boolean('is_internal')->default(false)->comment('内部备注，仅客服可见');
            $table->timestamp('created_at')->nullable();

            $table->index(['ticket_id', 'created_at']);
            $table->index(['ticket_id', 'is_internal']);
        });

        // 帮助中心分类
        Schema::create('cs_faq_category', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });

        // 帮助中心文章
        Schema::create('cs_faq_article', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('title', 191);
            $table->string('summary', 255)->nullable();
            $table->text('content');
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_hot')->default(false);
            $table->string('status', 16)->default('draft')->comment('draft/published/offline');
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'status', 'sort']);
            $table->index(['status', 'is_hot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_faq_article');
        Schema::dropIfExists('cs_faq_category');
        Schema::dropIfExists('cs_ticket_message');
        Schema::dropIfExists('cs_ticket');
        Schema::dropIfExists('cs_ticket_type');
    }
};
