<?php

namespace App\Services\Cs;

use App\Exceptions\BusinessException;
use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\Shipping;
use App\Models\User;
use App\Services\Common\NoGeneratorService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 服务工单服务层（CS-105）
 *
 * ⚠️ 全模块**唯一**的状态写入点：transitionTo()。
 *    控制器/命令不得直接 update(['status' => ...])，否则绕过流转矩阵与副作用（决策 D5）。
 *
 * 副作用集中在服务层：
 * - 时间锚点：first_replied_at / completed_at / closed_at / last_message_at
 * - 系统消息：状态变更、转交、优先级变更
 * - 通知钩子：建单通知客服、客服回复通知用户、状态变更通知用户（内部备注不通知）
 */
class CsTicketService
{
    public function __construct(
        private NoGeneratorService $noGenerator,
        private CsNotificationService $notifications,
    ) {
    }

    // ---------- 建单 ----------

    /**
     * @param  array{type_id: int, title: string, content: string, order_id?: int|null, contact?: string|null, images?: array<int, string>|null}  $data
     */
    public function createTicket(User $user, array $data): CsTicket
    {
        $type = CsTicketType::query()->where('id', $data['type_id'])->where('is_active', true)->first();
        if ($type === null) {
            throw BusinessException::badRequest('工单类型不存在或已停用');
        }

        $order = null;
        if (! empty($data['order_id'])) {
            $order = Order::query()->find($data['order_id']);
            if ($order === null || (int) $order->user_id !== (int) $user->id) {
                throw BusinessException::badRequest('关联订单不存在或不属于当前用户');
            }
        } elseif ($type->require_order) {
            throw BusinessException::badRequest('该问题类型必须关联订单');
        }

        $images = $this->normalizeImages($data['images'] ?? null);

        $ticket = DB::transaction(function () use ($user, $data, $type, $order, $images) {
            $ticket = CsTicket::create([
                'ticket_no' => $this->noGenerator->generateTicketNo(),
                'user_id' => $user->id,
                'type_id' => $type->id,
                'order_id' => $order?->id,
                'title' => $data['title'],
                'content' => $data['content'],
                'contact' => $data['contact'] ?? ($user->phone ?: $user->email),
                'status' => CsTicket::STATUS_PENDING,
                'priority' => CsTicket::PRIORITY_NORMAL,
                'last_message_at' => now(),
            ]);

            $ticket->messages()->create([
                'sender_type' => CsTicketMessage::SENDER_USER,
                'sender_id' => $user->id,
                'content' => $data['content'],
                'images' => $images,
                'is_internal' => false,
            ]);

            return $ticket;
        });

        $this->notifications->notifyNewTicket($ticket);

        return $ticket;
    }

    // ---------- 消息 ----------

    /**
     * 追加消息（用户/客服/系统）
     *
     * 自动流转：
     * - 客服回复（非内部备注）：pending → processing、waiting_user → processing
     * - 用户回复：waiting_user / completed → processing
     *
     * @param  array<int, string>|null  $images
     */
    public function addMessage(
        CsTicket $ticket,
        string $senderType,
        ?int $senderId = null,
        ?string $content = null,
        ?array $images = null,
        bool $isInternal = false,
    ): CsTicketMessage {
        if (blank($content) && empty($images)) {
            throw BusinessException::badRequest('消息内容与图片不能同时为空');
        }

        // 内部备注只有客服能写（其余发送方强制置 false，防越权带入）
        $isInternal = $isInternal && $senderType === CsTicketMessage::SENDER_STAFF;

        $normalizedImages = $this->normalizeImages($images);

        return DB::transaction(function () use ($ticket, $senderType, $senderId, $content, $normalizedImages, $isInternal) {
            /** @var CsTicket $locked */
            $locked = CsTicket::query()->where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            // 幂等（防网络重试重复提交）：同一发送方 5 秒内相同文本 → 返回已存在消息，不重复落库。
            // 行级锁保证并发的重复请求被串行化：后到者读到先到者写入的消息后直接返回（CS-116 并发专项）。
            if (! $isInternal && $content !== null && $content !== '') {
                $duplicate = $locked->messages()
                    ->where('sender_type', $senderType)
                    ->where('content', $content)
                    ->where('is_internal', false)
                    ->where('created_at', '>=', now()->subSeconds(5))
                    ->orderByDesc('id')
                    ->first();

                if ($duplicate !== null) {
                    return $duplicate;
                }
            }

            $message = $locked->messages()->create([
                'sender_type' => $senderType,
                'sender_id' => $senderId,
                'content' => $content,
                'images' => $normalizedImages,
                'is_internal' => $isInternal,
            ]);

            $updates = ['last_message_at' => now()];

            // 首响只记录一次：客服的第一条**非内部备注**消息
            if ($senderType === CsTicketMessage::SENDER_STAFF
                && ! $isInternal
                && $locked->first_replied_at === null) {
                $updates['first_replied_at'] = now();
            }

            $locked->update($updates);

            $this->applyAutoTransition($locked->fresh(), $senderType, $isInternal);

            $ticket->refresh();

            if ($senderType === CsTicketMessage::SENDER_STAFF) {
                $this->notifications->notifyStaffReply($ticket, $message);
            }

            return $message;
        });
    }

    // ---------- 状态流转 ----------

    /**
     * 状态流转唯一入口
     *
     * @param  string  $reason 关闭原因：user/staff/system/timeout（仅目标为 closed 时有意义）
     */
    public function transitionTo(CsTicket $ticket, string $to, ?int $operatorId = null, string $reason = ''): CsTicket
    {
        return DB::transaction(function () use ($ticket, $to, $operatorId, $reason) {
            /** @var CsTicket $locked */
            $locked = CsTicket::query()->where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            // 幂等：已经是目标状态则直接返回，不重复写系统消息
            if ($locked->status === $to) {
                return $locked;
            }

            $locked->assertTransitable($to);

            $updates = ['status' => $to];

            if ($to === CsTicket::STATUS_COMPLETED) {
                $updates['completed_at'] = now();
            }

            if ($to === CsTicket::STATUS_CLOSED) {
                $updates['closed_at'] = now();
                $updates['close_reason'] = $reason !== '' ? $reason : CsTicket::CLOSE_REASON_STAFF;
            }

            $locked->update($updates);

            $locked->messages()->create([
                'sender_type' => CsTicketMessage::SENDER_SYSTEM,
                'sender_id' => $operatorId,
                'content' => $this->transitionText($to, $reason),
                'is_internal' => false,
            ]);

            $ticket->refresh();

            $this->notifications->notifyStatusChanged($ticket, $to);

            return $ticket;
        });
    }

    /** 转交客服 */
    public function assign(CsTicket $ticket, ?int $assigneeId, ?int $operatorId = null, ?string $assigneeName = null): CsTicket
    {
        return DB::transaction(function () use ($ticket, $assigneeId, $operatorId, $assigneeName) {
            $ticket->update(['assignee_id' => $assigneeId]);

            $ticket->messages()->create([
                'sender_type' => CsTicketMessage::SENDER_SYSTEM,
                'sender_id' => $operatorId,
                'content' => $assigneeId === null
                    ? '工单已取消分配'
                    : '工单已转交给 '.($assigneeName ?? ('客服 #'.$assigneeId)),
                'is_internal' => false,
            ]);

            return $ticket->refresh();
        });
    }

    /** 标记优先级 */
    public function setPriority(CsTicket $ticket, int $priority, ?int $operatorId = null): CsTicket
    {
        $priority = $priority === CsTicket::PRIORITY_URGENT
            ? CsTicket::PRIORITY_URGENT
            : CsTicket::PRIORITY_NORMAL;

        return DB::transaction(function () use ($ticket, $priority, $operatorId) {
            $ticket->update(['priority' => $priority]);

            $ticket->messages()->create([
                'sender_type' => CsTicketMessage::SENDER_SYSTEM,
                'sender_id' => $operatorId,
                'content' => '工单优先级已标记为「'.CsTicket::PRIORITY_LABELS[$priority].'」',
                'is_internal' => false,
            ]);

            return $ticket->refresh();
        });
    }

    // ---------- 订单联动（CS-202） ----------

    /**
     * 工单关联订单快照：订单 / 商品 / 收货（脱敏）/ 物流最新轨迹 / 退款记录
     *
     * 口径说明：
     * - **实时读取**，不做冗余存储——订单状态变更后工单详情立即反映（AC-202.4）。
     * - 无关联订单（或订单已被物理删除）返回 `null`，不抛异常（AC-202.2）。
     * - 预加载 `items` / `refunds` / `shipping.traces`，避免 N+1（AC-202.5）。
     * - `$forStaff = false`（买家端）不下发客服内部字段（`refunds[].admin_remark`）
     *   与后台跳转参数（`jump`）（AC-202.3）。
     *
     * @return array<string, mixed>|null
     */
    public function orderSnapshot(CsTicket $ticket, bool $forStaff = true): ?array
    {
        if ($ticket->order_id === null) {
            return null;
        }

        /** @var Order|null $order */
        $order = $ticket->relationLoaded('order')
            ? $ticket->order
            : Order::query()->find($ticket->order_id);

        if ($order === null) {
            return null;
        }

        $order->loadMissing(['items', 'refunds', 'shipping.traces']);

        $address = is_array($order->address_snapshot) ? $order->address_snapshot : [];
        $items = $order->items->map(fn (OrderItem $item) => [
            'product_id' => $item->product_id,
            'sku_id' => $item->sku_id,
            'title' => $item->product_title,
            'specs' => $item->sku_specs,
            'image' => $item->sku_image,
            'price' => $item->price,
            'quantity' => (int) $item->quantity,
            'total_amount' => $item->total_amount,
            // 行实付 = 单价×数量 − 券分摊 − 满减分摊（与退款口径一致）
            'payable_amount' => $item->payableAmount(),
        ])->all();

        $refunds = $order->refunds
            ->sortByDesc('id')
            ->map(function (Refund $refund) use ($forStaff) {
                $row = [
                    'refund_no' => $refund->refund_no,
                    'amount' => $refund->amount,
                    'status' => $refund->status,
                    'created_at' => $refund->created_at,
                ];
                if ($forStaff) {
                    // 客服处理备注：买家端不下发
                    $row['admin_remark'] = $refund->admin_remark;
                }

                return $row;
            })
            ->values()
            ->all();

        $snapshot = [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'pay_amount' => $order->pay_amount,
            'created_at' => $order->created_at,
            'item_count' => (int) $order->items->sum('quantity'),
            'items' => $items,
            'address' => [
                'contact_name' => $address['contact_name'] ?? null,
                'phone_masked' => $this->maskPhone($address['contact_phone'] ?? null),
                'full_address' => $address['full_address'] ?? null,
            ],
            'shipping' => $this->shippingSummary($order),
            'refunds' => $refunds,
        ];

        if ($forStaff) {
            // 仅提供跳转参数：退款仍需 refund.process 权限，本接口不代客操作（CS-202 步骤 4）
            $snapshot['jump'] = [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'latest_refund_no' => $refunds[0]['refund_no'] ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * 旧版订单摘要形状（`order_no` / `status` / `pay_amount` / `created_at` / `product_image`）
     *
     * @deprecated 兼容 CS-114 工作台订单卡旧契约与既有用例；新代码一律消费 `order_snapshot`。
     *             待后台前端切到 `order_snapshot` 后随下一期移除。
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public function legacyOrderSummary(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'order_no' => $snapshot['order_no'],
            'status' => $snapshot['status'],
            'pay_amount' => $snapshot['pay_amount'],
            'created_at' => $snapshot['created_at'],
            'product_image' => $snapshot['items'][0]['image'] ?? null,
        ];
    }

    /** 物流摘要：最新一条轨迹 + 快递公司/运单号；未发货返回 null */
    private function shippingSummary(Order $order): ?array
    {
        /** @var Shipping|null $shipping 一单一发货，取最新一条兜底 */
        $shipping = $order->shipping->sortByDesc('id')->first();

        if ($shipping === null) {
            return null;
        }

        // traces() 关系已按 occurred_at 倒序，取首条即最新
        $latest = $shipping->traces->first();

        return [
            'company_code' => $shipping->company_code,
            'company_name' => $shipping->company_name,
            'tracking_no' => $shipping->tracking_no,
            'trace_status' => $shipping->trace_status,
            'shipped_at' => $shipping->shipped_at,
            'delivered_at' => $shipping->delivered_at,
            'latest_trace' => $latest === null ? null : [
                'context' => $latest->context,
                'occurred_at' => $latest->occurred_at,
            ],
        ];
    }

    /**
     * 联系方式脱敏（AC-202.3：手机仅显示尾号）
     *
     * - 11 位手机：前 3 + 后 4（138****8000）
     * - 7~10 位（固话等）：前 2 + 后 2（01****45）
     * - 4 位及以下：全部遮蔽
     */
    private function maskPhone(?string $phone): string
    {
        $phone = trim((string) $phone);
        $len = mb_strlen($phone);

        if ($len === 0) {
            return '';
        }

        if ($len >= 11) {
            return mb_substr($phone, 0, 3).'****'.mb_substr($phone, -4);
        }

        if ($len >= 7) {
            return mb_substr($phone, 0, 2).'****'.mb_substr($phone, -2);
        }

        return str_repeat('*', $len);
    }

    // ---------- 查询 ----------

    /** 买家侧：只能看自己的工单 */
    public function forUser(User $user, ?string $status = null, int $perPage = 10): LengthAwarePaginator
    {
        return CsTicket::query()
            ->ofUser($user->id)
            ->status($status)
            ->with(['type'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(min($perPage, 50));
    }

    /** 客服侧列表（一期可见全部，二期 CS-212 加数据范围） */
    public function forStaff(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->staffQuery($filters)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(min($perPage, 100));
    }

    public function staffQuery(array $filters = []): Builder
    {
        return CsTicket::query()
            ->with(['type', 'user', 'assignee'])
            ->status($filters['status'] ?? null)
            ->when(! empty($filters['type_id']), fn ($q) => $q->where('type_id', $filters['type_id']))
            ->when(! empty($filters['priority']), fn ($q) => $q->where('priority', (int) $filters['priority']))
            ->when(! empty($filters['assignee_id']), fn ($q) => $q->where('assignee_id', $filters['assignee_id']))
            ->when(! empty($filters['keyword']), fn ($q) => $this->applyKeyword($q, (string) $filters['keyword']))
            ->when(! empty($filters['created_start']), fn ($q) => $q->where('created_at', '>=', $filters['created_start']))
            ->when(! empty($filters['created_end']), fn ($q) => $q->where('created_at', '<=', $filters['created_end'].' 23:59:59'));
    }

    /** 待处理数量（工作台红点） */
    public function pendingCount(): int
    {
        return CsTicket::query()->where('status', CsTicket::STATUS_PENDING)->count();
    }

    /**
     * 消息流：非客服侧过滤内部备注（决策 D6，服务层统一过滤）
     */
    public function messagesFor(CsTicket $ticket, bool $isStaff = false)
    {
        return $ticket->messages()
            ->when(! $isStaff, fn ($q) => $q->where('is_internal', false))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    // ---------- 内部辅助 ----------

    /** 消息写入后的自动状态流转 */
    private function applyAutoTransition(CsTicket $ticket, string $senderType, bool $isInternal): void
    {
        $target = null;

        if ($senderType === CsTicketMessage::SENDER_STAFF && ! $isInternal) {
            if (in_array($ticket->status, [CsTicket::STATUS_PENDING, CsTicket::STATUS_WAITING_USER], true)) {
                $target = CsTicket::STATUS_PROCESSING;
            }
        }

        if ($senderType === CsTicketMessage::SENDER_USER
            && in_array($ticket->status, [CsTicket::STATUS_WAITING_USER, CsTicket::STATUS_COMPLETED], true)) {
            $target = CsTicket::STATUS_PROCESSING;
        }

        if ($target !== null && $ticket->canTransitTo($target)) {
            $ticket->update(['status' => $target]);
        }
    }

    private function transitionText(string $to, string $reason): string
    {
        $label = CsTicket::STATUS_LABELS[$to] ?? $to;

        if ($to === CsTicket::STATUS_CLOSED) {
            $reasonText = match ($reason) {
                CsTicket::CLOSE_REASON_USER => '用户关闭',
                CsTicket::CLOSE_REASON_TIMEOUT => '超时自动关闭',
                CsTicket::CLOSE_REASON_SYSTEM => '系统关闭',
                default => '客服关闭',
            };

            return "工单已关闭（{$reasonText}）";
        }

        return "工单状态变更为「{$label}」";
    }

    /**
     * 关键词：工单号精确 / 订单号 / 用户手机号
     */
    private function applyKeyword(Builder $query, string $keyword): Builder
    {
        $keyword = trim($keyword);

        return $query->where(function (Builder $q) use ($keyword) {
            $q->where('ticket_no', $keyword)
                ->orWhereHas('user', fn ($u) => $u->where('phone', 'like', '%'.$keyword.'%'))
                ->orWhereHas('order', fn ($o) => $o->where('order_no', 'like', '%'.$keyword.'%'));
        });
    }

    /**
     * @param  array<int, string>|null  $images
     * @return array<int, string>|null
     */
    private function normalizeImages(?array $images): ?array
    {
        if ($images === null || $images === []) {
            return null;
        }

        if (count($images) > CsTicketMessage::MAX_IMAGES) {
            throw BusinessException::badRequest('凭证图片最多 '.CsTicketMessage::MAX_IMAGES.' 张');
        }

        return array_values(array_filter($images, fn ($v) => is_string($v) && $v !== ''));
    }
}
