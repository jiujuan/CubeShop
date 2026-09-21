<?php

namespace App\Support\Shipping;

use App\Models\ExpressCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 快递100 智能单号识别（V1.1 三期）
 *
 * 接口：POST https://www.kuaidi100.com/autonumber/auto，参数仅 `num` + `key`，**无签名**。
 * 成功返回候选数组（相似度降序）：[{"lengthPre":15,"comCode":"yuantong","name":"圆通速递"}]
 * 失败返回：{"returnCode":"601","message":"key过期","result":false}
 *   601 未开通（需先充值查询/订阅套餐，随后免费）/ 701 key 缺失 / 201 单号不合规。
 *
 * ⚠️ 官方明确不保证 100% 准确：本服务只做**提示与校验**，绝不可据此自动改写商家录入的公司。
 * 一切失败（未配置、超时、错误码）统一降级为空数组，不阻断发货。
 */
class Kuaidi100AutoNumber
{
    /** @var array<string, ?string> 渠道编码 → 内部 code，进程内缓存 */
    private static array $codeCache = [];

    /** 失败只记一次，避免批量校验时刷屏 */
    private static bool $failureLogged = false;

    public function available(): bool
    {
        return (bool) config('services.shipping.autonumber_enabled', true) && $this->key() !== '';
    }

    /** 清空进程内编码缓存与「失败仅记一次」标记（字典变更 / 测试隔离用） */
    public static function flushCache(): void
    {
        self::$codeCache = [];
        self::$failureLogged = false;
    }

    /**
     * 识别运单号可能所属的快递公司。
     *
     * @return list<array{code:string, name:string}> 候选（内部 code，相似度降序）；无法识别时返回 []
     */
    public function detect(string $trackingNo): array
    {
        $trackingNo = trim($trackingNo);

        if (! $this->available() || $trackingNo === '') {
            return [];
        }

        try {
            $response = Http::asForm()
                ->timeout(max(1, (int) config('services.shipping.timeout', 8)))
                ->post((string) config('services.shipping.autonumber_url'), [
                    'key' => $this->key(),
                    'num' => $trackingNo,
                ]);
        } catch (\Throwable $e) {
            $this->logFailure('请求异常：'.$e->getMessage());

            return [];
        }

        if (! $response->successful()) {
            $this->logFailure('HTTP '.$response->status());

            return [];
        }

        $body = $response->json();

        // 错误分支：{"returnCode":"601", ...}
        if (is_array($body) && isset($body['returnCode'])) {
            $this->logFailure('returnCode='.$body['returnCode'].' '.((string) ($body['message'] ?? '')));

            return [];
        }

        if (! is_array($body)) {
            return [];
        }

        $candidates = [];
        foreach ($body as $item) {
            if (! is_array($item)) {
                continue;
            }
            $channelCode = trim((string) ($item['comCode'] ?? ''));
            if ($channelCode === '') {
                continue;
            }
            $internal = $this->toInternalCode($channelCode);
            if ($internal === null) {
                // 识别出的快递公司不在本平台字典内（未启用），跳过而非误导
                continue;
            }
            $candidates[] = [
                'code' => $internal,
                'name' => (string) ($item['name'] ?? '') ?: $internal,
            ];
        }

        return $candidates;
    }

    /**
     * 首个候选的内部 code；无候选返回 null。
     *
     * 用于与商家填写的公司编码比对，不一致即提示复核。
     */
    public function topCode(string $trackingNo): ?string
    {
        $candidates = $this->detect($trackingNo);

        return $candidates[0]['code'] ?? null;
    }

    /** 渠道编码 → 内部 code；字典未收录返回 null（未启用的公司不该被推荐） */
    private function toInternalCode(string $channelCode): ?string
    {
        if (array_key_exists($channelCode, self::$codeCache)) {
            return self::$codeCache[$channelCode];
        }

        $code = ExpressCompany::query()
            ->where('channel_code', $channelCode)
            ->value('code');

        return self::$codeCache[$channelCode] = ($code !== null ? (string) $code : null);
    }

    private function logFailure(string $reason): void
    {
        if (self::$failureLogged) {
            return;
        }
        self::$failureLogged = true;

        Log::warning('快递100 智能单号识别不可用，已降级跳过', ['reason' => trim($reason)]);
    }

    private function key(): string
    {
        return trim((string) config('services.shipping.key'));
    }
}
