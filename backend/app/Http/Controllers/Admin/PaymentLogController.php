<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\PaymentLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台支付日志（payment_logs，API 文档 8.12）
 *
 * 只读：用于排查渠道回调与异步通知问题。
 * 列表只回传摘要（避免 JSON 载荷过大），详情回传完整 request / response。
 */
class PaymentLogController extends Controller
{
    use ApiResponse;

    /** 列表摘要的最大长度 */
    private const PREVIEW_LENGTH = 160;

    /**
     * 支付日志列表
     * GET /admin/payment-logs?payment_no=&event=&start_time=&end_time=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_no' => ['nullable', 'string', 'max:64'],
            'event' => ['nullable', 'string', 'max:32'],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = PaymentLog::query()
            ->when($data['payment_no'] ?? null, fn ($q, $v) => $q->where('payment_no', 'like', '%'.$v.'%'))
            ->when($data['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($data['start_time'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($data['end_time'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (PaymentLog $log) => $this->row($log));

        return $this->paginated($paginator);
    }

    /**
     * 支付日志详情（完整 request / response）
     * GET /admin/payment-logs/{id}
     */
    public function show(int $id): JsonResponse
    {
        $log = PaymentLog::query()->find($id);

        if (! $log) {
            throw BusinessException::notFound('支付日志不存在');
        }

        return $this->success($this->row($log) + [
            'request_data' => $log->request_data,
            'response_data' => $log->response_data,
        ]);
    }

    private function row(PaymentLog $log): array
    {
        return [
            'id' => $log->id,
            'payment_id' => $log->payment_id,
            'payment_no' => $log->payment_no,
            'event' => $log->event,
            'event_label' => $log->event_label,
            'request_preview' => $this->preview($log->request_data),
            'response_preview' => $this->preview($log->response_data),
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** JSON 摘要（单行，超长截断） */
    private function preview(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_strlen($json) > self::PREVIEW_LENGTH
            ? mb_substr($json, 0, self::PREVIEW_LENGTH).'…'
            : $json;
    }
}
