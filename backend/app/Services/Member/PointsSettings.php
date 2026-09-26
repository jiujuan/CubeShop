<?php

namespace App\Services\Member;

use App\Services\Common\ConfigService;
use App\Support\Member\SigninReward;

/**
 * 积分运营开关（`points.*` 配置的唯一读口，会员成长计划 S2）
 *
 * 设计文档：docs/design/points-checkin-membership.md §7 / §12
 *
 * 与 `SmsSettings` / `SearchConfig` 同款做法：规则值**只从这里读**，
 * 后台在 S7 的「会员与积分」页改完立刻生效（ConfigService 有缓存，写入时自动 flush）。
 * 散落各处直接 `config()` 读的后果是后台改了不生效、且查不出配置项在哪。
 *
 * ⚠️ 存储格式：`system_configs.config_value` 是字符串列，所以 JSON 值（bonus）存字符串、
 *    开关存 `'1'/'0'`。别在这里 cast 成 bool 再写回去，会与其它配置的读写体例不一致。
 */
final class PointsSettings
{
    /** 后台可维护的开关：`键 => 默认值` */
    public const SWITCHES = [
        'points.enabled' => '1',
        'points.signin_enabled' => '1',
        'points.signin_base' => (string) SigninReward::DEFAULT_BASE,
        'points.signin_step' => (string) SigninReward::DEFAULT_STEP,
        'points.signin_max' => (string) SigninReward::DEFAULT_MAX,
        'points.signin_bonus' => '{"7":50}',
    ];

    public function __construct(private readonly ConfigService $config)
    {
    }

    /** 积分总开关（关闭后前台签到不可用，已积累的积分不受影响） */
    public function enabled(): bool
    {
        return $this->flag('points.enabled');
    }

    /** 签到开关 */
    public function signinEnabled(): bool
    {
        return $this->flag('points.signin_enabled');
    }

    /** 签到是否可用（两个开关都要开） */
    public function signinAvailable(): bool
    {
        return $this->enabled() && $this->signinEnabled();
    }

    /**
     * 签到奖励参数
     *
     * @return array{base: int, step: int, max: int, bonus: array<string, int>}
     */
    public function signinConfig(): array
    {
        return [
            'base' => $this->int('points.signin_base', SigninReward::DEFAULT_BASE),
            'step' => $this->int('points.signin_step', SigninReward::DEFAULT_STEP),
            'max' => $this->int('points.signin_max', SigninReward::DEFAULT_MAX),
            'bonus' => $this->bonus(),
        ];
    }

    /**
     * 里程碑奖励映射（连续天数 => 额外积分）
     *
     * @return array<string, int>
     */
    public function bonus(): array
    {
        $raw = (string) $this->config->get('points.signin_bonus', self::SWITCHES['points.signin_bonus']);

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return SigninReward::DEFAULT_BONUS;
        }

        $bonus = [];
        foreach ($decoded as $day => $points) {
            // 天数必须为正整数且在循环周期内，否则前端进度条会画不出这个里程碑
            $day = (int) $day;
            if ($day > 0 && $day <= SigninReward::CYCLE_DAYS) {
                $bonus[(string) $day] = max(0, (int) $points);
            }
        }

        return $bonus;
    }

    /**
     * 更新开关（只认 {@see self::SWITCHES} 白名单内的键）
     *
     * S7 的规则配置页与测试共用此入口，避免各处拼字符串写配置。
     *
     * @param  array<string, mixed>  $values
     */
    public function updateSwitches(array $values): void
    {
        foreach (self::SWITCHES as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if ($key === 'points.signin_bonus') {
                $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
            }

            $value = (string) $value;

            // ⚠️ 不能用 `(string) $value ?: $default` —— PHP 里 '0' 是 falsy，
            //    关开关会被错误地回退成默认值 1（关不掉）。
            $this->config->set($key, $value === '' ? $default : $value);
        }
    }

    private function flag(string $key): bool
    {
        return $this->config->get($key, self::SWITCHES[$key]) === '1';
    }

    private function int(string $key, int $default): int
    {
        return $this->config->getInt($key, $default);
    }
}
