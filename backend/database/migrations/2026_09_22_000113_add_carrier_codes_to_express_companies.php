<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 快递公司字典增加多渠道承运商编码映射（物流分层三期）
 *
 * 背景：原 `channel_code` 单列被快递100 占用（`SF` → `shunfeng`），WMS 接入后承载不了
 * 奇门 / 京东云仓各自的编码体系；且 WMS 回传编码原样落库会让 `shippings.company_code`
 * 出现两种互不相容的语义（详见 docs/design/CubeShop_Logistics_Layering_and_CarrierCode_v1.0.md §4）。
 *
 * 本迁移：
 * 1. 新增 `carrier_codes` JSON 列，结构 {"kuaidi100":"shunfeng","cainiao":"SF","jd_cloud":"JD"}；
 * 2. **保留** `channel_code` 列做兼容（既有数据 + 管理端 UI + 存量代码都依赖它），
 *    并把现存值回填为 `carrier_codes.kuaidi100` —— 保证升级前后快递100 查询行为完全一致；
 * 3. 幂等：列已存在则跳过建列；已有 `carrier_codes` 的行不被覆盖。
 *
 * 取值优先级由 {@see \App\Support\CarrierCode::forChannel()} 统一裁决，本迁移不做判断。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('express_companies', 'carrier_codes')) {
            Schema::table('express_companies', function (Blueprint $table) {
                $table->jsonb('carrier_codes')->nullable()->comment('多渠道承运商编码映射 {"kuaidi100":"shunfeng","cainiao":"SF"}');
            });
        }

        foreach (DB::table('express_companies')->get() as $row) {
            $existing = $this->decode($row->carrier_codes ?? null);

            // 已有映射 或 无历史 channel_code 可迁，均跳过
            if ($existing !== [] || trim((string) ($row->channel_code ?? '')) === '') {
                continue;
            }

            $existing['kuaidi100'] = trim((string) $row->channel_code);

            DB::table('express_companies')
                ->where('id', $row->id)
                ->update(['carrier_codes' => json_encode($existing, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('express_companies', 'carrier_codes')) {
            Schema::table('express_companies', function (Blueprint $table) {
                $table->dropColumn('carrier_codes');
            });
        }
    }

    /** @return array<string, string> */
    private function decode(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
};
