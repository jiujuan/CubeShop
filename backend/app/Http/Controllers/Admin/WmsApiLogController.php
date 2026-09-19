<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\WmsApiLog;
use App\Services\Wms\Support\PayloadMasker;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 后台 WMS 调用日志（WMS 计划 P6 / F5，权限 wms.config.manage）
 *
 * 排障入口：按方向 / 接口 / 成功失败 / 时间筛选，详情展示**脱敏后**的出入站报文，
 * 并可直接复制 `request_id` 与后端日志对账。
 *
 * ⚠️ 安全红线：报文在 P2 落库时已过 {@see PayloadMasker}，这里对详情再兜一层
 * `mask()`——历史上可能存在脱敏规则扩充前落库的旧数据，出口处再抹一次可保证
 * 「页面上任何位置搜不到 AppSecret / 完整手机号」。
 */
class WmsApiLogController extends Controller
{
    use ApiResponse;

    /** 详情页/详情接口返回的报文字符上限，防止超长报文拖垮页面 */
    private const BODY_LIMIT = 20000;

    public function __construct(private readonly PayloadMasker $masker) {}

    /** GET /api/admin/wms/logs —— 调用日志列表 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['nullable', Rule::in([WmsApiLog::DIRECTION_OUTBOUND, WmsApiLog::DIRECTION_INBOUND])],
            'api_name' => ['nullable', 'string', 'max:64'],
            'success' => ['nullable', 'boolean'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'biz_no' => ['nullable', 'string', 'max:64'],
            'keyword' => ['nullable', 'string', 'max:64'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WmsApiLog::query()
            ->when($data['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($data['api_name'] ?? null, fn ($q, $v) => $q->where('api_name', $v))
            ->when(isset($data['success']), fn ($q) => $q->where('success', (bool) $data['success']))
            ->when($data['request_id'] ?? null, fn ($q, $v) => $q->where('request_id', 'like', "%{$v}%"))
            ->when($data['biz_no'] ?? null, fn ($q, $v) => $q->where('biz_no', 'like', "%{$v}%"))
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where(fn ($inner) => $inner
                    ->where('request_id', 'like', "%{$kw}%")
                    ->orWhere('biz_no', 'like', "%{$kw}%")
                    ->orWhere('error_msg', 'like', "%{$kw}%"));
            })
            ->when($data['created_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($data['created_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id');

        $page = $query->paginate((int) ($data['page_size'] ?? 15));
        $page->through(fn (WmsApiLog $log) => $this->row($log));

        return $this->paginated($page);
    }

    /** GET /api/admin/wms/logs/{id} —— 详情（含脱敏报文） */
    public function show(int $id): JsonResponse
    {
        $log = WmsApiLog::find($id);
        if (! $log) {
            throw BusinessException::notFound('日志不存在');
        }

        return $this->success($this->row($log, withBody: true));
    }

    // ==================== 出口 ====================

    /** @return array<string, mixed> */
    private function row(WmsApiLog $log, bool $withBody = false): array
    {
        $row = [
            'id' => $log->id,
            'direction' => $log->direction,
            'direction_label' => $log->direction === WmsApiLog::DIRECTION_OUTBOUND ? '出站' : '入站',
            'provider' => $log->provider,
            'api_name' => $log->api_name,
            'request_id' => $log->request_id,
            'biz_no' => $log->biz_no,
            'http_status' => $log->http_status,
            'success' => (bool) $log->success,
            'error_msg' => $log->error_msg,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($withBody) {
            $row['request_body'] = $this->body($log->request_body);
            $row['response_body'] = $this->body($log->response_body);
        }

        return $row;
    }

    /**
     * 报文出口：先脱敏再截断。
     *
     * @param  mixed  $body  已 cast 成数组的报文（旧数据可能是字符串）
     */
    private function body(mixed $body): mixed
    {
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : $body;
        }

        $masked = $this->masker->mask(is_array($body) ? $body : []);

        $encoded = json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false && strlen($encoded) > self::BODY_LIMIT) {
            return json_decode(substr($encoded, 0, self::BODY_LIMIT), true)
                ?? ['_truncated' => true, '_preview' => substr($encoded, 0, self::BODY_LIMIT)];
        }

        return $masked;
    }
}
