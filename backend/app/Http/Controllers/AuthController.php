<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\User;
use App\Services\Common\CaptchaService;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

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
     * @param  scene  admin=后台管理端（默认）；web=用户端（风格与管理端区分）
     */
    public function captcha(Request $request)
    {
        $scene = (string) $request->input('scene', 'admin');

        return $this->success($this->captchaService->generate($scene));
    }

    /**
     * 注册（对齐 API 文档 2.1；短信验证码 V1.0 用图形验证码降级）
     * POST /auth/register
     *
     * 买家写入 `users` 表，不参与 spatie 权限体系（不再分配 customer 角色）。
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'unique:users,username', $this->notUsedByAdmin('username')],
            'password' => ['required', 'string', Password::min(6)],
            'password_confirmation' => ['required', 'same:password'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone', $this->notUsedByAdmin('phone')],
            'email' => ['nullable', 'email', 'max:128', 'unique:users,email', $this->notUsedByAdmin('email')],
            'code' => ['required', 'string'],
        ]);

        // V1.0 简化：code 为注册图形验证码
        if (! $this->captchaService->verify((string) $request->input('captcha_id', ''), $data['code'])) {
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        $user = User::create([
            'username' => $data['username'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'nickname' => $data['username'],
            'status' => 1,
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], '注册成功');
    }

    /**
     * 登录（用户名/手机号 + 密码 + 图形验证码）
     * POST /auth/login
     *
     * 账号来源：管理员（sys_user）优先，其次买家（users）。
     * 管理员优先可保证后台账号永不被买家账号遮蔽；买家注册时已禁止占用后台账号的
     * 用户名/手机号/邮箱（见 notUsedByAdmin），故两侧不会产生歧义。
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

        $user = $this->findAccount($data['username'], $data['password']);

        if (! $user) {
            throw BusinessException::badRequest('用户名或密码错误');
        }

        if ((int) $user->status !== 1) {
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

        $this->operationLog->record($user->id, 'auth', 'login', null, null, null, $this->actorTypeOf($user));

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
     * 当前登录身份信息（含角色与权限码，供动态菜单）
     * GET /auth/me
     *
     * 买家无角色与权限码（不参与 spatie），返回空数组。
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
            $user instanceof SysUser
                ? \App\Models\Notification::RECEIVER_ADMIN
                : \App\Models\Notification::RECEIVER_CUSTOMER,
        );

        $this->operationLog->record(
            $user->id,
            'auth',
            'change_password',
            $this->actorTypeOf($user) === SysOperationLog::ACTOR_ADMIN ? 'sys_user' : 'users',
            $user->id,
            ['revoked_tokens' => $revoked],
            $this->actorTypeOf($user),
        );

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
            ->first()
            ?? User::where('username', $data['target'])
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

        $this->operationLog->record($user->id, 'auth', 'reset_password', null, null, null, $this->actorTypeOf($user));

        return $this->success(null, '密码重置成功，请重新登录');
    }

    /**
     * 按标识（用户名或手机号）与密码查找账号
     *
     * 先查后台管理员（sys_user），再查买家（users）。
     */
    private function findAccount(string $identifier, string $password): SysUser|User|null
    {
        $admin = SysUser::where('username', $identifier)->orWhere('phone', $identifier)->first();
        if ($admin && Hash::check($password, $admin->password)) {
            return $admin;
        }

        $buyer = User::where('username', $identifier)->orWhere('phone', $identifier)->first();
        if ($buyer && Hash::check($password, $buyer->password)) {
            return $buyer;
        }

        return null;
    }

    /** 操作人身份类型（用于操作日志归属） */
    private function actorTypeOf(SysUser|User $user): string
    {
        return $user instanceof SysUser
            ? SysOperationLog::ACTOR_ADMIN
            : SysOperationLog::ACTOR_CUSTOMER;
    }

    /**
     * 注册校验：该字段不得占用后台管理员的同名标识
     *
     * 目的是避免「买家与管理员同名」在同一登录入口产生歧义。
     */
    private function notUsedByAdmin(string $column): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($column): void {
            if ($value === null || $value === '') {
                return;
            }

            if (SysUser::withTrashed()->where($column, $value)->exists()) {
                $fail(match ($column) {
                    'phone' => '该手机号已被占用',
                    'email' => '该邮箱已被占用',
                    default => '该用户名已被占用',
                });
            }
        };
    }

    private function formatUser(SysUser|User $user, bool $withPermissions = false): array
    {
        $isAdmin = $user instanceof SysUser;

        $data = [
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar,
            'phone' => $user->phone,
            'email' => $user->email,
            // 买家不参与 spatie 权限体系，角色与权限码为空
            'roles' => $isAdmin ? $user->getRoleNames() : [],
        ];

        if ($withPermissions) {
            $data['permissions'] = $isAdmin ? $user->getAllPermissions()->pluck('name') : [];
        }

        return $data;
    }
}
