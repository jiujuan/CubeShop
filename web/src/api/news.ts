import request from './request'
import type { ApiResult, PublicPagination } from './types'

// ================= 新闻中心（用户端，公开无需登录） =================

/** 新闻子栏目（图文/列表） */
export interface NewsChannel {
  id: number
  name: string
  slug: string | null
  /** card=图文卡片 / list=列表行 */
  list_style: string
  published_count: number
}

export interface NewsChannelsResult {
  /** 新闻中心根栏目（含 SEO 三列），未播种时为 null */
  root: {
    id: number
    name: string
    seo_title: string | null
    seo_keywords: string | null
    seo_description: string | null
  } | null
  channels: NewsChannel[]
}

/** 列表项（字段裁剪，不含正文 content） */
export interface NewsListItem {
  id: number
  title: string
  summary: string | null
  cover_image: string | null
  published_at: string | null
  view_count: number
  channel_id: number
  channel_name: string | null
}

export interface NewsListResult {
  list: NewsListItem[]
  pagination: PublicPagination
}

/** 上一篇/下一篇邻居（仅 id + title） */
export interface NewsNeighbor {
  id: number
  title: string
}

/** 详情（全文 + 同栏目相关）。related 复用列表项裁剪形状（后端经 toListItem 派生） */
export interface NewsDetail {
  id: number
  category_id: number
  title: string
  summary: string | null
  content: string
  cover_image: string | null
  view_count: number
  published_at: string | null
  is_hot: boolean
  sort: number
  created_at: string
  updated_at: string
  category?: { id: number; name: string } | null
}

export interface NewsDetailResult {
  article: NewsDetail
  related: NewsListItem[]
  prev: NewsNeighbor | null
  next: NewsNeighbor | null
}

export function getNewsChannels() {
  return request.get<ApiResult<NewsChannelsResult>>('/news/channels')
}

export function getNewsArticles(params: { channel_id?: number; keyword?: string; page?: number; per_page?: number } = {}) {
  return request.get<ApiResult<NewsListResult>>('/news/articles', { params })
}

export function getNewsDetail(id: number | string) {
  return request.get<ApiResult<NewsDetailResult>>(`/news/articles/${id}`)
}
