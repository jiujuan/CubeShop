<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shipping;
use App\Services\Common\ConfigService;
use App\Services\Common\OperationLogService;
use App\Services\Shipping\TracePullService;
use App\Support\Shipping\Kuaidi100Channel;
use App\Support\Shipping\ShippingChannelInterface;
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
}
