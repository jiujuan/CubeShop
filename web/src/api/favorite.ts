import request from './request'
import type { ApiResult, Pagination } from './types'

/**
 * 收藏与浏览足迹（V1.1 F05 / T-024）
 */
export interface FavoriteItem {
  id: number
  title: string
  subtitle: string | null
  main_image: string | null
  price: string
  sales_count: number
  status: number
  is_available: boolean
  unavailable_reason: string | null
}

export interface HistoryItem extends FavoriteItem {
  browsed_at: string | null
}

/** 收藏 / 取消收藏（幂等） */
export function favoriteProduct(productId: number) {
  return request.post<ApiResult<{ favorited: boolean }>>(`/products/${productId}/favorite`)
}

export function unfavoriteProduct(productId: number) {
  return request.delete<ApiResult<{ favorited: boolean }>>(`/products/${productId}/favorite`)
}

/** 收藏列表 */
export function getFavorites(params: { page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<{ list: FavoriteItem[]; pagination: Pagination }>>('/me/favorites', { params })
}

/** 批量取消收藏 */
export function batchRemoveFavorites(productIds: number[]) {
  return request.post<ApiResult<{ removed: number }>>('/me/favorites/batch-remove', { product_ids: productIds })
}

/** 上报浏览足迹（幂等去重） */
export function trackProduct(productId: number) {
  return request.post<ApiResult<null>>(`/products/${productId}/track`)
}

/** 足迹列表 */
export function getHistories(params: { page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<{ list: HistoryItem[]; pagination: Pagination }>>('/me/histories', { params })
}

/** 清空足迹 */
export function clearHistories() {
  return request.delete<ApiResult<{ removed: number }>>('/me/histories')
}
