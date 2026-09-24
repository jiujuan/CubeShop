<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsConfig;
use App\Services\Common\OperationLogService;
use App\Services\Sms\SmsAutoEnable;
use App\Services\Sms\SmsLogService;
use App\Services\Sms\SmsReadiness;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsSettings;
use App\Support\ApiResponse;
use App\Support\Sms\SmsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 短信渠道后台配置（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D7
 *
 * 权限：读 `sms.view`、写 `sms.manage`（两者当前都只授予 super_admin —— 短信凭证等同于花钱的钥匙）。
 *
 * ⚠️ 三条硬约定：
 * 1. **Secret 只出不进**：接口只回掩码（`****abcd`）与 `has_secret` 布尔，
 *    且 `SmsConfig` 把密文列放进了 `$hidden`，序列化时不可能带出去；
 * 2. **Secret 留空 = 不修改**（全局中间件 `ConvertEmptyStringsToNull` 会把 `''` 变 null，
 *    null 与缺省一律视为「不改」），避免前端误传空串把凭证清空；
 * 3. **启用切换在事务里「先关旧、再开新」**，保证全局最多一行 `is_enabled = true`。
 */
class SmsConfigController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OperationLogService $operationLog,
        private readonly SmsSettings $settings,
        private readonly SmsService $smsService,
        private readonly SmsLogService $logService,
        private readonly SmsAutoEnable $autoEnable,
        private readonly SmsReadiness $readiness,
    ) {
    }

    /**
     * 渠道列表 + 当前生效渠道 + 运营开关 GET /admin/sms/config
     *
     * 出参区分「配置值」与「实际生效」：`degraded = true` 表示配的是阿里云但实际走 Mock
     * （非生产环境凭证缺失时的兜底），页面据此提示「当前不会发出真实短信」。
     */
    public function config(): JsonResponse
    {
        $active = $this->smsService->active();

        $channels = SmsConfig::query()
            ->orderBy('id')
            ->get()
            ->map(fn (SmsConfig $c) => $this->channelPayload($c))
            ->all();

        return $this->success([
            'channels' => $channels,
            'active' => [
                'provider' => $active['provider'],
                'configured_provider' => $active['config']?->provider,
                'degraded' => $active['degraded'],
                'error' => $active['error'],
            ],
            'switches' => [
                'enabled' => $this->settings->enabled(),
                'code_scenes' => $this->settings->codeScenes(),
                'scenes_auto' => $this->settings->scenesAuto(),
                'code_templates' => json_decode(
                    (string) $this->settings->switches()['sms.code_templates'],
                    true,
                ) ?? [],
            ],
            // 每个场景的就绪度与未生效原因：勾了场景却看不到效果时，管理员据此自查
            'scene_status' => collect(SmsSettings::CODE_SCENES)
                ->map(fn (string $label, string $key) => $this->readiness->describe($key))
                ->all(),
            'options' => [
                'scenes' => SmsSettings::CODE_SCENES,
                'providers' => collect(SmsProvider::ALL)
                    ->map(fn ($label, $key) => [
                        'key' => $key,
                        'label' => $label,
                        'available' => SmsProvider::isAvailable($key),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * 更新运营开关 PUT /admin/sms/config
     *
     * body: { enabled?: bool, code_scenes?: string[], code_templates?: object }
     * 省略（或 null）的键一律「不改动」。
     */
    public function updateSwitches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'code_scenes' => ['nullable', 'array'],
            'code_templates' => ['nullable', 'array'],
        ]);

        $before = $this->settings->switches();

        $payload = [];

        if (array_key_exists('enabled', $data) && $data['enabled'] !== null) {
            $payload['sms.enabled'] = (bool) $data['enabled'];
        }

        if (array_key_exists('code_scenes', $data) && $data['code_scenes'] !== null) {
            $payload['sms.code_scenes'] = $data['code_scenes'];
        }

        if (array_key_exists('code_templates', $data) && $data['code_templates'] !== null) {
            $payload['sms.code_templates'] = $data['code_templates'];
        }

        $this->settings->updateSwitches($payload);

        $this->operationLog->record(
            $request->user()?->id,
            'sms',
            'switches.update',
            'SystemConfig',
            null,
            ['before' => $before, 'after' => $this->settings->switches()],
        );

        return $this->success([
            'switches' => [
                'enabled' => $this->settings->enabled(),
                'code_scenes' => $this->settings->codeScenes(),
                'code_templates' => json_decode((string) $this->settings->switches()['sms.code_templates'], true) ?? [],
            ],
        ], '已保存');
    }

    /**
     * 更新某渠道凭证 PUT /admin/sms/config/{id}
     *
     * body: { name?, access_key_id?, access_key_secret?, sign_name?, region?, is_enabled? }
     *
     * ⚠️ `access_key_secret` 留空/null = 不修改（无法从后台清空，避免误操作把线上凭证抹掉）。
     */
    public function updateChannel(Request $request, int $id): JsonResponse
    {
        $config = SmsConfig::query()->findOrFail($id);

        if (! SmsProvider::isAvailable((string) $config->provider)) {
            return $this->fail('该服务商尚未支持（'.SmsProvider::label((string) $config->provider).' 将在二期提供）', 1, null, 422);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:64'],
            'access_key_id' => ['nullable', 'string', 'max:128'],
            'access_key_secret' => ['nullable', 'string', 'max:256'],
            'sign_name' => ['nullable', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:32'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $before = $this->channelPayload($config);

        DB::transaction(function () use ($config, $data) {
            foreach (['name', 'access_key_id', 'sign_name', 'region'] as $field) {
                if (isset($data[$field])) {
                    $config->{$field} = $data[$field];
                }
            }

            // Secret 例外：null/空串 = 不修改（见类注释第 2 条）
            if (! empty($data['access_key_secret'])) {
                $config->access_key_secret = $data['access_key_secret'];
            }

            // 启用切换：事务内先关掉其它行，保证全局最多一个启用渠道
            if (isset($data['is_enabled']) && (bool) $data['is_enabled']) {
                SmsConfig::query()->where('id', '!=', $config->id)->update(['is_enabled' => false]);
                $config->is_enabled = true;
            } elseif (isset($data['is_enabled'])) {
                $config->is_enabled = false;
            }

            $config->save();
        });

        // 「配好服务商账号就默认启用」：仅当管理员从未手动设置过场景时才写入
        $autoScenes = $this->autoEnable->sync();

        $this->operationLog->record(
            $request->user()?->id,
            'sms',
            'config.update',
            'SmsConfig',
            $config->id,
            [
                'provider' => $config->provider,
                'before' => $before,
                'after' => $this->channelPayload($config->fresh()),
                'auto_scenes' => $autoScenes,
            ],
        );

        $message = '已保存';

        if ($autoScenes !== []) {
            $labels = array_map(fn (string $scene) => SmsSettings::CODE_SCENES[$scene] ?? $scene, $autoScenes);
            $message .= '，已自动启用短信验证码：'.implode('、', $labels);
        }

        return $this->success($this->channelPayload($config->fresh()), $message);
    }

    /**
     * 测试发送 POST /admin/sms/test
     *
     * 走真实渠道链路（含落 sms_logs，scene=test），因此**必须限流**：
     * 路由层 `throttle:sms-send`（同 IP 每分钟 `SMS_SEND_RATE_LIMIT` 次）。
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'template_code' => ['required', 'string', 'max:64'],
            'params' => ['nullable', 'array'],
        ], [
            'phone.regex' => '请填写正确的手机号',
        ]);

        $result = $this->smsService->send(
            $data['phone'],
            $data['template_code'],
            $data['params'] ?? [],
            'test',
        );

        $this->operationLog->record(
            $request->user()?->id,
            'sms',
            'test.send',
            'SmsLog',
            null,
            [
                'phone_masked' => substr($data['phone'], 0, 3).'****'.substr($data['phone'], -4),
                'template_code' => $data['template_code'],
                'ok' => $result->ok,
                'error_code' => $result->errorCode,
            ],
        );

        $payload = [
            'ok' => $result->ok,
            'error_code' => $result->errorCode,
            'error_msg' => $result->errorMsg,
            'biz_id' => $result->providerMessageId,
            'latency_ms' => $result->latencyMs,
        ];

        return $result->ok
            ? $this->success($payload, '已发送')
            : $this->fail($result->errorMsg ?? '发送失败', 1, $payload);
    }

    /**
     * 发送记录分页 GET /admin/sms/logs
     *
     * 只回脱敏数据（库里本来就只有脱敏手机号）。
     */
    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'max:16'],
            'scene' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->logService->paginate(
            [
                'provider' => $data['provider'] ?? null,
                'status' => $data['status'] ?? null,
                'scene' => $data['scene'] ?? null,
                'phone' => $data['phone'] ?? null,
            ],
            (int) ($data['page_size'] ?? 20),
        );

        return $this->paginated($paginator);
    }

    /**
     * 渠道出参（Secret 只回掩码）
     *
     * @return array<string, mixed>
     */
    private function channelPayload(?SmsConfig $config): array
    {
        if ($config === null) {
            return [];
        }

        return [
            'id' => $config->id,
            'provider' => $config->provider,
            'provider_label' => $config->providerLabel(),
            'available' => SmsProvider::isAvailable((string) $config->provider),
            'name' => $config->name,
            'access_key_id' => $config->access_key_id,
            'has_secret' => $config->hasAccessKeySecret(),
            'secret_masked' => $config->maskedAccessKeySecret(),
            'sign_name' => $config->sign_name,
            'region' => $config->region,
            'is_enabled' => (bool) $config->is_enabled,
            'credentials_complete' => $config->credentialsComplete(),
            'missing_credentials' => $config->missingCredentials(),
            'remark' => $config->remark,
        ];
    }
}
