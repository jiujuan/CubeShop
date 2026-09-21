<?php

namespace App\Support\Shipping;

use App\Support\CarrierCode;
use Illuminate\Support\Facades\Http;

/**
 * 快递100 实时查询渠道（V1.1 三期）
 *
 * 接口：POST https://poll.kuaidi100.com/poll/query.do（form-urlencoded）
 * 签名：strtoupper(md5(param + key + customer))，param 为**未 urlencode** 的 JSON 串。
 *
 * 设计要点：
 * - 内部 code（SF/ZTO…）经 `express_companies.channel_code` 转换为快递100 编码（shunfeng/zhongtong…），
 *   字典未配置时回落内部 code——快递100 与常用内部码部分重合，总比直接失败好；
 * - 运单级 state 只有 8 态且**不区分行**，故行级 stage 优先按轨迹文案匹配，state 仅作兜底；
 * - 顺丰/顺丰快运/中通强制手机号，缺失时直接 fail 并给出可操作提示（不静默失败）；
 * - 一切异常收敛为 TraceResult::fail()，由 TracePullService 统一计入 pull_fail_count。
 */
class Kuaidi100Channel implements ShippingChannelInterface
{
    /** 快递100 明确要求必填手机号的渠道编码 */
    private const PHONE_REQUIRED = ['shunfeng', 'shunfengkuaiyun', 'zhongtong'];

    /** 运单级 state → 轨迹阶段；2/4/6/7 属异常，未签收故统一视作在途（异常由文案体现） */
    private const STATE_STAGE = [
        '0' => TraceStage::IN_TRANSIT,
        '1' => TraceStage::PICKUP,
        '2' => TraceStage::IN_TRANSIT,
        '3' => TraceStage::DELIVERED,
        '4' => TraceStage::IN_TRANSIT,
        '5' => TraceStage::DELIVERING,
        '6' => TraceStage::IN_TRANSIT,
        '7' => TraceStage::IN_TRANSIT,
    ];

    /**
     * 文案关键词 → 阶段。**顺序即优先级**：越靠后（越接近签收）越先匹配，
     * 否则「派件已签收」会被误判成派送中。
     */
    private const KEYWORD_STAGES = [
        TraceStage::DELIVERED => ['已签收', '签收成功', '已妥投', '妥投', '代收'],
        TraceStage::DELIVERING => ['派件', '派送', '投递', '正在配送', '安排送达'],
        TraceStage::PICKUP => ['揽收', '已收件', '已取件', '揽件', '已揽件'],
    ];

    public function available(): bool
    {
        return $this->key() !== '' && $this->customer() !== '';
    }

    /**
     * 清空进程内编码缓存（V1.1 三期）
     *
     * `express_companies` 后台可维护，改动后需即时生效；
     * 长驻进程（队列/定时任务）与测试隔离都依赖它。
     * 缓存实际由 {@see CarrierCode} 持有，这里委托清理。
     */
    public static function flushCache(): void
    {
        CarrierCode::flushCache();
    }

    public function query(string $companyCode, string $trackingNo, ?string $phone = null): TraceResult
    {
        $channelCode = $this->resolveChannelCode($companyCode);

        if ($channelCode === '') {
            return TraceResult::fail('快递公司未配置渠道编码：'.$companyCode);
        }

        $phone = $this->normalizePhone((string) $phone);
        if ($this->requiresPhone($channelCode) && $phone === '') {
            return TraceResult::fail('该快递公司（'.$channelCode.'）轨迹查询需手机号，请为运单补全收件人手机号');
        }

        $param = json_encode([
            'com' => $channelCode,
            'num' => $trackingNo,
            'phone' => $phone,
            'resultv2' => '1',
            'order' => 'asc',
        ], JSON_UNESCAPED_UNICODE);

        $payload = [
            'customer' => $this->customer(),
            'param' => $param,
            'sign' => strtoupper(md5($param.$this->key().$this->customer())),
        ];

        $timeout = max(1, (int) config('services.shipping.timeout', 8));

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout(min(3, $timeout))
                ->post((string) config('services.shipping.query_url'), $payload);
        } catch (\Throwable $e) {
            return TraceResult::fail('快递100 请求异常：'.$e->getMessage());
        }

        if (! $response->successful()) {
            return TraceResult::fail('快递100 HTTP '.$response->status(), ['status' => $response->status()]);
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?: [];

        return $this->toTraceResult($body);
    }

    /** 响应 → TraceResult */
    private function toTraceResult(array $body): TraceResult
    {
        $status = (string) ($body['status'] ?? '');
        $message = (string) ($body['message'] ?? '');

        // 200 = 查询成功（data 可能为空，属「尚无轨迹」的正常态，不计失败）
        if ($status !== '' && $status !== '200') {
            return TraceResult::fail($message !== '' ? $message : '快递100 查询失败（status='.$status.'）', $body);
        }

        if (isset($body['returnCode']) && (string) $body['returnCode'] !== '200') {
            return TraceResult::fail($message !== '' ? $message : '快递100 查询失败', $body);
        }

        $state = (string) ($body['state'] ?? '');
        $rows = is_array($body['data'] ?? null) ? $body['data'] : [];

        $traces = [];
        foreach ($rows as $row) {
            $context = trim((string) ($row['context'] ?? ''));
            if ($context === '') {
                continue;
            }
            $traces[] = [
                'context' => $context,
                'occurred_at' => $this->normalizeTime((string) ($row['ftime'] ?? $row['time'] ?? '')),
                'stage' => $this->stageOf($context),
            ];
        }

        $total = count($traces);
        if ($total > 0 && isset(self::STATE_STAGE[$state])) {
            // 兜底：末行（asc 即最新）文案未命中关键词时，以运单级 state 为准
            $last = &$traces[$total - 1];
            if ($last['stage'] === TraceStage::IN_TRANSIT) {
                $last['stage'] = self::STATE_STAGE[$state];
            }
        }

        return TraceResult::ok($traces, $body);
    }

    /** 轨迹文案 → 阶段 */
    private function stageOf(string $context): string
    {
        foreach (self::KEYWORD_STAGES as $stage => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($context, $keyword)) {
                    return $stage;
                }
            }
        }

        return TraceStage::IN_TRANSIT;
    }

    /** 手机号归一：电商虚拟号「138****1234-5678」取「-」后四位 */
    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        if (str_contains($phone, '-')) {
            $parts = explode('-', $phone);

            return trim((string) end($parts));
        }

        return $phone;
    }

    private function requiresPhone(string $channelCode): bool
    {
        return in_array($channelCode, self::PHONE_REQUIRED, true);
    }

    /**
     * 内部 code → 渠道编码；字典未配置时回落内部 code。
     *
     * 委托 {@see CarrierCode}：由它统一裁决「carrier_codes → channel_code → 平台码」的回落优先级。
     * 缓存同样由它持有——Channel 以 bind 注册，实例级缓存无效，必须是静态级。
     */
    private function resolveChannelCode(string $companyCode): string
    {
        return CarrierCode::forChannel($companyCode, CarrierCode::KUAIDI100);
    }

    /**
     * 时间归一：快递100 返回「2012-08-28 16:33:19」。
     *
     * 缺失时返回空串交由 TracePullService 兜底为发货时间——此处若用 now()，
     * 每次拉取时间都不同会导致同一条轨迹被重复入库。
     */
    private function normalizeTime(string $value): string
    {
        return trim($value);
    }

    private function key(): string
    {
        return trim((string) config('services.shipping.key'));
    }

    private function customer(): string
    {
        return trim((string) config('services.shipping.customer'));
    }
}
