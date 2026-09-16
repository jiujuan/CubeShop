<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Common\FileUploadService;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 支付（API 文档 7 / Roadmap P5）
 */
class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentChannelService $channels,
    ) {
    }

    /**
     * 发起支付：POST /payments {order_no, channel, extra?}
     *
     * channel ∈ wechat/alipay/balance/offline/mock；extra 仅线下转账需要
     * （payer_name/payer_account/transfer_no/transferred_at/voucher_url）。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_no' => ['required', 'string'],
            'channel' => ['required', 'string', 'in:wechat,alipay,balance,offline,mock'],
            'extra' => ['nullable', 'array'],
        ]);

        $order = Order::where('order_no', $data['order_no'])->first();
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        [$payment, $payParams] = $this->payments->createPayment(
            $order,
            $request->user()->id,
            $data['channel'],
            $data['extra'] ?? [],
        );

        return $this->success([
            'payment_no' => $payment->payment_no,
            'order_no' => $payment->order_no,
            'biz_type' => $payment->biz_type,
            'amount' => (string) $payment->amount,
            'channel' => $payment->channel,
            'status' => $payment->status,
            'pay_params' => $payParams,
        ]);
    }

    /** 支付状态查询（前端轮询）：GET /payments/{paymentNo} */
    public function show(Request $request, string $paymentNo): JsonResponse
    {
        $payment = $this->payments->queryByNo($paymentNo, $request->user()->id);

        return $this->success([
            'payment_no' => $payment->payment_no,
            'order_no' => $payment->order_no,
            'order_id' => $payment->order_id,
            'biz_type' => $payment->biz_type,
            'channel' => $payment->channel,
            'amount' => (string) $payment->amount,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'review_remark' => $payment->review_remark,
            'channel_trade_no' => $payment->channel_trade_no,
        ]);
    }

    /**
     * 收银台渠道列表（首屏）：GET /payments/channels?scene=order|recharge
     *
     * 返回已启用渠道（含余额/收款账户），后台改配置即生效，前端不写死。
     */
    public function channels(Request $request): JsonResponse
    {
        $scene = $request->query('scene', 'order');
        if (! in_array($scene, ['order', 'recharge'], true)) {
            $scene = 'order';
        }

        $data = $this->channels->cashierChannels($request->user()->id, $scene);

        return $this->success($data);
    }

    /**
     * 线下转账凭证上传：POST /user/upload-voucher（需登录，≤3MB，单日≤20张）
     */
    public function uploadVoucher(Request $request, FileUploadService $uploader): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $url = $uploader->uploadVoucher($request->file('file'), $request->user()->id);

        return $this->success(['url' => $url], '上传成功');
    }

    /**
     * 主动查单补偿（回调丢失时结果页触发，§7.2）
     *
     * 仅对在线渠道（wechat/alipay/mock）的 pending/reviewing 单生效；
     * 余额（同步完成）/线下（人工核账）无需主动查单，直接返回当前状态。
     */
    public function sync(Request $request, string $paymentNo): JsonResponse
    {
        $payment = $this->payments->queryByNo($paymentNo, $request->user()->id);

        $online = [Payment::CHANNEL_WECHAT, Payment::CHANNEL_ALIPAY, Payment::CHANNEL_MOCK];
        if (! in_array($payment->channel, $online, true)
            || ! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_REVIEWING], true)) {
            return $this->success([
                'payment_no' => $payment->payment_no,
                'status' => $payment->status,
                'synced' => false,
                'message' => '无需主动查单',
            ]);
        }

        $result = $this->payments->sync($payment);

        return $this->success([
            'payment_no' => $payment->payment_no,
            'status' => $payment->fresh()->status,
            'synced' => $result['ok'],
            'message' => $result['message'] ?? 'ok',
        ]);
    }

    /**
     * 渠道回调（API 文档 7.2）：POST /payments/callback/{channel}
     * 无需用户 Token，验签 + 幂等；返回渠道所需的应答。
     */
    public function callback(Request $request, string $channel): JsonResponse
    {
        $result = $this->payments->handleCallback($channel, (array) $request->input());

        return $this->success($result, $result['ok'] ? 'ok' : 'fail');
    }

    /**
     * 沙箱模拟渠道通知：POST /payments/sandbox/{paymentNo}
     * 开发环境模拟「用户在渠道完成支付」——内部生成签名走真实回调逻辑。
     */
    public function sandbox(Request $request, string $paymentNo): JsonResponse
    {
        $result = $this->payments->sandboxNotify($paymentNo, $request->input('result', 'success'));

        return $this->success($result);
    }
}
