<?php

namespace App\Support\Member;

/**
 * 签到奖励算法（会员成长计划 S2）
 *
 * 设计文档：docs/design/points-checkin-membership.md §7
 *
 * **纯函数**：不碰 DB、不读请求、不依赖容器。所有入参显式传入，
 * 便于单测直接钉死每一档奖励（连续 1~7 天、断签、上限、里程碑）。
 * 规则值由 {@see \App\Services\Member\PointsSettings} 从 `system_configs` 读出后传入。
 *
 * ### 两个独立规则，别混为一谈
 *
 * 1. **连续递增**：`base + (streak - 1) × step`，再被 `max` 截断
 * 2. **里程碑奖励**：连续满 7 天额外加 50（`bonus` 映射），与递增奖励**叠加**
 *
 * ### 7 日循环（D7）
 *
 * 第 8 天 streak 归 1，奖励也回到第一档 —— 这是有意设计的「周期重置」，
 * 而不是 bug：它让每个周期都有一次里程碑高峰（第 7 天），
 * 同时避免无限连续时奖励被 max 卡死在天花板、后期毫无波动。
 */
final class SigninReward
{
    /** 连续天数循环周期（D7：第 8 天归 1） */
    public const CYCLE_DAYS = 7;

    public const DEFAULT_BASE = 5;

    public const DEFAULT_STEP = 2;

    public const DEFAULT_MAX = 30;

    /** 默认里程碑：`连续天数 => 额外积分` */
    public const DEFAULT_BONUS = ['7' => 50];

    /**
     * 下一个连续天数（7 日循环）
     *
     * 0 或 7 → 1；1 → 2；…；6 → 7。
     */
    public static function nextStreak(int $currentStreak): int
    {
        if ($currentStreak <= 0) {
            return 1;
        }

        return ($currentStreak % self::CYCLE_DAYS) + 1;
    }

    /**
     * 连续递增部分（不含里程碑奖励）
     *
     * min(base + (streak - 1) × step, max)：streak 从 1 起算，第 1 天即 base。
     */
    public static function progressive(int $streak, int $base, int $step, int $max): int
    {
        if ($streak <= 0) {
            return 0;
        }

        $value = $base + ($streak - 1) * $step;

        return max(0, min($value, $max));
    }

    /**
     * 里程碑奖励
     *
     * @param  array<string|int, int>  $bonus  连续天数（字符串键，JSON 反序列化后如此）=> 额外积分
     */
    public static function bonus(int $streak, array $bonus): int
    {
        foreach ($bonus as $day => $points) {
            if ((int) $day === $streak) {
                return max(0, (int) $points);
            }
        }

        return 0;
    }

    /**
     * 某连续天数当天实发积分（递增 + 里程碑）
     *
     * @param  array{base: int, step: int, max: int, bonus: array<string|int, int>}  $config
     */
    public static function pointsFor(int $streak, array $config): int
    {
        return self::progressive($streak, $config['base'], $config['step'], $config['max'])
            + self::bonus($streak, $config['bonus']);
    }

    /**
     * 里程碑天数列表（前端日历/进度条展示用），按天数升序
     *
     * @param  array<string|int, int>  $bonus
     * @return list<int>
     */
    public static function milestoneDays(array $bonus): array
    {
        $days = array_map('intval', array_keys($bonus));
        sort($days);

        return $days;
    }
}
