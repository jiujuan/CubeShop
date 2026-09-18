<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\User;
use App\Services\Common\CaptchaService;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use App\Support\WeakPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CaptchaService $captchaService,
        private readonly OperationLogService $operationLog,
        private readonly \App\Services\Common\ConfigService $config,
        private readonly \App\Services\Notification\NotificationService $notifications,
        private readonly \App\Services\Auth\LoginSecurityService $loginSecurity,
        private readonly \App\Services\Auth\DeviceTokenService $devices,
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
        // SEC-08：同 IP 每日注册上限（防批量注册薅券），先于业务校验执行
        $this->loginSecurity->assertRegisterAllowed((string) $request->ip());

        // SEC-08：注册接口的 `unique` 校验失败会精确指出"用户名已存在 / 手机号已存在"，
        // 等于给了攻击者一个可批量探测用户是否注册的 oracle。此处统一改写为不可区分的文案。
        try {
            $data = $request->validate([
                'username' => ['required', 'string', 'max:64', 'unique:users,username', $this->notUsedByAdmin('username')],
                'password' => ['required', 'string', ...$this->passwordRule()],
                'password_confirmation' => ['required', 'same:password'],
                'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone', $this->notUsedByAdmin('phone')],
                'email' => ['nullable', 'email', 'max:128', 'unique:users,email', $this->notUsedByAdmin('email')],
                'code' => ['required', 'string'],
            ], [
                // 应用 locale 为 en，密码规则的默认文案是英文；注册页是纯中文场景，
                // 422 只回一个笼统 message 会让用户把「密码不合格」误认成「验证码错了」。
                'password.min' => '密码至少 8 位',
                'password.letters' => '密码需同时包含字母和数字',
                'password.numbers' => '密码需同时包含字母和数字',
            ]);
        } catch (ValidationException $e) {
            throw $this->flattenAccountTaken($e);
        }

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

        $issued = $this->devices->issue($user, $request);

        return $this->success([
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
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

        // SEC-07：账号维度锁定先于密码校验——被锁账号即使密码正确也拒绝，避免"试出正确密码"
        $this->loginSecurity->assertNotLocked($data['username']);

        if (! $this->captchaService->verify($data['captcha_id'], $data['captcha_code'])) {
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        $user = $this->findAccount($data['username'], $data['password']);

        if (! $user) {
            // 失败计数；解锁条件为"窗口内失败达阈值"，与 IP 无关
            $this->loginSecurity->recordFailure($data['username']);

            // SEC-08：不区分"账号不存在"与"密码错误"
            throw BusinessException::badRequest('用户名或密码错误');
        }

        if ((int) $user->status !== 1) {
            throw BusinessException::conflict('账号已被禁用，请联系管理员');
        }

        if ($user->trashed()) {
            throw BusinessException::conflict('账号已注销');
        }

        $this->loginSecurity->clear($data['username']);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $issued = $this->devices->issue($user, $request);

        $this->operationLog->record($user->id, 'auth', 'login', null, null, null, $this->actorTypeOf($user));

        return $this->success([
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'user' => $this->formatUser($user),
        ], '登录成功');
    }

    /**
     * 退出登录（吊销当前 Token）
     * POST /auth/logout
     */
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token) {
            \Illuminate\Support\Facades\DB::table('auth_tokens')
                ->where('token_id', $token->getKey())
                ->delete();
        }
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, '已退出登录');
    }

    /**
     * 最近登录设备列表（SEC-06）
     * GET /auth/devices
     */
    public function devices(Request $request)
    {
        return $this->success([
            'list' => $this->devices->devices($request->user()),
        ]);
    }

    /**
     * 踢下线：吊销指定设备的 Token（SEC-06）
     * DELETE /auth/devices/{id}
     */
    public function revokeDevice(Request $request, int $id)
    {
        $ok = $this->devices->revoke($request->user(), $id);
        if (! $ok) {
            throw BusinessException::notFound('设备不存在');
        }

        return $this->success(null, '已踢下线');
    }

    /**
     * 轮换 Token（SEC-06）
     * POST /auth/refresh
     *
     * 短有效期 Token 的配套能力：签发新 Token 并立即吊销当前这一个，
     * 使"有效期到期"不必表现为用户被强制登出。
     */
    public function refresh(Request $request)
    {
        $issued = $this->devices->refresh($request->user(), $request);
        if (! $issued) {
            throw BusinessException::badRequest('当前会话不支持轮换');
        }

        return $this->success([
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
        ], '已续期');
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
        // SEC-05：长度与复杂度之外，还需排除常见弱口令
        $rules[] = WeakPassword::rule();

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
            'password' => ['required', 'string', ...$this->passwordRule()],
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
            // SEC-08：不透露账号是否存在（原实现返回"账号不存在"，是可枚举 oracle）
            throw BusinessException::badRequest('验证码错误或账号信息不可用');
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
    /**
     * 统一密码强度规则（SEC-05）
     *
     * 历史为 `Password::min(6)`——六位纯数字即可通过，撞库成本极低。
     * 现要求：至少 8 位且同时含字母与数字，并命中弱口令黑名单时拒绝。
     */
    private function passwordRule(): array|Password
    {
        return [
            Password::min(8)->letters()->numbers(),
            WeakPassword::rule(),
        ];
    }

    /**
     * 把注册接口的"账号已占用"错误压平成不可区分的文案（SEC-08）
     *
     * 仅改写 username / phone / email 三个字段的唯一性冲突，
     * 其余字段（如密码强度、验证码）的错误信息保持原样，避免误伤正常提示。
     */
    private function flattenAccountTaken(ValidationException $e): ValidationException
    {
        $errors = $e->errors();
        $accountFields = ['username', 'phone', 'email'];

        $hasTaken = false;
        foreach ($accountFields as $field) {
            if (! isset($errors[$field])) {
                continue;
            }
            $hasTaken = true;
            unset($errors[$field]);
        }

        if (! $hasTaken) {
            return $e;
        }

        // 错误挂在通用字段上，前端按"账号信息不可用"整体提示
        $errors['account'] = ['该账号信息不可用，请更换后重试'];

        return ValidationException::withMessages($errors);
    }

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

        // SEC-04-B：当前用户「自身」的主键属于单条自有记录，攻击者无法据此枚举全表，
        // 故 self-profile（login/register/me/refresh）直接返回内部 id。public_id（SCOPE_USER）
        // 仅用于跨用户引用场景，本系统公开列表当前不暴露作者 user_id，故此处无需编码。
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
