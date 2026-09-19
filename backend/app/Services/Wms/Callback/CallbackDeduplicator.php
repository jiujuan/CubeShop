<?php

namespace App\Services\Wms\Callback;

use App\Models\WmsCallbackDedup;
use Illuminate\Support\Facades\Cache;

/**
 * 回调幂等与防重放（WMS 计划 P3 / Step 3）
 *
 * 两层防线：
 * 1. **防重放**（同步层）：同一条原始报文短窗口内重复到达 → Cache 拒收（对方网络层重试风暴）；
 * 2. **业务幂等**（异步层）：同一 (provider, biz_no, msg_type, status_key) 事件只处理一次
 *    （`wms_callback_dedups` 唯一索引兜底）。
 *
 * claim 语义：先占坑后处理，**处理失败必须 `release()`**，否则对方重推会被误吞。
 */
class CallbackDeduplicator
{
    public function __construct(
        private readonly ?int $replayTtl = null,
    ) {}

    private function ttl(): int
    {
        return $this->replayTtl ?? (int) config('wms.callback.replay_ttl', 600);
    }

    /**
     * 防重放检查（同步层调用）。
     *
     * @return bool true=首次收到；false=短窗口内重复报文（重放）
     */
    public function claimRaw(string $provider, string $rawBody): bool
    {
        $key = 'wms:cb:raw:'.md5($provider.'|'.$rawBody);

        return (bool) Cache::add($key, 1, $this->ttl());
    }

    /**
     * 业务幂等占坑（异步层调用）。
     *
     * @return bool true=首次（本 Job 负责处理）；false=已处理过（直接跳过）
     */
    public function claimEvent(string $provider, string $bizNo, string $msgType, string $statusKey = ''): bool
    {
        $exists = WmsCallbackDedup::query()
            ->where('provider', $provider)
            ->where('biz_no', $bizNo)
            ->where('msg_type', $msgType)
            ->where('status_key', $statusKey)
            ->exists();

        if ($exists) {
            return false;
        }

        try {
            WmsCallbackDedup::create([
                'provider' => $provider,
                'biz_no' => $bizNo,
                'msg_type' => $msgType,
                'status_key' => $statusKey,
                'received_at' => now(),
            ]);

            return true;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // 并发窗口内另一条 Job 先占坑——视为重复
            return false;
        }
    }

    /** 处理失败时释放占坑（允许对方重推或本轮重试再处理） */
    public function releaseEvent(string $provider, string $bizNo, string $msgType, string $statusKey = ''): void
    {
        WmsCallbackDedup::query()
            ->where('provider', $provider)
            ->where('biz_no', $bizNo)
            ->where('msg_type', $msgType)
            ->where('status_key', $statusKey)
            ->delete();
    }
}
