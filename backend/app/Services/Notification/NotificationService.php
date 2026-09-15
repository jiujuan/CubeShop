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

    public function __construct(private ConfigService $config)
    {
    }

    /**
     * 发送站内信（并视配置投递邮件）
     */
    public function send(int $userId, string $type, string $title, string $content, ?string $link = null): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'link' => $link,
            'is_read' => false,
        ]);

        if ($this->isMailEnabled($type)) {
            $this->dispatchMail($userId, $title, $content);
        }

        return $notification;
    }

    /**
     * 向拥有某角色的全部用户发送通知（如库存预警 → 运营）
     */
    public function sendToRole(string $role, string $type, string $title, string $content, ?string $link = null): int
    {
        $count = 0;
        SysUser::query()
            ->where('status', 1)
            ->whereHas('roles', fn ($q) => $q->where('name', $role))
            ->cursor()
            ->each(function (SysUser $user) use ($type, $title, $content, $link, &$count) {
                $this->send($user->id, $type, $title, $content, $link);
                $count++;
            });

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
    private function dispatchMail(int $userId, string $title, string $content): void
    {
        try {
            \App\Jobs\SendNotificationMail::dispatch($userId, $title, $content)->afterCommit();
        } catch (\Throwable $e) {
            // 队列/邮件通道异常不得影响业务主流程
            report($e);
        }
    }

    // ---------- 查询与已读 ----------

    public function unreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)->where('is_read', false)->count();
    }

    /** 批量已读：ids 为空表示全部已读 */
    public function markRead(int $userId, array $ids = []): int
    {
        $query = Notification::where('user_id', $userId)->where('is_read', false);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query->update(['is_read' => true, 'read_at' => now(), 'updated_at' => now()]);
    }
}
