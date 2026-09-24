<?php

namespace App\Services\Sms;

use App\Models\SmsConfig;
use App\Support\Sms\SmsProvider;

/**
 * 「配了服务商账号就默认启用」的落库动作（第一期：AuthController 改造）
 *
 * 需求：后台配好阿里云凭证后，注册/登录/重置密码默认走短信验证码，不必再逐个勾场景。
 *
 * 实现要点（这三条是刻意的设计，改之前请先读）：
 * 1. **只做一次性写入**。把已配模板的场景写进 `sms.code_scenes` 并打开总开关，
 *    写完就是普通配置值——管理员看得见、改得动、有操作日志。
 *    ⚠️ 绝不写成「运行时判断有没有配渠道」：那样管理员关掉渠道调试一下，
 *    前台登录/注册模式就跟着跳变，且无从审计。
 * 2. **Mock 渠道不触发**。迁移默认启用的就是 Mock，若把它算进去，本地开发会直接跳成
 *    短信模式；生产若忘换渠道则变成「所有人都收不到码」的静默事故。
 * 3. **没模板的场景不启用**。模板 CODE 是阿里云账号级别的，缺模板时启用只是让用户
 *    点发送后拿到一个发不出去的错误，比图形验证码体验更差。
 *
 * 触发时机：后台保存渠道配置后（{@see \App\Http\Controllers\Admin\SmsConfigController}）。
 * 若管理员手动提交过一次 `code_scenes`，`sms.code_scenes_auto` 会被置 0，此后本类不再改写任何东西。
 */
final class SmsAutoEnable
{
    public function __construct(private readonly SmsSettings $settings)
    {
    }

    /**
     * 按当前启用渠道同步「默认启用」的场景
     *
     * @return array<int, string>  实际写入的场景（空数组 = 本次未做任何改动）
     */
    public function sync(): array
    {
        // 管理员已手动接管：自动落库彻底让位，避免覆盖人工选择
        if (! $this->settings->scenesAuto()) {
            return [];
        }

        $config = SmsConfig::query()->where('is_enabled', true)->first();

        if ($config === null) {
            return [];
        }

        $provider = (string) $config->provider;

        // Mock 是开发/测试的兜底渠道，不算「配好了服务商账号」
        if ($provider === SmsProvider::MOCK || ! SmsProvider::isAvailable($provider)) {
            return [];
        }

        // 凭证不齐（含签名缺失）时不自动启用：走了真实渠道也只会拿到 isv.* 错误
        if (! $config->credentialsComplete()) {
            return [];
        }

        $scenes = $this->settings->scenesWithTemplate();

        if ($scenes === []) {
            return [];
        }

        // 总开关一并打开：否则配好了还得再点一次开关，「默认启用」就名不副实
        $this->settings->updateSwitches(['sms.enabled' => true]);
        $this->settings->applyAutoScenes($scenes);

        return $scenes;
    }
}
