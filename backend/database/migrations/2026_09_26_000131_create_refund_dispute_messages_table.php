<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款纠纷消息表（买卖/平台沟通留痕）
 *
 * 与 refund_disputes 一对一多的线程关系；sender_type 区分 admin/customer/system。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 24)->unique()->comment('对外 ID');
            $table->unsignedBigInteger('dispute_id')->comment('关联 refund_disputes.id');
            $table->string('sender_type', 16)->comment('admin/customer/system');
            $table->unsignedBigInteger('sender_id')->nullable()->comment('发送人 ID');
            $table->string('body', 2000)->comment('消息内容');
            $table->json('attachments')->nullable()->comment('附件');
            $table->timestamps();

            $table->foreign('dispute_id')->references('id')->on('refund_disputes')->cascadeOnDelete();
            $table->index(['dispute_id'], 'refund_dispute_msgs_dispute');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_dispute_messages');
    }
};
