import request from './request'
import type { ApiResult, Pagination } from './types'

/** 评价状态（与后端 Review::STATUS_* 对齐） */
export type ReviewStatus = 'pending' | 'approved' | 'rejected'

export interface ReviewItem {
  id: number
  rating: number
  content: string | null
  images: string[]
  is_anonymous: boolean
  status: ReviewStatus
  status_label?: string
  reply_content: string | null
  reply_at: string | null
  edited_at: string | null
  created_at: string
  nickname: string
  avatar: string | null
  // 仅「我的评价」返回
  product_id?: number
  order_id?: number
  order_item_id?: number
  can_edit?: boolean
  product?: { id: number; title: string; main_image: string | null } | null
}

export interface ReviewSummary {
  avg: number
  total: number
  star_counts: Record<string, number>
  good_rate: number
}

export interface ProductReviewsResult {
  summary: ReviewSummary
  list: ReviewItem[]
  pagination: Pagination
}

export interface ReviewPayload {
  rating: number
  content?: string
  images?: string[]
  is_anonymous?: boolean
}

/** 提交评价（已完成订单的行项目，V1.1 F01 / T-015） */
export function submitReview(orderId: number, itemId: number, payload: ReviewPayload) {
  return request.post<ApiResult<{ id: number; rating: number; status: ReviewStatus }>>(
    `/orders/${orderId}/items/${itemId}/review`,
    payload,
  )
}

/** 修改评价（30 天内且未修改过） */
export function updateReview(id: number, payload: ReviewPayload) {
  return request.put<ApiResult<ReviewItem>>(`/reviews/${id}`, payload)
}

/** 商品评价列表 + 汇总（匿名可访问） */
export function getProductReviews(
  productId: number | string,
  params: { rating?: number; sort?: 'newest' | 'rating_desc' | 'rating_asc'; has_image?: 0 | 1; page?: number; page_size?: number } = {},
) {
  return request.get<ApiResult<ProductReviewsResult>>(`/products/${productId}/reviews`, { params })
}

/** 我的评价 */
export function getMyReviews(params: { page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<{ list: ReviewItem[]; pagination: Pagination }>>('/me/reviews', { params })
}
