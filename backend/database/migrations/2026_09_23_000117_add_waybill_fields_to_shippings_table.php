<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 电子面单申请层（出单侧）数据支撑（V1.2）
 *
 * `tracking_no` 已在 000034 建立，本迁移只补出单相关字段：
 * - waybill_channel：出单渠道（mock/kuaidi100/null），便于审计与重打；
 * - waybill_printed_at：电子面单生成/打印时间（发货即出单时即写入）；
 * - waybill_data：面单原始报文（含 label/route），供后台重打，不入库敏感凭证。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shippings', function (Blueprint $table) {
            $table->string('waybill_channel', 20)->nullable()->after('tracking_no')
                ->comment('出单渠道：mock/kuaidi100/null');
            $table->timestamp('waybill_printed_at')->nullable()->after('waybill_channel')
                ->comment('电子面单生成/打印时间');
            $table->jsonb('waybill_data')->nullable()->after('waybill_printed_at')
                ->comment('面单原始报文（含 label/route），供重打');
        });
    }

    public function down(): void
    {
        Schema::table('shippings', function (Blueprint $table) {
            $table->dropColumn(['waybill_channel', 'waybill_printed_at', 'waybill_data']);
        });
    }
};
