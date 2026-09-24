<?php

namespace App\Services\Sms;

use App\Models\SmsLog;
use App\Support\Mask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 短信发送记录：写入与后台查询（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D5
 *
 * 两条安全约定在写入侧强制落地，调用方想违反都难：
 * 1. **手机号先脱敏再入库** —— 明文手机号由本方法内部 `Mask::phone()` 处理，
 *    调用方传入明文即可，不存在「忘了脱敏」的写法；
 * 2. **模板参数不入库** —— `record()` 的签名里根本没有 params 这一项，
 *    验证码场景的参数就是验证码明文，落库等于把验证码写进日志。
 */
class SmsLogService
{
    /**
     * 落一条发送记录
     *
     * @param  string  $phone  明文手机号（内部脱敏）
     */
    public function record(
        ?int $configId,
        string $provider,
        string $phone,
        string $scene,
        ?string $templateCode,
        string $status,
        ?string $errorCode = null,
        ?string $errorMsg = null,
        ?string $bizId = null,
        ?int $latencyMs = null,
    ): SmsLog {
        return SmsLog::query()->create([
            'sms_config_id' => $configId,
            'provider' => $provider,
            'phone_masked' => Mask::phone($phone),
            'scene' => $scene,
            'template_code' => $templateCode,
            'status' => $status,
            'error_code' => $errorCode,
            'error_msg' => $errorMsg !== null ? mb_substr($errorMsg, 0, 255) : null,
            'biz_id' => $bizId,
            'latency_ms' => $latencyMs,
        ]);
    }

    /**
     * 后台发送记录分页查询
     *
     * 入参全部可选，未传即不过滤；手机号按**脱敏值模糊匹配**（库里没有明文，只能这样查）。
     *
     * @param  array{provider?: string, status?: string, scene?: string, phone?: string}  $filters
     */
    public function paginate(array $filters = [], int $pageSize = 20): LengthAwarePaginator
    {
        $pageSize = min(100, max(1, $pageSize));

        return SmsLog::query()
            ->when(
                ! empty($filters['provider']),
                fn (Builder $q) => $q->where('provider', (string) $filters['provider']),
            )
            ->when(
                ! empty($filters['status']),
                fn (Builder $q) => $q->where('status', (string) $filters['status']),
            )
            ->when(
                ! empty($filters['scene']),
                fn (Builder $q) => $q->where('scene', (string) $filters['scene']),
            )
            ->when(! empty($filters['phone']), function (Builder $q) use ($filters) {
                $needle = trim((string) $filters['phone']);

                $q->where('phone_masked', 'like', '%'.$needle.'%');
            })
            ->orderByDesc('id')
            ->paginate($pageSize);
    }
}
