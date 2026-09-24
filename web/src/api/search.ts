import request from './request'
import type { ApiResult, ProductBrief, PublicPagination } from './types'

/**
 * 站内搜索（V1.2 S1-07 后端，S1-10 前台消费）
 *
 * 后端：backend/routes/api.php 的 /search 组（无需登录）。
 * /search 与 /products 参数一致，但结果带 `meta`：
 * - `related_categories`：命中商品的高频分类聚合（供二次收敛筛选）；
 * - `recommendations`：零结果时的推荐位（不白屏）；
 * - `relaxed` / `engine`：仅后台开启 search.expose_debug 时返回。
 */

export interface RelatedCategory {
  id: string
  name: string
  count: number
}

export interface SearchMeta {
  keyword: string
  related_categories?: RelatedCategory[]
  recommendations?: ProductBrief[]
  relaxed?: boolean
  engine?: string
}

export type SearchSort = 'newest' | 'sales_desc' | 'price_asc' | 'price_desc' | 'relevance'

export function searchProducts(params: {
  keyword: string
  category_id?: string
  min_price?: number
  max_price?: number
  brand_id?: string
  attribute_values?: string[]
  sort?: SearchSort
  page?: number
  page_size?: number
}) {
  return request.get<ApiResult<{
    list: ProductBrief[]
    pagination: PublicPagination
    meta: SearchMeta
  }>>('/search', { params })
}

/** 联想候选（后端同 IP 每分钟限流，前端 200ms 防抖，见 ShopHeader） */
export function getSuggest(keyword: string, limit = 8) {
  return request.get<ApiResult<string[]>>('/search/suggest', { params: { keyword, limit } })
}

/** 热搜榜 */
export function getHotKeywords(limit = 10) {
  return request.get<ApiResult<string[]>>('/search/hot', { params: { limit } })
}
