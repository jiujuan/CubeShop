<?php

namespace App\Models;

use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\HasPublicId;

/**
 * 服务工单（CS-101 / CS-102）
 *
 * ⚠️ 状态写入唯一入口：CsTicketService::transitionTo()。
 *    控制器禁止直接 update(['status' => ...])，否则绕过流转校验与副作用（决策 D5）。
 */
class CsTicket extends Model
{
    use HasPublicId;
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_WAITING_USER = 'waiting_user';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_PROCESSING => '处理中',
        self::STATUS_WAITING_USER => '等待用户回复',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CLOSED => '已关闭',
    ];

    public const PRIORITY_NORMAL = 0;
    public const PRIORITY_URGENT = 1;

    public const PRIORITY_LABELS = [
        self::PRIORITY_NORMAL => '普通',
        self::PRIORITY_URGENT => '紧急',
    ];

    public const CLOSE_REASON_USER = 'user';
    public const CLOSE_REASON_STAFF = 'staff';
    public const CLOSE_REASON_SYSTEM = 'system';
    public const CLOSE_REASON_TIMEOUT = 'timeout';

    /**
     * 状态流转矩阵（设计文档 §4.2）
     *
     * closed 为终态，不可再流转。
     * completed → processing 允许：已完成工单用户继续追问时重新打开处理（CS-105 自动流转规则）。
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_PROCESSING, self::STATUS_CLOSED],
        self::STATUS_PROCESSING => [self::STATUS_WAITING_USER, self::STATUS_COMPLETED, self::STATUS_CLOSED],
        self::STATUS_WAITING_USER => [self::STATUS_PROCESSING, self::STATUS_CLOSED],
        self::STATUS_COMPLETED => [self::STATUS_PROCESSING, self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
    ];

    protected $table = 'cs_ticket';

    protected $fillable = [
        'ticket_no', 'user_id', 'type_id', 'order_id', 'title', 'content',
        'status', 'priority', 'assignee_id', 'contact',
        'satisfaction', 'satisfaction_remark', 'satisfaction_at',
        'first_replied_at', 'last_message_at', 'completed_at', 'closed_at', 'close_reason',
    ];

    protected $casts = [
        'priority' => 'integer',
        'satisfaction' => 'integer',
        'satisfaction_at' => 'datetime',
        'first_replied_at' => 'datetime',
        'last_message_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    // ---------- 状态机 ----------

    public function canTransitTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @throws BusinessException 非法流转
     */
    public function assertTransitable(string $to): void
    {
        if (! $this->canTransitTo($to)) {
            throw BusinessException::conflict(sprintf(
                '工单状态不允许从「%s」变更为「%s」',
                self::STATUS_LABELS[$this->status] ?? $this->status,
                self::STATUS_LABELS[$to] ?? $to,
            ));
        }
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? '普通';
    }

    /** 用户是否可追加回复（已关闭不可） */
    public function canReply(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    /** 用户是否可主动关闭 */
    public function canClose(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    // ---------- 关系 ----------

    public function type(): BelongsTo
    {
        return $this->belongsTo(CsTicketType::class, 'type_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'assignee_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CsTicketMessage::class, 'ticket_id')->orderBy('created_at')->orderBy('id');
    }

    // ---------- 查询作用域 ----------

    public function scopeOfUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status === null || $status === '' || $status === 'all'
            ? $query
            : $query->where('status', $status);
    }
}
