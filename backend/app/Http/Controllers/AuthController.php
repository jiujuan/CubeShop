<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\AuthLog;
use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\User;
use App\Services\Auth\AuthLogService;
use App\Services\Common\CaptchaService;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use App\Support\WeakPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use App\Services\Sms\SmsCodeService;
use App\Services\Sms\SmsReadiness;
use App\Services\Sms\SmsSettings;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    use ApiResponse;

    /** 国内手机号（注册/登录/重置密码的短信分支共用） */
    private const PHONE_PATTERN = '/^1[3-9]\d{9}$/';

    /** SEC-08：账号占用类错误对外统一文案，不区分是用户名还是手机号撞库 */
    private const ACCOUNT_UNAVAILABLE = '该账号信息不可用，请更换后重试';

    public function __construct(
        private readonly AuthLogService $authLog,
        private readonly CaptchaService $captchaService,
        private readonly OperationLogService $operationLog,
        private readonly \App\Services\Common\ConfigService $config,
        private readonly \App\Services\Notification\NotificationService $notifications,
        private readonly \App\Services\Auth\LoginSecurityService $loginSecurity,
        private readonly \App\Services\Auth\DeviceTokenService $devices,
        private readonly SmsReadiness $readiness,
        private readonly SmsCodeService $smsCodes,
        private readonly SmsSettings $smsSettings,
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
     * 查询某场景当前用哪种验证码 GET /auth/verify-mode?scene=register
     *
     * 出参 `{mode: 'sms'|'captcha', code_length}` 是前端渲染的唯一依据：
     * 短信就绪就渲染「手机号 + 发送验证码」，否则渲染图形验证码。
     *
     * ⚠️ 这里只看**系统就绪度**，不看用户状态；用户级限制（频繁/超限）由发送接口返回。
     */
    public function verifyMode(Request $request): JsonResponse
    {
        $scene = (string) $request->input('scene', 'register');

        if (! $this->smsSettings->isKnownScene($scene)) {
            $scene = 'register';
        }

        $check = $this->readiness->check($scene);

        return $this->success([
            'scene' => $scene,
            'mode' => $check['ready'] ? 'sms' : 'captcha',
            'code_length' => $check['ready'] ? SmsCodeService::LENGTH : CaptchaService::LENGTH,
            'reason' => $check['reason'],
        ]);
    }

    /**
     * 发送短信验证码 POST /auth/send-sms-code
     *
     * body: { scene, phone, captcha_id, captcha_code }
     *
     * **图形验证码是必填的防刷闸门**：短信按条计费，一个不带图形码的发送口
     * 等于一个可被脚本刷的账单（限流只能减轻，不能替代前置的人机校验）。
     *
     * 两类失败要区别对待：
     * - 系统级不就绪（未启用 / 无模板 / 无渠道 / 生产走 Mock）→ **不报错**，返回
     *   `mode: 'captcha'` 让前端切回图形，保证注册登录不被配置问题打断；
     * - 用户级失败（发送过频 / 当日超限 / 被锁定）→ 明确抛错，否则他会一直点。
     *
     * ⚠️ 刻意**不校验**手机号是否已注册、账号是否存在：一旦校验就等于给出
     * 「该手机号是否注册过」的枚举 oracle（SEC-08），统一走成功/中性文案。
     */
    public function sendSmsCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scene' => ['required', 'string', 'in:'.implode(',', array_keys(SmsSettings::CODE_SCENES))],
            'phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'captcha_id' => ['required', 'string'],
            'captcha_code' => ['required', 'string', 'size:'.CaptchaService::LENGTH],
        ], [
            'phone.regex' => '请输入正确的手机号',
            'captcha_code.size' => '验证码为 '.CaptchaService::LENGTH.' 位',
        ]);

        if (! $this->captchaService->verify($data['captcha_id'], $data['captcha_code'])) {
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        $scene = $data['scene'];
        $check = $this->readiness->check($scene);

        if (! $check['ready']) {
            return $this->success([
                'sent' => false,
                'mode' => 'captcha',
                'code_length' => CaptchaService::LENGTH,
                'reason' => $check['reason'],
            ], '当前不支持短信验证码，请改用图形验证码');
        }

        $result = $this->smsCodes->send($scene, $data['phone']);

        if (! $result->ok) {
            // 渠道侧失败同样回退：别让用户卡在「点了发送但永远收不到」
            if (in_array($result->errorCode, SmsReadiness::FALLBACK_REASONS, true)) {
                return $this->success([
                    'sent' => false,
                    'mode' => 'captcha',
                    'code_length' => CaptchaService::LENGTH,
                    'reason' => $result->errorCode,
                ], '短信服务暂时不可用，请改用图形验证码');
            }

            throw BusinessException::badRequest($result->errorMsg ?: '验证码发送失败，请稍后再试');
        }

        return $this->success([
            'sent' => true,
            'mode' => 'sms',
            'code_length' => SmsCodeService::LENGTH,
            'resend_after' => SmsCodeService::RESEND_INTERVAL,
            'reason' => null,
        ], '验证码已发送');
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
        // 短信分支：前端按 /auth/verify-mode 的结果决定提交 phone+sms_code 还是 captcha_id+code
        $smsMode = $request->filled('sms_code') || $request->input('mode') === 'sms';

        $rules = [
            'username' => ['required', 'string', 'max:64', 'unique:users,username', $this->notUsedByAdmin('username')],
            'password' => ['required', 'string', ...$this->passwordRule()],
            'password_confirmation' => ['required', 'same:password'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone', $this->notUsedByAdmin('phone')],
            'email' => ['nullable', 'email', 'max:128', 'unique:users,email', $this->notUsedByAdmin('email')],
        ];

        // 应用 locale 为 en，密码规则的默认文案是英文；注册页是纯中文场景，
        // 422 只回一个笼统 message 会让用户把「密码不合格」误认成「验证码错了」。
        $messages = [
            'password.min' => '密码至少 8 位',
            'password.letters' => '密码需同时包含字母和数字',
            'password.numbers' => '密码需同时包含字母和数字',
        ];

        if ($smsMode) {
            // 验证码要发到手机上，手机号从「可选」变「必填且必须是合法号段」
            $rules['phone'] = ['required', 'string', 'max:20', 'regex:'.self::PHONE_PATTERN, 'unique:users,phone', $this->notUsedByAdmin('phone')];
            $rules['sms_code'] = ['required', 'string', 'size:'.SmsCodeService::LENGTH];

            // 免密注册（手机号即账号）：用户名与密码都可不填——不填用户名时以手机号作登录名，
            // 不填密码时由服务端生成随机强密码，用户今后凭「手机号 + 短信验证码」登录或重置密码。
            // 自设密码仍走同一套强度规则（SEC-05），不会因改走短信而放宽。
            $rules['username'] = ['nullable', 'string', 'max:64', 'unique:users,username', $this->notUsedByAdmin('username')];
            $rules['password'] = ['nullable', 'string', ...$this->passwordRule()];
            $rules['password_confirmation'] = ['nullable', 'required_with:password', 'same:password'];

            $messages['phone.regex'] = '请输入正确的手机号';
            $messages['sms_code.size'] = '短信验证码为 '.SmsCodeService::LENGTH.' 位';
        } else {
            $rules['code'] = ['required', 'string', 'size:'.CaptchaService::LENGTH];
            $messages['code.size'] = '验证码为 '.CaptchaService::LENGTH.' 位';
        }

        try {
            $data = $request->validate($rules, $messages);
        } catch (ValidationException $e) {
            // 注册校验失败（用户名/手机号/邮箱占用、密码强度、验证码位长等）：详尽记录但不向前端细分
            $this->authLog->record(AuthLog::EVENT_REGISTER, false, [
                'identifier' => $request->input('username'),
                'fail_reason' => 'validation',
                'detail' => ['fields' => array_keys($e->errors())],
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw $this->flattenAccountTaken($e);
        }

        $username = $data['username'] ?? null;
        $plainPassword = $data['password'] ?? null;

        if ($smsMode) {
            $this->assertSmsReady('register');

            // 手机号即账号：用户名留空时用它作登录名；若该手机号已被他人用作用户名，
            // 账号会冲突且无法静默改名，按 SEC-08 用不可区分文案拒绝。
            $username = ($username === null || $username === '') ? $data['phone'] : $username;

            if ($username === $data['phone'] && $this->usernameTaken($username)) {
                $this->authLog->record(AuthLog::EVENT_REGISTER, false, [
                    'identifier' => $username,
                    'fail_reason' => 'username_taken',
                ]);
                $request->attributes->set('auth_log_skip', true);
                throw BusinessException::badRequest(self::ACCOUNT_UNAVAILABLE);
            }

            if ($plainPassword === null || $plainPassword === '') {
                $plainPassword = $this->generateRandomPassword();
            }

            if (! $this->smsCodes->verify('register', $data['phone'], $data['sms_code'])) {
                $this->authLog->record(AuthLog::EVENT_REGISTER, false, [
                    'identifier' => $data['username'],
                    'fail_reason' => 'sms_code_error',
                ]);
                $request->attributes->set('auth_log_skip', true);
                throw BusinessException::badRequest('短信验证码错误或已过期');
            }
        } elseif (! $this->captchaService->verify((string) $request->input('captcha_id', ''), $data['code'])) {
            $this->authLog->record(AuthLog::EVENT_REGISTER, false, [
                'identifier' => $data['username'],
                'fail_reason' => 'captcha_error',
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        $user = User::create([
            'username' => $username,
            'password' => $plainPassword,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            // 免密注册的登录名就是手机号，昵称若跟着等于手机号会在前台直接露出完整号码
            'nickname' => ($smsMode && $username === ($data['phone'] ?? null))
                ? '用户'.substr($username, -4)
                : $username,
            'status' => 1,
        ]);

        $issued = $this->devices->issue($user, $request);

        // 注册成功留痕（买家）
        $this->authLog->record(AuthLog::EVENT_REGISTER, true, [
            'user_id' => $user->id,
            'actor_type' => AuthLog::ACTOR_CUSTOMER,
            'identifier' => $user->username,
            'device_id' => $issued['device_id'] ?? null,
            'token_id' => $issued['token_id'] ?? null,
        ]);

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
        // 短信验证码登录：提交 phone + sms_code 时走这条。
        // 密码登录始终可用，不受短信配置影响——「优先走短信」只体现在前端默认选中哪个 tab。
        if ($request->filled('sms_code') || $request->input('mode') === 'sms') {
            return $this->success($this->loginBySmsCode($request), '登录成功');
        }

        try {
            $data = $request->validate([
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
                'captcha_id' => ['required', 'string'],
                // 验证码恒为 5 位：位数不对直接 422，避免用户少输一位拿到笼统的「验证码错误」
                'captcha_code' => ['required', 'string', 'size:'.CaptchaService::LENGTH],
            ], [
                'captcha_code.size' => '验证码为 '.CaptchaService::LENGTH.' 位',
            ]);
        } catch (ValidationException $e) {
            $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                'identifier' => $request->input('username'),
                'fail_reason' => 'validation',
                'detail' => ['fields' => array_keys($e->errors())],
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw $e;
        }

        $identifier = $data['username'];

        // SEC-07：账号维度锁定先于密码校验——被锁账号即使密码正确也拒绝，避免"试出正确密码"
        try {
            $this->loginSecurity->assertNotLocked($identifier);
        } catch (BusinessException $e) {
            $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                'identifier' => $identifier,
                'actor_type' => $this->actorTypeOfIdentifier($identifier),
                'fail_reason' => 'account_locked',
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw $e;
        }

        if (! $this->captchaService->verify($data['captcha_id'], $data['captcha_code'])) {
            $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                'identifier' => $identifier,
                'actor_type' => $this->actorTypeOfIdentifier($identifier),
                'fail_reason' => 'captcha_error',
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        $user = $this->findAccount($identifier, $data['password']);

        if (! $user) {
            // 失败计数；解锁条件为"窗口内失败达阈值"，与 IP 无关
            $this->loginSecurity->recordFailure($identifier);

            // SEC-08：不区分"账号不存在"与"密码错误"
            $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                'identifier' => $identifier,
                'actor_type' => $this->actorTypeOfIdentifier($identifier),
                'fail_reason' => 'invalid_credential',
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw BusinessException::badRequest('用户名或密码错误');
        }

        return $this->success($this->completeLogin($user, $request, $identifier), '登录成功');
    }

    /**
     * 退出登录（吊销当前 Token）
     * POST /auth/logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $tokenId = $token?->getKey();

        $deviceId = $tokenId !== null
            ? \Illuminate\Support\Facades\DB::table('auth_tokens')->where('token_id', $tokenId)->value('id')
            : null;

        // 登出成功留痕
        $this->authLog->record(AuthLog::EVENT_LOGOUT, true, [
            'user_id' => $user->id,
            'actor_type' => $this->actorTypeOf($user),
            'identifier' => $user->username,
            'device_id' => $deviceId,
            'token_id' => $tokenId,
        ]);

        if ($token) {
            \Illuminate\Support\Facades\DB::table('auth_tokens')
                ->where('token_id', $token->getKey())
                ->delete();
            $token->delete();
        }

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
        $smsMode = $request->filled('sms_code') || $request->input('mode') === 'sms';

        $rules = [
            'target' => ['required', 'string'],
            'password' => ['required', 'string', ...$this->passwordRule()],
            'password_confirmation' => ['required', 'same:password'],
        ];
        $messages = [];

        if ($smsMode) {
            // 短信模式下 target 必须是手机号：码发到哪个号就重置哪个号
            $rules['target'] = ['required', 'string', 'regex:'.self::PHONE_PATTERN];
            $rules['sms_code'] = ['required', 'string', 'size:'.SmsCodeService::LENGTH];
            $messages['target.regex'] = '请输入正确的手机号';
            $messages['sms_code.size'] = '短信验证码为 '.SmsCodeService::LENGTH.' 位';
        } else {
            $rules['code'] = ['required', 'string', 'size:'.CaptchaService::LENGTH];
            $rules['captcha_id'] = ['required', 'string'];
            $messages['code.size'] = '验证码为 '.CaptchaService::LENGTH.' 位';
        }

        $data = $request->validate($rules, $messages);

        if ($smsMode) {
            $this->assertSmsReady('reset_password');

            if (! $this->smsCodes->verify('reset_password', $data['target'], $data['sms_code'])) {
                throw BusinessException::badRequest('短信验证码错误或已过期');
            }
        } elseif (! $this->captchaService->verify($data['captcha_id'], $data['code'])) {
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
     * 短信验证码登录（登录页「验证码登录」tab 走这条）
     *
     * 与密码登录共用 {@see self::completeLogin()}，保证状态检查、设备签发、留痕完全一致。
     * 不再要求图形验证码——发送验证码时已经过一次，重复校验只增加摩擦。
     */
    private function loginBySmsCode(Request $request): array
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'sms_code' => ['required', 'string', 'size:'.SmsCodeService::LENGTH],
        ], [
            'phone.regex' => '请输入正确的手机号',
            'sms_code.size' => '短信验证码为 '.SmsCodeService::LENGTH.' 位',
        ]);

        $phone = $data['phone'];
        // 先定位账号：锁定是按 identifier 维度累计的，得先知道用户名才能查到
        // 「密码登录失败累计出来的锁定」（此处结果不参与响应判断，防泄露统一在 SEC-08 分支）
        $user = SysUser::where('phone', $phone)->first()
            ?? User::where('phone', $phone)->first();

        // SEC-07：锁定检查必须**先于**验证码校验，否则被锁账号也能试出正确验证码。
        // ⚠️ 两个 identifier 都要查：密码登录的失败记在**用户名**上，短信登录记在**手机号**上，
        //    只查一个维度等于「换一种登录方式就能绕过锁定」。
        foreach (array_values(array_unique(array_filter([$phone, $user?->username]))) as $identifier) {
            try {
                $this->loginSecurity->assertNotLocked((string) $identifier);
            } catch (BusinessException $e) {
                $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                    'identifier' => $identifier,
                    'actor_type' => $this->actorTypeOfIdentifier((string) $identifier),
                    'fail_reason' => 'account_locked',
                ]);
                $request->attributes->set('auth_log_skip', true);
                throw $e;
            }
        }

        $this->assertSmsReady('login');

        if (! $this->smsCodes->verify('login', $phone, $data['sms_code'])) {
            $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                'identifier' => $phone,
                'actor_type' => $this->actorTypeOfIdentifier($phone),
                'fail_reason' => 'sms_code_error',
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw BusinessException::badRequest('验证码错误或已过期');
        }

        if (! $user) {
            // SEC-08：不透露手机号是否注册过（与密码登录的「用户名或密码错误」同款口径）
            $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                'identifier' => $phone,
                'fail_reason' => 'invalid_credential',
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw BusinessException::badRequest('验证码错误或账号信息不可用');
        }

        return $this->completeLogin($user, $request, $phone);
    }

    /**
     * 登录收尾（状态检查 → 清锁定 → 留痕 → 签发设备 Token）
     *
     * 密码登录与短信登录共用，避免两条路径的行为漂移（例如只在一侧检查封禁）。
     *
     * @return array{token: string, expires_at: mixed, user: array}
     */
    private function completeLogin(SysUser|User $user, Request $request, string $identifier): array
    {
        if ((int) $user->status !== 1) {
            $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                'user_id' => $user->id,
                'actor_type' => $this->actorTypeOf($user),
                'identifier' => $identifier,
                'fail_reason' => 'account_disabled',
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw BusinessException::conflict('账号已被禁用，请联系管理员');
        }

        if ($user->trashed()) {
            $this->authLog->record(AuthLog::EVENT_LOGIN, false, [
                'user_id' => $user->id,
                'actor_type' => $this->actorTypeOf($user),
                'identifier' => $identifier,
                'fail_reason' => 'account_deleted',
            ]);
            $request->attributes->set('auth_log_skip', true);
            throw BusinessException::conflict('账号已注销');
        }

        $this->loginSecurity->clear($identifier);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $issued = $this->devices->issue($user, $request);

        $this->authLog->record(AuthLog::EVENT_LOGIN, true, [
            'user_id' => $user->id,
            'actor_type' => $this->actorTypeOf($user),
            'identifier' => $identifier,
            'device_id' => $issued['device_id'] ?? null,
            'token_id' => $issued['token_id'] ?? null,
        ]);

        $this->operationLog->record($user->id, 'auth', 'login', null, null, null, $this->actorTypeOf($user));

        return [
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'user' => $this->formatUser($user),
        ];
    }

    /** 短信验证码是否可用；不可用时给出明确文案而不是让用户对着「验证码错误」发懵 */
    private function assertSmsReady(string $scene): void
    {
        if (! $this->readiness->check($scene)['ready']) {
            throw BusinessException::badRequest('短信验证码当前不可用，请改用图形验证码');
        }
    }

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

    /**
     * 免密注册时生成的随机强密码（含大小写字母与数字，16 位）
     *
     * 用户不感知这个密码：注册后凭「手机号 + 短信验证码」登录，或通过重置密码场景自助设置。
     * 之所以不把 password 列改成可空——那会让「无密码账号」成为一类新状态，后续每个登录入口
     * 都要判空；生成一个不可达的强密码，等于把这类账号收敛成普通账号。
     */
    private function generateRandomPassword(): string
    {
        return 'Cs'.Str::random(10).random_int(1000, 9999);
    }

    /** 用户名是否已被买家账号或后台账号占用（免密注册派生用户名前先查一次） */
    private function usernameTaken(string $username): bool
    {
        return User::where('username', $username)->exists()
            || SysUser::withTrashed()->where('username', $username)->exists();
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
     * 登录失败时账号归属无法确定（可能根本不存在），按标识是否命中 sys_user 做最佳推断，
     * 仅用于审计标注，不影响对外响应（SEC-08 仍统一文案）。
     */
    private function actorTypeOfIdentifier(string $identifier): string
    {
        if (SysUser::where('username', $identifier)->orWhere('phone', $identifier)->exists()) {
            return AuthLog::ACTOR_ADMIN;
        }

        return AuthLog::ACTOR_CUSTOMER;
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
