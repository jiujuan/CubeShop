<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\PaymentChannel;
use App\Models\SysOperationLog;
use App\Services\Common\OperationLogService;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台支付渠道配置（收银台方案 §5，权限 payment.channel.manage，仅超管）
 *
 * 敏感字段统一脱敏回显、写入加密、编辑留空不覆盖；提供启用停用与连接测试。
 */
class PaymentChannelController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentChannelService $channels,
        private readonly PaymentGatewayFactory $gateways,
        private readonly OperationLogService $opLog,
    ) {}

    /**
     * 渠道列表（敏感字段全部脱敏）
     * GET /admin/payment-channels
     */
    public function index(): JsonResponse
    {
        $this->channels->ensurePresets();

        $list = $this->channels->all()->map(fn (PaymentChannel $c) => $this->row($c))->all();

        return $this->success(['list' => $list]);
    }

    /**
     * 渠道详情（敏感字段返回掩码 + has_xxx 布尔位）
     * GET /admin/payment-channels/{channel}
     */
    public function show(string $channel): JsonResponse
    {
        $record = $this->channels->find($channel);
        if (! $record) {
            throw BusinessException::notFound('渠道不存在');
        }

        return $this->success($this->row($record));
    }

    /**
     * 更新渠道配置（敏感字段为空表示不覆盖）
     * PUT /admin/payment-channels/{channel}
     */
    public function update(Request $request, string $channel): JsonResponse
    {
        $record = $this->channels->find($channel);
        if (! $record) {
            throw BusinessException::notFound('渠道不存在');
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:50'],
            'config' => ['nullable', 'array'],
            'config.*' => ['nullable', 'string', 'max:4096'],
            'sandbox' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'notify_url' => ['nullable', 'string', 'max:255'],
            'return_url' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $before = $record->only(['name', 'enabled', 'sandbox', 'notify_url', 'return_url']);

        $savedConfig = $record->config ?? [];
        $changed = [];
        if (array_key_exists('config', $data) && is_array($data['config'])) {
            $built = $this->channels->buildConfigForSave($channel, $data['config']);
            $savedConfig = $built['config'];
            $changed = $built['changed'];
        }

        $record->forceFill([
            'name' => $data['name'] ?? $record->name,
            'config' => $savedConfig,
            'sandbox' => $data['sandbox'] ?? $record->sandbox,
            'enabled' => $data['enabled'] ?? $record->enabled,
            'notify_url' => $data['notify_url'] ?? $record->notify_url,
            'return_url' => $data['return_url'] ?? $record->return_url,
            'remark' => $data['remark'] ?? $record->remark,
            'updated_by' => $request->user()->id,
        ])->save();

        $this->opLog->record($request->user()->id, 'payment_channel', 'update', 'payment_channel', $record->id, [
            'channel' => $channel,
            'changed_fields' => $changed,
        ]);

        return $this->success($this->row($record->fresh()), '渠道配置已保存');
    }

    /**
     * 启用 / 停用渠道
     * POST /admin/payment-channels/{channel}/toggle  body: { enabled? }
     */
    public function toggle(Request $request, string $channel): JsonResponse
    {
        $record = $this->channels->find($channel);
        if (! $record) {
            throw BusinessException::notFound('渠道不存在');
        }

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
        ]);

        $enabled = $data['enabled'] ?? ! $record->enabled;
        $before = $record->enabled;
        $record->forceFill(['enabled' => $enabled, 'updated_by' => $request->user()->id])->save();

        $this->opLog->record($request->user()->id, 'payment_channel', 'toggle', 'payment_channel', $record->id, [
            'channel' => $channel,
            'before' => $before,
            'after' => $enabled,
        ]);

        return $this->success($this->row($record->fresh()), $enabled ? '已启用' : '已停用');
    }

    /**
     * 连接测试（调用网关 testConnection，不落库）
     * POST /admin/payment-channels/{channel}/test
     */
    public function test(Request $request, string $channel): JsonResponse
    {
        $record = $this->channels->find($channel);
        if (! $record) {
            throw BusinessException::notFound('渠道不存在');
        }

        if (! $this->channels->isConfigured($channel)) {
            return $this->success([
                'ok' => false,
                'message' => '渠道参数未配置齐全，无法测试连接',
                'detail' => [],
            ]);
        }

        $gateway = $this->gateways->make($channel);
        $result = $gateway->testConnection($this->channels->decryptedConfig($channel));

        return $this->success($result->toArray());
    }

    /**
     * 渠道配置变更历史（复用 sys_operation_log，module=payment_channel）
     * GET /admin/payment-channels/{channel}/logs
     */
    public function logs(Request $request, string $channel): JsonResponse
    {
        $pageSize = min(max($request->integer('page_size', 20), 1), 100);

        $logs = SysOperationLog::query()
            ->where('module', 'payment_channel')
            ->where('target_type', 'payment_channel')
            ->where('target_id', PaymentChannel::query()->where('channel', $channel)->value('id') ?? 0)
            ->orderByDesc('id')
            ->paginate($pageSize);

        return $this->paginated($logs->through(fn (SysOperationLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'content' => $log->content,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ]));
    }

    /** 列表 / 详情共用结构（敏感字段脱敏） */
    private function row(PaymentChannel $c): array
    {
        $channel = $c->channel;

        return [
            'channel' => $channel,
            'name' => $c->name,
            'sort' => $c->sort,
            'enabled' => $c->enabled,
            'sandbox' => $c->sandbox,
            'is_online' => in_array($channel, [PaymentChannel::CHANNEL_WECHAT, PaymentChannel::CHANNEL_ALIPAY], true),
            'is_configured' => $this->channels->isConfigured($channel),
            'config' => $this->channels->maskedConfig($channel),
            'notify_url' => $c->notify_url,
            'return_url' => $c->return_url,
            'remark' => $c->remark,
            'updated_at' => $c->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
