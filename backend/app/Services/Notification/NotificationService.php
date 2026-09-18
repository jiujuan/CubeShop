<?php

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\SysUser;
use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\DB;

/**
 * 通知服务（V1.1 F02 / T-018）
 *
 * 站内信为兜底主通道（一定写库）；邮件为增强通道，按 `notify.mail_types` 配置决定是否投递。
 * 邮件投递失败不影响主流程（队列重试 + 失败记日志）。
 *
 * 收件人身份：notifications.user_id 是混合语义列（买家 + 后台管理员都会写入），
 * 必须通过 receiver_type 区分来源表，否则两侧 ID 撞号会造成通知串号。
 */
class NotificationService
{
    /** 通知类型常量 */
    public const TYPE_ORDER_PAID = 'order_paid';
    public const TYPE_ORDER_SHIPPED = 'order_shipped';
    public const TYPE_REFUND_RESULT = 'refund_result';
    public const TYPE_REVIEW_REPLIED = 'review_replied';
    public const TYPE_PASSWORD_CHANGED = 'password_changed';
    public const TYPE_LOW_STOCK = 'low_stock';

    // 客户服务中心（CS-107）
    public const TYPE_CS_TICKET_NEW = 'cs_ticket_new';
    public const TYPE_CS_TICKET_REPLY = 'cs_ticket_reply';
    public const TYPE_CS_TICKET_STATUS = 'cs_ticket_status';

    public function __construct(private ConfigService $config)
    {
    }

    /**
     * 发送站内信（并视配置投递邮件）
     *
     * @param  string  $receiverType  收件人来源：customer=买家（默认）/ admin=后台管理员
     */
    public function send(
        int $userId,
        string $type,
        string $title,
        string $content,
        ?string $link = null,
        string $receiverType = Notification::RECEIVER_CUSTOMER,
    ): Notification {
        $notification = Notification::create([
            'user_id' => $userId,
            'receiver_type' => $receiverType,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'link' => $link,
            'is_read' => false,
        ]);

        if ($this->isMailEnabled($type)) {
            $this->dispatchMail($userId, $title, $content, $receiverType);
        }

        return $notification;
    }

    /**
     * 向拥有某角色的后台管理员发送通知（如库存预警 → 运营）
     *
     * 注意：仅后台管理员参与 spatie 角色体系，买家不参与。
     */
    public function sendToRole(string $role, string $type, string $title, string $content, ?string $link = null): int
    {
        $count = 0;
        SysUser::query()
            ->where('status', 1)
            ->whereHas('roles', fn ($q) => $q->where('name', $role))
            ->cursor()
            ->each(function (SysUser $user) use ($type, $title, $content, $link, &$count) {
                $this->send($user->id, $type, $title, $content, $link, Notification::RECEIVER_ADMIN);
                $count++;
            });

        return $count;
    }

    /**
     * 向持有某权限的后台管理员发送通知
     *
     * 适用场景：收件人不是一个固定角色名，而是「谁有权处理这件事」——例如客服工单，
     * 未来新增客服专属角色时可自动覆盖，无需改代码。
     */
    public function sendToPermission(string $permission, string $type, string $title, string $content, ?string $link = null): int
    {
        $count = 0;
        SysUser::query()
            ->where('status', 1)
            ->permission($permission)
            ->cursor()
            ->each(function (SysUser $user) use ($type, $title, $content, $link, &$count) {
                $this->send($user->id, $type, $title, $content, $link, Notification::RECEIVER_ADMIN);
                $count++;
            });

        if ($count === 0) {
            \Illuminate\Support\Facades\Log::warning('[Notify] 权限收件人为空，通知无人接收', [
                'permission' => $permission,
                'type' => $type,
            ]);
        }

        return $count;
    }

    /** 该类型是否开启邮件通道 */
    public function isMailEnabled(string $type): bool
    {
        return in_array($type, $this->mailTypes(), true);
    }

    /**
     * 开启邮件的通知类型集合
     *
     * @return array<int, string>
     */
    public function mailTypes(): array
    {
        $raw = $this->config->get('notify.mail_types', '[]');
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** 投递邮件作业（队列可用时异步，不可用时降级 sync 仍不阻塞主流程） */
    private function dispatchMail(int $userId, string $title, string $content, string $receiverType): void
    {
        try {
            \App\Jobs\SendNotificationMail::dispatch($userId, $title, $content, $receiverType)->afterCommit();
        } catch (\Throwable $e) {
            // 队列/邮件通道异常不得影响业务主流程
            report($e);
        }
    }

    // ---------- 查询与已读 ----------

    public function unreadCount(int $userId, string $receiverType = Notification::RECEIVER_CUSTOMER): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->receiver($receiverType)
            ->where('is_read', false)
            ->count();
    }

    /** 批量已读：ids 为空表示全部已读 */
    public function markRead(
        int $userId,
        array $ids = [],
        string $receiverType = Notification::RECEIVER_CUSTOMER,
    ): int {
        $query = Notification::query()
            ->where('user_id', $userId)
            ->receiver($receiverType)
            ->where('is_read', false);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query->update(['is_read' => true, 'read_at' => now(), 'updated_at' => now()]);
    }
}
