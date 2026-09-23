<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shipping;
use App\Services\Common\ConfigService;
use App\Services\Common\OperationLogService;
use App\Services\Shipping\TracePullService;
use App\Support\Shipping\Kuaidi100Channel;
use App\Support\Shipping\ShippingChannelInterface;
use App\Support\Shipping\WaybillChannelInterface;
use Illuminate\Http\Request;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 后台物流管理（V1.1 T-045 / T-047 前置接口）
 *
 * 三期补充：渠道查看/切换（system_configs.shipping.channel 覆盖 .env）、运单轨迹详情。
 */
class ShippingController extends Controller
{
    use ApiResponse;

    /** 渠道配置项（后台可切换；密钥仍走 .env，不入库） */
    public const CHANNEL_CONFIG_KEY = 'shipping.channel';

    /** 电子面单申请渠道配置项（与 CHANNEL_CONFIG_KEY 对称） */
    public const WAYBILL_CHANNEL_CONFIG_KEY = 'waybill.channel';

    /**
     * 可选渠道：'' 表示跟随 .env，off 表示强制关闭
     *
     * @var list<array{value: string, label: string}>
     */
    public const CHANNEL_OPTIONS = [
        ['value' => '', 'label' => '跟随环境配置（.env）'],
        ['value' => 'kuaidi100', 'label' => '快递100'],
        ['value' => 'mock', 'label' => '本地演示（Mock，不发真实请求）'],
        ['value' => 'off', 'label' => '关闭轨迹查询'],
    ];

    /** 渠道 → 展示名 */
    private const CHANNEL_LABELS = [
        'kuaidi100' => '快递100',
        'mock' => '本地演示（Mock）',
    ];

    public function __construct(
        private readonly TracePullService $tracePull,
        private readonly ConfigService $config,
        private readonly OperationLogService $operationLog,
        private readonly WaybillChannelInterface $waybillChannel,
    ) {
    }

    /**
     * 物流看板列表（V1.1 T-047；权限 order.view）
     * GET /admin/shippings?trace_status=&keyword=&page=&page_size=
     *
     * 异常口径：trace_status=failed（连续拉取失败）；in_transit 且 shipped_at 超 48h 无轨迹 → stagnant 标记；
     * 列表按发货时间倒序，附订单号与买家便于跳转详情。
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trace_status' => ['nullable', 'string', 'in:'.implode(',', [Shipping::TRACE_PENDING, Shipping::TRACE_IN_TRANSIT, Shipping::TRACE_DELIVERED, Shipping::TRACE_FAILED])],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Shipping::query()
            ->with('order:id,order_no,user_id,status')
            ->withCount('traces')
            ->withMax('traces', 'occurred_at')
            ->when($data['trace_status'] ?? null, fn ($q, $s) => $q->where('trace_status', $s))
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where(fn ($w) => $w
                    ->where('tracking_no', 'like', "%{$kw}%")
                    ->orWhere('company_name', 'like', "%{$kw}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_no', 'like', "%{$kw}%")));
            })
            ->orderByDesc('shipped_at')
            ->orderByDesc('id');

        $page = $query->paginate($data['page_size'] ?? 20);
        $stagnantHours = 72;

        $items = collect($page->items())->map(function (Shipping $s) use ($stagnantHours) {
            $hoursSinceShip = $s->shipped_at ? $s->shipped_at->diffInHours(now()) : 0;
            // 轨迹停滞：in_transit 且最新轨迹距今超 72h（withMax 聚合列为字符串，需 parse）
            $stagnant = $s->trace_status === Shipping::TRACE_IN_TRANSIT
                && $s->traces_max_occurred_at !== null
                && \Carbon\Carbon::parse($s->traces_max_occurred_at)->diffInHours(now()) >= $stagnantHours;
            $stagnant = $stagnant || ($s->trace_status === Shipping::TRACE_IN_TRANSIT && $s->traces_count === 0 && $hoursSinceShip >= $stagnantHours);

            return [
                'id' => $s->id,
                'order_id' => $s->order_id,
                'order_no' => $s->order?->order_no,
                'company_code' => $s->company_code,
                'company_name' => $s->company_name,
                'tracking_no' => $s->tracking_no,
                'trace_status' => $s->trace_status,
                'trace_count' => $s->traces_count,
                'shipped_at' => $s->shipped_at?->toDateTimeString(),
                'delivered_at' => $s->delivered_at?->toDateTimeString(),
                'pull_fail_count' => $s->pull_fail_count,
                'last_fail_message' => $s->last_fail_message,
                // 异常标记：发货超 48h 无轨迹，或轨迹停滞超 72h（最近一条轨迹时间超限）
                'abnormal' => $s->trace_status === Shipping::TRACE_FAILED
                    || ($s->trace_status !== Shipping::TRACE_DELIVERED && $hoursSinceShip >= 48 && $s->traces_count === 0)
                    || $stagnant,
            ];
        })->all();

        return $this->success([
            'list' => $items,
            'pagination' => [
                'page' => $page->currentPage(),
                'page_size' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * 手动重试轨迹拉取（权限 order.ship）
     * POST /admin/shippings/{id}/pull
     */
    public function pull(int $id): JsonResponse
    {
        $shipping = Shipping::query()->find($id);
        if (! $shipping) {
            return $this->fail('物流记录不存在', 40004);
        }

        $result = $this->tracePull->pull($shipping);
        $shipping->refresh();

        $data = [
            'result' => $result,
            'trace_status' => $shipping->trace_status,
            'pull_fail_count' => $shipping->pull_fail_count,
            'trace_count' => $shipping->traces()->count(),
        ];

        if ($result === TracePullService::RESULT_FAILED) {
            return $this->fail('轨迹拉取失败（'.$shipping->trace_status.'）', 40000, $data);
        }

        return $this->success($data, $result === TracePullService::RESULT_SKIPPED ? '未配置查询渠道，已跳过' : '拉取成功');
    }

    /**
     * 运单轨迹详情（V1.1 三期；权限 order.view）
     * GET /admin/shippings/{id}
     *
     * 与用户端 GET /orders/{id}/shipping 返回同一口径的轨迹时间线，
     * 便于客服在后台直接核对买家看到的物流信息。
     */
    public function show(int $id): JsonResponse
    {
        $shipping = Shipping::query()
            ->with('order:id,order_no')
            ->find($id);

        if (! $shipping) {
            return $this->fail('物流记录不存在', 40004);
        }

        $traces = $shipping->traces()->orderByDesc('occurred_at')->orderByDesc('id')->get();

        return $this->success([
            'id' => $shipping->id,
            'order_id' => $shipping->order_id,
            'order_no' => $shipping->order?->order_no,
            'company_code' => $shipping->company_code,
            'company_name' => $shipping->company_name,
            'tracking_no' => $shipping->tracking_no,
            'phone' => $shipping->phone,
            'trace_status' => $shipping->trace_status,
            'shipped_at' => $shipping->shipped_at?->toDateTimeString(),
            'delivered_at' => $shipping->delivered_at?->toDateTimeString(),
            'pull_fail_count' => $shipping->pull_fail_count,
            'last_fail_message' => $shipping->last_fail_message,
            'has_trace' => $traces->isNotEmpty(),
            'traces' => $traces->map(fn ($t) => [
                'context' => $t->context,
                'occurred_at' => $t->occurred_at->toDateTimeString(),
            ])->all(),
        ]);
    }

    /**
     * 打印电子面单（与出单侧配套；权限 order.view）
     * GET /admin/shippings/{id}/waybill?format=html|json
     *
     * 返回自包含的可打印 HTML 页面（默认），或 format=json 返回结构化模板供程序化消费。
     * 面单内容取自 shippings.waybill_data（发货时已落库 print_template），离线重打不依赖第三方。
     */
    public function waybillPrint(Request $request, int $id)
    {
        $shipping = Shipping::query()->with('order:id,order_no')->find($id);
        if (! $shipping) {
            return $this->fail('物流记录不存在', 40004);
        }

        $template = $shipping->resolvePrintTemplate();
        if ($template === null) {
            return $this->fail('该运单无可打印面单（未申请电子面单或渠道未返回面单数据）', 40022);
        }

        if (($request->query('format') ?? 'html') === 'json') {
            return $this->success([
                'tracking_no' => $shipping->tracking_no,
                'company_name' => $shipping->company_name,
                'company_code' => $shipping->company_code,
                'channel' => $shipping->waybill_channel,
                'printed_at' => $shipping->waybill_printed_at?->toDateTimeString(),
                'template' => $template,
            ]);
        }

        $this->operationLog->record(
            $request->user()->id,
            'order',
            'print_waybill',
            'shipping',
            $shipping->id,
            ['tracking_no' => $shipping->tracking_no, 'channel' => $shipping->waybill_channel],
        );

        return response($this->renderPrintPage($shipping, $template))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 渲染自包含打印页（含打印按钮 + 面单内容 + 打印样式）
     */
    private function renderPrintPage(Shipping $shipping, string $template): string
    {
        $tracking = htmlspecialchars((string) ($shipping->tracking_no ?? ''), ENT_QUOTES);
        $company = htmlspecialchars((string) ($shipping->company_name ?? ''), ENT_QUOTES);
        $orderNo = htmlspecialchars((string) ($shipping->order?->order_no ?? ''), ENT_QUOTES);
        $channel = htmlspecialchars((string) ($shipping->waybill_channel ?? ''), ENT_QUOTES);
        $printedAt = $shipping->waybill_printed_at?->format('Y-m-d H:i') ?? '';

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>电子面单 {$tracking}</title>
<style>
  @page { size: 100mm 150mm; margin: 6mm; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; color: #111;
    font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; }
  .wb-toolbar { position: fixed; top: 0; left: 0; right: 0; z-index: 9;
    display: flex; gap: 12px; align-items: center; padding: 10px 16px;
    background: #f5f5f5; border-bottom: 1px solid #ddd; }
  .wb-toolbar button { padding: 6px 16px; border: 1px solid #1677ff; background: #1677ff;
    color: #fff; border-radius: 6px; cursor: pointer; font-size: 14px; }
  .wb-toolbar .wb-meta { font-size: 12px; color: #666; }
  .wb-sheet { padding: 12px; }
  .wb-label { border: 1px dashed #bbb; border-radius: 8px; padding: 12px; min-height: 120mm; }
  .wb-label img { max-width: 100%; display: block; }
  @media print {
    .wb-toolbar { display: none !important; }
    .wb-sheet { padding: 0; }
  }
</style>
</head>
<body>
  <div class="wb-toolbar">
    <button type="button" onclick="window.print()">打印面单</button>
    <span class="wb-meta">运单号 {$tracking} ｜ 承运 {$company} ｜ 渠道 {$channel} ｜ 订单 {$orderNo} ｜ 出单 {$printedAt}</span>
  </div>
  <div class="wb-sheet">
    <div class="wb-label">{$template}</div>
  </div>
</body>
</html>
HTML;
    }

    /**
     * 当前轨迹查询渠道（V1.1 三期；权限 order.view）
     * GET /admin/shippings/channel
     *
     * ⚠️ 只回显「密钥是否已配置」，不返回 key/customer 明文。
     */
    public function channel(): JsonResponse
    {
        $configured = trim((string) ($this->config->get(self::CHANNEL_CONFIG_KEY) ?? ''));
        $effective = $configured === 'off' ? null : $configured;
        $effective = $effective !== '' ? $effective : config('services.shipping.channel');

        $channel = app(ShippingChannelInterface::class);

        return $this->success([
            // 后台配置值：'' 表示跟随 .env
            'configured' => $configured,
            // 实际生效渠道（null = 未启用）
            'channel' => $effective,
            'label' => $effective === null ? '未启用' : (self::CHANNEL_LABELS[$effective] ?? $effective),
            'source' => $configured === '' ? 'env' : 'database',
            // 渠道能否真正发起查询（密钥齐备）
            'available' => $channel->available(),
            'key_configured' => trim((string) config('services.shipping.key')) !== '',
            'customer_configured' => trim((string) config('services.shipping.customer')) !== '',
            'options' => self::CHANNEL_OPTIONS,
        ]);
    }

    /**
     * 切换轨迹查询渠道（V1.1 三期；权限 shipping.manage）
     * PUT /admin/shippings/channel  body: { channel: ''|kuaidi100|mock|off }
     *
     * 写入 system_configs 并即时生效（AppServiceProvider 每次启动用 DB 值覆盖 config）。
     * 密钥不在此处维护：key/customer 仍需在 .env 配置（凭证不入库）。
     */
    public function updateChannel(Request $request): JsonResponse
    {
        $data = $request->validate([
            // present + nullable：'' 是合法值（跟随 env），而 ConvertEmptyStringsToNull 会转成 null
            'channel' => ['present', 'nullable', 'string', 'max:20'],
        ]);

        $channel = trim((string) ($data['channel'] ?? ''));

        if (! in_array($channel, array_column(self::CHANNEL_OPTIONS, 'value'), true)) {
            return $this->fail('不支持的物流渠道', 40000, ['allowed' => array_column(self::CHANNEL_OPTIONS, 'value')]);
        }

        $this->config->set(self::CHANNEL_CONFIG_KEY, $channel);
        // 当前请求立即生效，无需等到下次启动
        config(['services.shipping.channel' => $channel === 'off' ? null : $channel]);
        // 渠道编码缓存随渠道切换失效
        Kuaidi100Channel::flushCache();

        $this->operationLog->record(
            $request->user()->id,
            'shipping',
            'switch_channel',
            'system_configs',
            null,
            ['shipping.channel' => $channel === '' ? '(跟随 .env)' : $channel],
        );

        return $this->success(
            ['configured' => $channel, 'channel' => $channel === 'off' ? null : $channel],
            $channel === '' ? '已恢复为跟随环境配置' : '物流渠道已切换',
        );
    }

    /**
     * 当前电子面单申请渠道（V1.2；权限 order.view）
     * GET /admin/shippings/waybill-channel
     *
     * ⚠️ 只回显「密钥是否已配置」，不返回 key/customer 明文。
     * 与 channel() 对称——区别在方向（此处是「发货时能否自动出单」）。
     */
    public function waybillChannel(): JsonResponse
    {
        $configured = trim((string) ($this->config->get(self::WAYBILL_CHANNEL_CONFIG_KEY) ?? ''));
        $effective = $configured === 'off' ? null : $configured;
        $effective = $effective !== '' ? $effective : config('services.waybill.channel');

        return $this->success([
            // 后台配置值：'' 表示跟随 .env
            'configured' => $configured,
            // 实际生效渠道（null = 未启用，发货走手动录入）
            'channel' => $effective,
            'label' => $effective === null ? '手动录入（未启用电子面单）' : (self::CHANNEL_LABELS[$effective] ?? $effective),
            'source' => $configured === '' ? 'env' : 'database',
            // 渠道能否真正发起申请（密钥齐备）
            'available' => $this->waybillChannel->available(),
            'key_configured' => trim((string) config('services.waybill.key')) !== '',
            'customer_configured' => trim((string) config('services.waybill.customer')) !== '',
            'options' => self::CHANNEL_OPTIONS,
        ]);
    }

    /**
     * 切换电子面单申请渠道（V1.2；权限 shipping.manage）
     * PUT /admin/shippings/waybill-channel  body: { channel: ''|kuaidi100|mock|off }
     *
     * 写入 system_configs 并即时生效（AppServiceProvider 每次启动用 DB 值覆盖 config）。
     * 密钥不在此处维护：key/customer 仍需在 .env 配置（凭证不入库）。
     */
    public function updateWaybillChannel(Request $request): JsonResponse
    {
        $data = $request->validate([
            // present + nullable：'' 是合法值（跟随 env），而 ConvertEmptyStringsToNull 会转成 null
            'channel' => ['present', 'nullable', 'string', 'max:20'],
        ]);

        $channel = trim((string) ($data['channel'] ?? ''));

        if (! in_array($channel, array_column(self::CHANNEL_OPTIONS, 'value'), true)) {
            return $this->fail('不支持的电子面单渠道', 40000, ['allowed' => array_column(self::CHANNEL_OPTIONS, 'value')]);
        }

        $this->config->set(self::WAYBILL_CHANNEL_CONFIG_KEY, $channel);
        // 当前请求立即生效，无需等到下次启动
        config(['services.waybill.channel' => $channel === 'off' ? null : $channel]);

        $this->operationLog->record(
            $request->user()->id,
            'shipping',
            'switch_waybill_channel',
            'system_configs',
            null,
            ['waybill.channel' => $channel === '' ? '(跟随 .env)' : $channel],
        );

        return $this->success(
            ['configured' => $channel, 'channel' => $channel === 'off' ? null : $channel],
            $channel === '' ? '已恢复为跟随环境配置' : '电子面单渠道已切换',
        );
    }
}
