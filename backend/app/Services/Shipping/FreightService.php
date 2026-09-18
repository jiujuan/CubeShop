<?php

namespace App\Services\Shipping;

use App\Models\FreightTemplate;
use App\Services\Common\ConfigService;
use App\Services\Shipping\Dto\FreightResult;
use App\Services\Common\RegionService;
use Illuminate\Support\Facades\Log;

/**
 * 运费服务（Stage 2 接线层）：从 DB/Config 组装入参 → 调 FreightCalculator 纯函数引擎。
 *
 * OrderService（下单）与 freight-preview（结算页实时预览）共用本服务，
 * 保证「预估与实付同一套逻辑」（设计文档核心要求）。
 *
 * 全局默认规则（未绑定模板的商品行走此口径）：
 *  - 配置 order.freight_template_id 指向启用中的模板 → 用该模板；
 *  - 否则回退旧口径：fixed（order.freight_default）+ order.free_shipping_threshold 包邮。
 *
 * 商品绑定的模板缺失/停用 → 降级为全局默认规则并记 warning（方案 3.3 降级策略）。
 */
class FreightService
{
    public function __construct(private ConfigService $config)
    {
    }

    /**
     * 计算运费。
     *
     * @param  iterable<int, array{template_id: int|null, weight_g: int, quantity: int, price: string}>  $lines
     * @param  string|null  $provinceName  收货地址省名（user_addresses.province 存文本，内部换算省 code）
     */
    public function calculate(iterable $lines, ?string $provinceName = null): FreightResult
    {
        $lines = is_array($lines) ? $lines : iterator_to_array($lines);

        $templates = $this->loadTemplates(collect($lines)->pluck('template_id')->filter()->unique()->all());

        $provinceCode = $provinceName !== null && $provinceName !== ''
            ? RegionService::codeOfProvinceName($provinceName)
            : null;

        return FreightCalculator::calculate(
            lines: $lines,
            templates: $templates,
            defaultRules: $this->defaultRules(),
            freeShippingThreshold: $this->config->getDecimal('order.free_shipping_threshold', '0.00'),
            provinceCode: $provinceCode,
        );
    }

    /**
     * 批量取启用中的模板（禁用/被删的模板不返回 → 引擎按「模板缺失」降级默认规则）。
     *
     * @param  array<int, int|string>  $templateIds
     * @return array<string, array{mode: string, rules: array}>
     */
    private function loadTemplates(array $templateIds): array
    {
        if ($templateIds === []) {
            return [];
        }

        $found = FreightTemplate::query()
            ->enabled()
            ->whereIn('id', $templateIds)
            ->get()
            ->mapWithKeys(fn (FreightTemplate $t) => [
                (string) $t->id => ['mode' => (string) $t->mode, 'rules' => (array) $t->rules],
            ]);

        // 降级审计：绑定但未取到的模板（被删/停用）记 warning，便于运营发现配置漂移
        foreach ($templateIds as $id) {
            if (! $found->has((string) $id)) {
                Log::warning('freight.template_missing', ['template_id' => $id, 'fallback' => 'default_rules']);
            }
        }

        return $found->all();
    }

    /**
     * 全局默认规则：配置指向的启用模板，或旧「固定运费」口径。
     *
     * @return array{mode: string, rules: array}
     */
    private function defaultRules(): array
    {
        $globalId = $this->config->getInt('order.freight_template_id', 0);
        if ($globalId > 0) {
            $template = FreightTemplate::query()->enabled()->find($globalId);
            if ($template) {
                return ['mode' => (string) $template->mode, 'rules' => (array) $template->rules];
            }

            Log::warning('freight.global_template_missing', ['template_id' => $globalId, 'fallback' => 'legacy_fixed']);
        }

        return [
            'mode' => 'fixed',
            'rules' => ['amount' => $this->config->getDecimal('order.freight_default', '10.00')],
        ];
    }
}
