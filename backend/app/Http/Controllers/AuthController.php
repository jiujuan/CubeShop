<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\SysUser;
use App\Services\Common\CaptchaService;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CaptchaService $captchaService,
        private readonly OperationLogService $operationLog,
        private readonly \App\Services\Common\ConfigService $config,
        private readonly \App\Services\Notification\NotificationService $notifications,
    ) {}

    /**
     * 获取图形验证码（登录页）
     * POST /auth/captcha
     *
     * @param scene admin=后台管理端（默认）；web=用户端（风格与管理端区分）
     */
    public function captcha(Request $request)
    {
        $scene = (string) $request->input('scene', 'admin');

        return $this->success($this->captchaService->generate($scene));
    }

    /**
     * 注册（对齐 API 文档 2.1；短信验证码 V1.0 用图形验证码降级）
     * POST /auth/register
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'unique:sys_user,username'],
            'password' => ['required', 'string', Password::min(6)],
            'password_confirmation' => ['required', 'same:password'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:sys_user,phone'],
            'email' => ['nullable', 'email', 'max:128', 'unique:sys_user,email'],
            'code' => ['required', 'string'],
        ]);

        // V1.0 简化：code 为注册图形验证码
        if (! $this->captchaService->verify((string) $request->input('captcha_id', ''), $data['code'])) {
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        $user = SysUser::create([
            'username' => $data['username'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'nickname' => $data['username'],
            'status' => 1,
        ]);

        // 注册默认买家角色（无后台权限）
        $user->assignRole('customer');

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], '注册成功');
    }

    /**
     * 登录（用户名/手机号 + 密码 + 图形验证码）
     * POST /auth/login
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'captcha_id' => ['required', 'string'],
            'captcha_code' => ['required', 'string'],
        ]);

        if (! $this->captchaService->verify($data['captcha_id'], $data['captcha_code'])) {
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        $user = SysUser::where('username', $data['username'])
            ->orWhere('phone', $data['username'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw BusinessException::badRequest('用户名或密码错误');
        }

        if ($user->status !== 1) {
            throw BusinessException::conflict('账号已被禁用，请联系管理员');
        }

        if ($user->trashed()) {
            throw BusinessException::conflict('账号已注销');
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $token = $user->createToken('api')->plainTextToken;

        $this->operationLog->record($user->id, 'auth', 'login');

        return $this->success([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], '登录成功');
    }

    /**
     * 退出登录（吊销当前 Token）
     * POST /auth/logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, '已退出登录');
    }

    /**
     * 当前登录用户信息（含角色与权限码，供动态菜单）
     * GET /auth/me
     */
    public function me(Request $request)
    {
        return $this->success($this->formatUser($request->user(), withPermissions: true));
    }

    /**
     * 修改密码（已登录）
     * POST /auth/password
     */
    public function changePassword(Request $request)
    {
        $min = $this->config->getInt('auth.password_min_length', 8);
        $max = $this->config->getInt('auth.password_max_length', 32);
        $requireMixed = (bool) $this->config->getInt('auth.password_require_mixed', 1);

        $rules = ['required', 'string', "min:{$min}", "max:{$max}", 'different:old_password'];
        if ($requireMixed) {
            $rules[] = 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/';
        }

        $data = $request->validate([
            'old_password' => ['required', 'current_password:sanctum'],
            'password' => $rules,
            'password_confirmation' => ['required', 'same:password'],
        ], [
            'password.regex' => "新密码需 {$min}~{$max} 位，且同时包含字母与数字",
            'password.min' => "新密码需 {$min}~{$max} 位，且同时包含字母与数字",
            'password.max' => "新密码需 {$min}~{$max} 位，且同时包含字母与数字",
            'password.different' => '新密码不能与当前密码相同',
        ]);

        $user = $request->user();
        $user->password = Hash::make($data['password']);
        $user->save();

        // 撤销其他设备 Token，保留当前请求所用 Token（V1.1 E05-B / T-027）
        $currentTokenId = $user->currentAccessToken()?->id;
        $revoked = $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        // 安全通知：站内信为兜底主通道，邮件视 notify.mail_types 配置（T-018）
        $this->notifications->send(
            $user->id,
            \App\Services\Notification\NotificationService::TYPE_PASSWORD_CHANGED,
            '密码已变更',
            '您的账号密码已成功修改。如非本人操作，请立即联系客服并重新登录检查账号安全。',
            '/account',
        );

        $this->operationLog->record($user->id, 'auth', 'change_password', 'sys_user', $user->id, [
            'revoked_tokens' => $revoked,
        ]);

        return $this->success(['revoked_tokens' => $revoked], '密码修改成功，其他设备已退出登录');
    }

    /**
     * 重置密码（对齐 API 文档 2.5；V1.0 无短信网关，验证码用图形验证码降级）
     * POST /auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'target' => ['required', 'string'],
            'code' => ['required', 'string'],
            'captcha_id' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(6)],
            'password_confirmation' => ['required', 'same:password'],
        ]);

        if (! $this->captchaService->verify($data['captcha_id'], $data['code'])) {
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        $user = SysUser::where('username', $data['target'])
            ->orWhere('phone', $data['target'])
            ->orWhere('email', $data['target'])
            ->first();

        if (! $user) {
            throw BusinessException::notFound('账号不存在');
        }

        $user->password = $data['password'];
        $user->save();

        // 重置后吊销全部 Token，强制重新登录
        $user->tokens()->delete();

        $this->operationLog->record($user->id, 'auth', 'reset_password');

        return $this->success(null, '密码重置成功，请重新登录');
    }

    private function formatUser(SysUser $user, bool $withPermissions = false): array
    {
        $data = [
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar,
            'phone' => $user->phone,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ];

        if ($withPermissions) {
            $data['permissions'] = $user->getAllPermissions()->pluck('name');
        }

        return $data;
    }
}
