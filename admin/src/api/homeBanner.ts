import request from './request'
import type { ApiResult } from './request'
import type { Pagination } from './announcement'

// ================= 首页广告位管理（P-HomeBanner，权限 home.manage） =================

export type BannerPosition = 'banner' | 'promo' | 'bottom'

export interface HomeBannerRow {
  id: number
  position: BannerPosition
  position_label: string
  image: string
  title: string
  subtitle: string | null
  link_url: string | null
  sort_order: number
  is_enabled: boolean
  created_at: string
  updated_at: string
}

export interface HomeBannerPayload {
  position: BannerPosition
  image: string
  title: string
  subtitle?: string
  link_url?: string
  sort_order?: number
  is_enabled?: boolean
}

export function getHomeBanners(params: { position?: string; keyword?: string; page?: number; per_page?: number } = {}) {
  return request.get<ApiResult<{ list: HomeBannerRow[]; pagination: Pagination }>>('/admin/home-banners', { params })
}

export function createHomeBanner(data: HomeBannerPayload) {
  return request.post<ApiResult<HomeBannerRow>>('/admin/home-banners', data)
}

export function updateHomeBanner(id: number, data: Partial<HomeBannerPayload>) {
  return request.put<ApiResult<HomeBannerRow>>(`/admin/home-banners/${id}`, data)
}

export function toggleHomeBanner(id: number) {
  return request.post<ApiResult<HomeBannerRow>>(`/admin/home-banners/${id}/toggle`)
}

export function deleteHomeBanner(id: number) {
  return request.delete<ApiResult<null>>(`/admin/home-banners/${id}`)
}
