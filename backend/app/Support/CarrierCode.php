<?php

namespace App\Support;

use App\Models\ExpressCompany;

/**
 * 承运商编码双向解析（唯一真源，物流分层三期）
 *
 * 同一个快递公司在不同体系里有不同编码（详见
 * docs/design/CubeShop_Logistics_Layering_and_CarrierCode_v1.0.md §4）：
 *
 * | 体系 | 顺丰 | 圆通 | 京东 |
 * |---|---|---|---|
 * | 平台内部 `express_companies.code` | `SF` | `YTO` | `JD` |
 * | 快递100 | `shunfeng` | `yuantong` | `jd` |
 * | 菜鸟奇门 | `SF` | `YTO` | ——（`OTHER`） |
 *
 * 历史上靠单列 `channel_code` 承载，WMS 接入后不够用（承载不了奇门/京东各自的体系），
 * 故新增 `carrier_codes` JSON 映射，**所有编码互转一律走本类**，禁止直接
 * `ExpressCompany::where(...)` 取值——否则会绕过回落优先级。
 *
 * 两个方向用途不同：
 * - {@see self::forChannel()} 正查，平台 → 渠道，用于出站（查轨迹、下发仓配指令）；
 * - {@see self::fromChannel()} 反查，渠道 → 平台，用于**入站归一**（WMS 回传写库前）。
 *
 * 匹配统一忽略大小写（`SF` 与 `sf` 等价）；字典后台可维护，故提供 {@see self::flushCache()}。
 */
final class CarrierCode
{
    /** 轨迹查询渠道 */
    public const KUAIDI100 = 'kuaidi100';

    /** WMS 服务商 —— 取值与 {@see WmsProvider} 保持一致 */
    public const CAINIAO = 'cainiao';

    public const JD_CLOUD = 'jd_cloud';

    /** 可维护编码的渠道（管理端字典页据此渲染输入框） */
    public const CHANNELS = [
        self::KUAIDI100 => '快递100',
        self::CAINIAO => '菜鸟奇门',
        self::JD_CLOUD => '京东云仓',
    ];

    /** @var array<string, string> 正查缓存 "渠道|平台码" => 渠道码 */
    private static array $forward = [];

    /** @var array<string, string>|null 全量反查表缓存：外部标识(小写) => 平台码 */
    private static ?array $reverseMap = null;

    /**
     * 平台码 → 指定渠道的承运商编码（正查，出站用）。
     *
     * 取值优先级：
     * 1. `carrier_codes[渠道]` —— 精确配置，最高优先；
     * 2. `channel_code`（**仅** 快递100 渠道）—— 兼容历史数据与存量录入；
     * 3. 平台 `code` 本身 —— 保守回落，总比丢字段好。
     *
     * @return string 空串表示无法解析（入参为空），调用方据此判定失败
     */
    public static function forChannel(string $platformCode, string $channel): string
    {
        $code = trim($platformCode);
        if ($code === '') {
            return '';
        }

        $key = $channel.'|'.$code;
        if (array_key_exists($key, self::$forward)) {
            return self::$forward[$key];
        }

        $row = ExpressCompany::query()
            ->where('code', $code)
            ->first(['code', 'channel_code', 'carrier_codes']);

        if (! $row) {
            return self::$forward[$key] = $code;
        }

        $mapped = $row->carrier_codes[$channel] ?? null;
        $result = is_string($mapped) ? trim($mapped) : '';

        // channel_code 语义上只属于快递100，不参与其他渠道的回落
        if ($result === '' && $channel === self::KUAIDI100) {
            $result = trim((string) $row->channel_code);
        }

        return self::$forward[$key] = ($result !== '' ? $result : $code);
    }

    /**
     * 渠道编码 → 平台码（反查，入站归一用）。
     *
     * 识别线索包含该公司名下的全部已知标识：`carrier_codes` 各渠道值、`channel_code`、
     * 以及平台 `code` 本身（仓方若回传的就是平台码，归一结果等于它自己）。
     *
     * ⚠️ 与 {@see self::forChannel()} 相反，反查**刻意不做渠道隔离**：
     * - 正查是出站，发错编码会被第三方直接拒单，必须严格按渠道取值；
     * - 反查是入站，目标是「从任意标识猜出这是哪家公司」，多一条线索只会更准。
     *   仓方回传的编码并不总能遵守我们的渠道划分，严格隔离反而漏判。
     *
     * @return string|null 未命中返回 null，调用方应**保留原值并告警**，不要静默改写
     */
    public static function fromChannel(string $externalCode, string $channel): ?string
    {
        $raw = trim($externalCode);
        if ($raw === '') {
            return null;
        }

        return self::reverseMap()[strtolower($raw)] ?? null;
    }

    /**
     * 清空进程内缓存。
     *
     * `express_companies` 后台可维护，长驻进程（队列 / 定时任务）改动后需即时生效；
     * 测试隔离同样依赖它。
     */
    public static function flushCache(): void
    {
        self::$forward = [];
        self::$reverseMap = null;
    }

    /**
     * 构建全量反查表：任意已知外部标识(小写) => 平台码。
     *
     * 同一公司的多个标识都映射到同一个平台码，不存在跨公司冲突；
     * 极端情况（两家公司共用一个外部码）按 `code` 字典序先到先得。
     *
     * @return array<string, string>
     */
    private static function reverseMap(): array
    {
        if (self::$reverseMap !== null) {
            return self::$reverseMap;
        }

        $map = [];

        foreach (ExpressCompany::query()->orderBy('code')->get(['code', 'channel_code', 'carrier_codes']) as $row) {
            $candidates = [];

            // 各渠道配置值（按 CHANNELS 声明顺序，让靠前的渠道优先占坑）
            foreach (array_keys(self::CHANNELS) as $channel) {
                $value = is_array($row->carrier_codes) ? ($row->carrier_codes[$channel] ?? null) : null;
                if (is_string($value) && trim($value) !== '') {
                    $candidates[] = $value;
                }
            }

            if (trim((string) $row->channel_code) !== '') {
                $candidates[] = $row->channel_code;
            }

            // 平台码本身也算命中
            $candidates[] = $row->code;

            foreach ($candidates as $candidate) {
                $key = strtolower(trim((string) $candidate));
                if ($key !== '' && ! isset($map[$key])) {
                    $map[$key] = $row->code;
                }
            }
        }

        return self::$reverseMap = $map;
    }
}
