import request from './request'
import type { ApiResult } from './request'
import type { Pagination } from './product'

export type AdminReviewStatus = 'pending' | 'approved' | 'rejected'

export interface AdminReview {
  id: number
  order_id: number
  product_id: number
  product_title: string | null
  user_id: number
  nickname: string | null
  is_anonymous: boolean
  rating: number
  content: string | null
  images: string[]
  status: AdminReviewStatus
  status_label: string
  reject_reason: string | null
  reply_content: string | null
  reply_at: string | null
  /** 是否已被后台隐藏（true = 前台不展示，可恢复） */
  is_hidden: boolean
  created_at: string
}

export interface ReviewStats {
  pending: number
  today: number
  total: number
  hidden: number
  avg: number
}

export interface ReviewListResult {
  stats: ReviewStats
  audit_mode: boolean
  list: AdminReview[]
  pagination: Pagination
}

export function getReviews(params: {
  keyword?: string
  status?: AdminReviewStatus | ''
  rating?: number
  /** 只看已隐藏（true）/ 只看未隐藏（false）/ 全部（不传） */
  hidden?: boolean
  page?: number
  page_size?: number
} = {}) {
  return request.get<ApiResult<ReviewListResult>>('/admin/reviews', { params })
}

export function getReview(id: number) {
  return request.get<ApiResult<AdminReview & { product: { id: number; title: string } | null }>>(`/admin/reviews/${id}`)
}

export function approveReview(id: number) {
  return request.post<ApiResult<null>>(`/admin/reviews/${id}/approve`)
}

export function rejectReview(id: number, reason: string) {
  return request.post<ApiResult<null>>(`/admin/reviews/${id}/reject`, { reason })
}

export function replyReview(id: number, content: string) {
  return request.post<ApiResult<null>>(`/admin/reviews/${id}/reply`, { content })
}

/** 隐藏 / 显示评价（隐藏后前台不展示且不计入评分，记录保留可恢复） */
export function setReviewHidden(id: number, hidden: boolean) {
  return request.post<ApiResult<{ is_hidden: boolean }>>(`/admin/reviews/${id}/hidden`, { hidden })
}

export function deleteReview(id: number) {
  return request.delete<ApiResult<null>>(`/admin/reviews/${id}`)
}

/** 审核模式开关（需 config.manage 权限） */
export function setAuditMode(enabled: boolean) {
  return request.post<ApiResult<{ audit_mode: boolean }>>('/admin/reviews/audit-mode', { enabled })
}

/** 状态展示映射 */
export const REVIEW_STATUS_LABELS: Record<AdminReviewStatus, string> = {
  pending: '待审核',
  approved: '已通过',
  rejected: '已拒绝',
}

export const REVIEW_STATUS_CLASS: Record<AdminReviewStatus, string> = {
  pending: 'bg-amber-50 text-amber-600',
  approved: 'bg-emerald-50 text-emerald-600',
  rejected: 'bg-red-50 text-red-500',
}
