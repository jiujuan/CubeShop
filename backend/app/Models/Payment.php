<?php

namespace App\Models;

use App\Casts\MediaPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 支付记录（数据库设计 2.7，收银台方案 §4.2）
 *
 * V1.2 起支付单同时承载两类业务（biz_type）：
 * - order    ：订单支付，order_id / order_no 非空
 * - recharge ：余额充值，order_id / order_no 为空，biz_no 为充值单号
 */
class Payment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CLOSED = 'closed';
    /** 线下转账：已提交凭证，等待后台核账 */
    public const STATUS_REVIEWING = 'reviewing';

    public const CHANNEL_WECHAT = 'wechat';
    public const CHANNEL_ALIPAY = 'alipay';
    public const CHANNEL_BALANCE = 'balance';
    public const CHANNEL_OFFLINE = 'offline';
    public const CHANNEL_MOCK = 'mock';

    public const BIZ_TYPE_ORDER = 'order';
    public const BIZ_TYPE_RECHARGE = 'recharge';

    public const PLATFORM_WEB = 'web';
    public const PLATFORM_H5 = 'h5';
    public const PLATFORM_MINIPROGRAM = 'miniprogram';
    public const PLATFORMS = [self::PLATFORM_WEB, self::PLATFORM_H5, self::PLATFORM_MINIPROGRAM];

    /** 客户端平台中文名（对账看板按订单来源端拆分） */
    public const PLATFORM_LABELS = [
        self::PLATFORM_WEB => 'Web 商城',
        self::PLATFORM_H5 => 'H5 手机端',
        self::PLATFORM_MINIPROGRAM => '小程序',
    ];

    /** 状态中文名（后台展示） */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待支付',
        self::STATUS_SUCCESS => '支付成功',
        self::STATUS_FAILED => '支付失败',
        self::STATUS_CLOSED => '已关闭',
        self::STATUS_REVIEWING => '待核账',
    ];

    /** 渠道中文名（后台展示） */
    public const CHANNEL_LABELS = [
        self::CHANNEL_WECHAT => '微信支付',
        self::CHANNEL_ALIPAY => '支付宝',
        self::CHANNEL_BALANCE => '余额支付',
        self::CHANNEL_OFFLINE => '线下转账',
        self::CHANNEL_MOCK => '本地模拟',
    ];

    /** 业务类型中文名 */
    public const BIZ_TYPE_LABELS = [
        self::BIZ_TYPE_ORDER => '订单支付',
        self::BIZ_TYPE_RECHARGE => '余额充值',
    ];

    /** 状态机：允许的目标状态 */
    public const STATUS_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CLOSED, self::STATUS_REVIEWING],
        self::STATUS_REVIEWING => [self::STATUS_SUCCESS, self::STATUS_FAILED],
        self::STATUS_SUCCESS => [],
        self::STATUS_FAILED => [],
        self::STATUS_CLOSED => [],
    ];

    protected $table = 'payments';
    protected $fillable = [
        'payment_no', 'order_id', 'order_no', 'user_id',
        'channel', 'amount', 'status', 'channel_trade_no', 'paid_at',
        'biz_type', 'biz_no',
        'payer_name', 'payer_account', 'transfer_no', 'transferred_at', 'voucher_url',
        'review_remark', 'reviewed_by', 'reviewed_at',
        'submitted_by', 'submitted_by_type',
        'platform',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'transferred_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'voucher_url' => MediaPath::class,
    ];

    /** 提交人身份域：买家（users）或管理员（sys_user） */
    public const SUBMITTER_USER = 'user';
    public const SUBMITTER_ADMIN = 'sys_user';

    protected $attributes = [
        'biz_type' => self::BIZ_TYPE_ORDER,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class, 'payment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getChannelLabelAttribute(): string
    {
        return self::CHANNEL_LABELS[$this->channel] ?? $this->channel;
    }

    public function getBizTypeLabelAttribute(): string
    {
        return self::BIZ_TYPE_LABELS[$this->biz_type] ?? $this->biz_type;
    }

    /** 是否订单支付 */
    public function isOrder(): bool
    {
        return $this->biz_type === self::BIZ_TYPE_ORDER;
    }

    /** 是否余额充值 */
    public function isRecharge(): bool
    {
        return $this->biz_type === self::BIZ_TYPE_RECHARGE;
    }
}
