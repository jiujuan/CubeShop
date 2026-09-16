<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\SysUser;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 通知邮件投递作业（V1.1 F02 / T-018）
 *
 * 重试 3 次、退避 60s；失败记日志但不影响业务主流程。
 * 无 Redis 环境时队列降级为 sync 驱动仍可工作。
 */
class SendNotificationMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $userId,
        public string $title,
        public string $content,
        public ?string $link = null,
        public string $receiverType = Notification::RECEIVER_CUSTOMER,
    ) {
    }

    public function handle(): void
    {
        // 按收件人来源选择账号表（买家 users / 管理员 sys_user）
        $user = $this->receiverType === Notification::RECEIVER_ADMIN
            ? SysUser::find($this->userId)
            : User::find($this->userId);

        if (! $user || ! $user->email) {
            return;
        }

        try {
            Mail::raw($this->content, function ($message) use ($user) {
                $message->to($user->email)->subject($this->title);
            });
        } catch (Throwable $e) {
            // 邮件失败不抛出（不阻塞队列以外流程），仅记录
            report($e);
        }
    }
}
