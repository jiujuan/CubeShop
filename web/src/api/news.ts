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
  /** 语义化 URL 标识（null 时详情用 id） */
  slug: string | null
  title: string
  summary: string | null
  cover_image: string | null
  /** 标签（用于专题聚合） */
  tags: string[]
  published_at: string | null
  view_count: number
  channel_id: number
  channel_name: string | null
}

export interface NewsListResult {
  list: NewsListItem[]
  pagination: PublicPagination
}

/** 上一篇/下一篇邻居（id + slug + title） */
export interface NewsNeighbor {
  id: number
  slug: string | null
  title: string
}

/** 详情里的关联种草商品（id 为 public_id，跳 /product/{id}） */
export interface NewsProduct {
  id: string
  title: string
  subtitle: string | null
  main_image: string | null
  price: string
}

/** 详情（全文 + 同栏目相关 + 关联商品） */
export interface NewsDetail {
  id: number
  slug: string | null
  category_id: number
  title: string
  summary: string | null
  /** 文章级 SEO 三列（可空，详情页回落栏目/标题摘要） */
  seo_title: string | null
  seo_keywords: string | null
  seo_description: string | null
  /** 标签（JSON 数组） */
  tags: string[] | null
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
  products: NewsProduct[]
}

/** 标签聚合项 */
export interface NewsTag {
  tag: string
  count: number
}

export function getNewsChannels() {
  return request.get<ApiResult<NewsChannelsResult>>('/news/channels')
}

export function getNewsArticles(
  params: { channel_id?: number; keyword?: string; tag?: string; page?: number; per_page?: number } = {},
) {
  return request.get<ApiResult<NewsListResult>>('/news/articles', { params })
}

/** 详情：`key` 为 slug 或数字 id（后端 slug 优先、id 兜底） */
export function getNewsDetail(key: number | string) {
  return request.get<ApiResult<NewsDetailResult>>(`/news/articles/${key}`)
}

/** 全部标签（含次数，供标签云 / 专题入口） */
export function getNewsTags() {
  return request.get<ApiResult<NewsTag[]>>('/news/tags')
}

/** 热门排行（按浏览量；可选 channel_id） */
export function getNewsHot(params: { channel_id?: number; limit?: number } = {}) {
  return request.get<ApiResult<NewsListItem[]>>('/news/hot', { params })
}

/** 某商品关联的种草新闻（商品详情页用；`productId` 为 public_id） */
export function getProductNews(productId: number | string) {
  return request.get<ApiResult<NewsListItem[]>>(`/news/by-product/${productId}`)
}
