<?php

namespace App\Support\Member;

/**
 * 积分类型字典与规则常量（会员成长计划 S1）
 *
 * 设计文档：docs/design/points-checkin-membership.md §5
 *
 * 只放**纯规则**：类型码、中文名、记账方向、幂等键格式。
 * 不依赖模型/DB/请求，可被 Service、控制器、测试任意引用（与 Support\Sms 的字典同体例）。
 *
 * ### 记账方向（DIRECTION_*）是什么
 *
 * 积分有「可用 / 冻结」两个池子，一笔业务可能只在池子间挪位置、并不改变用户真实持有量：
 *
 * - earn：用户真实拿到积分 → 累加 total_earn
 * - spend：用户真实消耗积分 → 累加 total_spend
 * - none：只是挪位置或回补（冻结/释放/退款返还）→ 不动累计值
 *
 * 之所以把「退款返还」也算 none，是因为它退的是**之前抵扣掉的**积分，
 * 计入 total_earn 会让「累计获得」虚高，对账时对不上。
 */
final class PointsRules
{
    /** 签到奖励（S2） */
    public const TYPE_SIGNIN = 'signin';

    /** 消费返积分（S3，按实付金额发放） */
    public const TYPE_EARN = 'earn';

    /** 积分抵扣确认消耗（S5，冻结 → 真实扣减） */
    public const TYPE_CONSUME = 'consume';

    /** 下单占用冻结（S5，可用 → 冻结） */
    public const TYPE_FREEZE = 'freeze';

    /** 冻结释放（S5，取消/超时/支付失败，冻结 → 可用） */
    public const TYPE_RELEASE = 'release';

    /** 退款返还抵扣的积分（S6） */
    public const TYPE_REFUND_RETURN = 'refund_return';

    /** 后台人工调整（S1，正负皆可） */
    public const TYPE_ADMIN_ADJUST = 'admin_adjust';

    public const TYPE_LABELS = [
        self::TYPE_SIGNIN => '签到奖励',
        self::TYPE_EARN => '消费返积分',
        self::TYPE_CONSUME => '积分抵扣',
        self::TYPE_FREEZE => '下单冻结',
        self::TYPE_RELEASE => '冻结释放',
        self::TYPE_REFUND_RETURN => '退款返还',
        self::TYPE_ADMIN_ADJUST => '后台调整',
    ];

    public const DIRECTION_EARN = 'earn';

    public const DIRECTION_SPEND = 'spend';

    public const DIRECTION_NONE = 'none';

    /**
     * 类型 → 记账方向
     *
     * ⚠️ 不含 admin_adjust：人工调整的方向由**符号**决定（加分为获得、减分为消耗），
     *    查表给不出答案，统一走 {@see self::directionOf()}。
     *
     * @var array<string, string>
     */
    public const DIRECTIONS = [
        self::TYPE_SIGNIN => self::DIRECTION_EARN,
        self::TYPE_EARN => self::DIRECTION_EARN,
        self::TYPE_CONSUME => self::DIRECTION_SPEND,
        self::TYPE_FREEZE => self::DIRECTION_NONE,
        self::TYPE_RELEASE => self::DIRECTION_NONE,
        self::TYPE_REFUND_RETURN => self::DIRECTION_NONE,
    ];

    /** 单次人工调整的绝对值上限（防止手抖多敲一个 0） */
    public const ADJUST_MAX = 100000;

    /**
     * 构造业务幂等键，如 order:12:earn
     *
     * 同一笔业务（同一 scope+id+动作）只允许产生一条流水，
     * 靠 user_point_logs.biz_key 的唯一索引兜底，防止回调重放重复加/减积分。
     */
    public static function bizKey(string $scope, int|string $id, string $action): string
    {
        return $scope.':'.$id.':'.$action;
    }

    /** 类型中文名 */
    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /**
     * 该笔流水的记账方向
     *
     * @param  int  $pointsDelta  可用积分变动（带符号）
     * @param  int  $frozenDelta  冻结积分变动（带符号）
     */
    public static function directionOf(string $type, int $pointsDelta = 0, int $frozenDelta = 0): string
    {
        // 人工调整：加分为获得，减分为消耗
        if ($type === self::TYPE_ADMIN_ADJUST) {
            return $pointsDelta > 0 ? self::DIRECTION_EARN : self::DIRECTION_SPEND;
        }

        return self::DIRECTIONS[$type] ?? self::DIRECTION_NONE;
    }
}
