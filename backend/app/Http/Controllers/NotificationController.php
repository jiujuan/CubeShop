<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\Notification\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 站内通知（前台，V1.1 F02 / T-018 / T-019）
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private NotificationService $notifications)
    {
    }

    /** 通知列表（支持已读筛选） GET /me/notifications */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_read' => ['nullable', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = Notification::where('user_id', $request->user()->id)
            ->when(isset($data['is_read']), fn ($q) => $q->where('is_read', (bool) $data['is_read']))
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 15, 50), ['*'], 'page', $data['page'] ?? 1);

        return $this->paginated($paginator);
    }

    /** 未读数 GET /me/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success(['count' => $this->notifications->unreadCount($request->user()->id)]);
    }

    /** 标记已读 POST /me/notifications/read（ids 为空 → 全部已读） */
    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);

        $count = $this->notifications->markRead($request->user()->id, $data['ids'] ?? []);

        return $this->success(['updated' => $count], '已标记为已读');
    }
}
