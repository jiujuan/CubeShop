<?php

namespace App\Services\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * 登录设备管理（SEC-06）
 *
 * Sanctum 默认"签发后永久有效"，且无从得知 Token 来自哪台设备。
 * 本服务补齐两件事：
 * 1. 签发 Token 时记录来源指纹（IP / UA / 设备标签），写 `auth_tokens`；
 * 2. 提供设备列表与踢下线能力，让用户与管理员能主动吊销可疑会话。
 *
 * 说明：Token 有效期由 `SANCTUM_TOKEN_EXPIRATION` 统一控制（见 config/sanctum.php），
 * 本服务不参与过期判断。
 */
final class DeviceTokenService
{
    /**
     * 签发 Token 并记录设备指纹
     *
     * @return array{token: string, device_id: int, expires_at: string|null}
     */
    public function issue(Model $user, Request $request, string $name = 'api'): array
    {
        /** @var NewAccessToken $newToken */
        $newToken = $user->createToken($name);

        $deviceId = DB::table('auth_tokens')->insertGetId([
            'token_id' => $newToken->accessToken->getKey(),
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'device_label' => $this->labelFrom($request->userAgent()),
            'created_at' => now(),
        ]);

        return [
            'token' => $newToken->plainTextToken,
            'device_id' => $deviceId,
            'expires_at' => $this->expiresAt(),
        ];
    }

    /**
     * 最近登录设备列表（当前用户自己的会话）
     *
     * 只返回 `personal_access_tokens` 中仍存在的记录——被吊销的 Token 不应出现在列表里。
     */
    public function devices(Model $user): array
    {
        $currentId = $user->currentAccessToken()?->getKey();

        $rows = DB::table('auth_tokens')
            ->join('personal_access_tokens', 'personal_access_tokens.id', '=', 'auth_tokens.token_id')
            ->where('auth_tokens.tokenable_type', $user->getMorphClass())
            ->where('auth_tokens.tokenable_id', $user->getKey())
            ->orderByDesc('personal_access_tokens.last_used_at')
            ->orderByDesc('auth_tokens.id')
            ->get([
                'auth_tokens.id',
                'auth_tokens.token_id',
                'auth_tokens.ip',
                'auth_tokens.user_agent',
                'auth_tokens.device_label',
                'auth_tokens.created_at',
                'personal_access_tokens.name',
                'personal_access_tokens.last_used_at',
            ]);

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'token_id' => (int) $row->token_id,
            'name' => $row->name,
            'ip' => $row->ip,
            'device_label' => $row->device_label,
            'user_agent' => $row->user_agent,
            'created_at' => $row->created_at,
            'last_used_at' => $row->last_used_at,
            'current' => $currentId !== null && (int) $row->token_id === (int) $currentId,
        ])->all();
    }

    /**
     * 踢下线：吊销指定设备对应的 Token
     *
     * 仅能吊销**自己的**会话（按 tokenable 过滤），防止越权踢别人下线。
     */
    public function revoke(Model $user, int $deviceId): bool
    {
        $row = DB::table('auth_tokens')
            ->where('id', $deviceId)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->first();

        if (! $row) {
            return false;
        }

        // 真正生效的是删除 Sanctum 的 Token 记录；本表行仅用于展示与审计
        $user->tokens()->where('id', $row->token_id)->delete();
        DB::table('auth_tokens')->where('id', $deviceId)->delete();

        return true;
    }

    /**
     * 轮换 Token：签发新 Token 并吊销当前这一个
     *
     * 用于配合 Token 有效期——短有效期 + 可轮换，既限制泄露后的可用窗口，
     * 又不至于让用户在浏览过程中被强制登出。
     */
    public function refresh(Model $user, Request $request): ?array
    {
        $current = $user->currentAccessToken();
        if (! $current) {
            return null;
        }

        $issued = $this->issue($user, $request, $current->name ?: 'api');

        $current->delete();
        DB::table('auth_tokens')->where('token_id', $current->getKey())->delete();

        return $issued;
    }

    /**
     * 当前配置的 Token 过期时间（ISO8601），未配置返回 null
     */
    private function expiresAt(): ?string
    {
        $minutes = config('sanctum.expiration');

        return is_numeric($minutes) ? now()->addMinutes((int) $minutes)->toIso8601String() : null;
    }

    /**
     * 由 User-Agent 生成人类可读的设备标签
     *
     * 不做完整 UA 解析（避免引入依赖），只识别主流浏览器与操作系统，识别不出返回 null。
     */
    private function labelFrom(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        $ua = strtolower($userAgent);

        $os = match (true) {
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') => 'iOS',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'linux') => 'Linux',
            default => '未知系统',
        };

        $browser = match (true) {
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'chrome/') && ! str_contains($ua, 'chromium') => 'Chrome',
            str_contains($ua, 'safari/') && ! str_contains($ua, 'chrome') => 'Safari',
            str_contains($ua, 'firefox/') => 'Firefox',
            str_contains($ua, 'postmanruntime') => 'Postman',
            str_contains($ua, 'curl/') => 'curl',
            default => '未知浏览器',
        };

        return $browser.' · '.$os;
    }
}
