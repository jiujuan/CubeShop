import request from './request'
import type { ApiResult } from './types'

// ================= 首页广告位（P-HomeBanner，公开无需登录） =================

export type BannerPosition = 'banner' | 'promo' | 'bottom'

export interface HomeBannerItem {
  /** ULID public_id */
  id: string
  image: string
  title: string
  subtitle: string | null
  link_url: string | null
}

export interface HomeBannerGroups {
  banner: HomeBannerItem[]
  promo: HomeBannerItem[]
  bottom: HomeBannerItem[]
}

/** 一次性取回首页三个位置的广告数据（仅启用项，按 sort_order 升序） */
export function getBanners() {
  return request.get<ApiResult<{ banners: HomeBannerGroups }>>('/banners')
}
