import request from './request'
import type { ApiResult, Pagination } from './types'

export interface NotificationItem {
  id: number
  type: string
  title: string
  content: string | null
  link: string | null
  is_read: boolean
  read_at: string | null
  created_at: string
}

/** 通知列表（is_read 可选筛选，V1.1 F02 / T-018） */
export function getNotifications(params: { is_read?: 0 | 1; page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<{ list: NotificationItem[]; pagination: Pagination }>>('/me/notifications', { params })
}

/** 未读数 */
export function getUnreadCount() {
  return request.get<ApiResult<{ count: number }>>('/me/notifications/unread-count')
}

/** 标记已读（ids 为空表示全部已读） */
export function markNotificationsRead(ids: number[] = []) {
  return request.post<ApiResult<{ updated: number }>>('/me/notifications/read', { ids })
}
