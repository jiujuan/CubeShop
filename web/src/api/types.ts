export interface ApiResult<T = unknown> {
  code: number
  message: string
  data: T
}

export interface Pagination {
  page: number
  page_size: number
  total: number
  total_pages: number
  /** 后端已统一输出；旧接口未返回时为 undefined */
  has_more?: boolean
}

/**
 * 公开列表分页（SEC-04）
 *
 * 商品、评价等平台公共资源不再返回精确总量，避免一个未登录 GET 就拿到经营指标；
 * 翻页一律改用 has_more。本人资源（订单/收藏/消息等）仍用 Pagination，保留精确总数。
 */
export interface PublicPagination {
  page: number
  page_size: number
  total: number | null
  total_pages: number | null
  has_more: boolean
}

/** P2-11：分类对外只暴露 public_id（ULID 字符串，非自增主键） */
export interface CategoryNode {
  id: string
  name: string
  children: Array<{ id: string; name: string }>
}

/** P2-11：商品 id 已转 public_id 字符串 */
export interface ProductBrief {
  id: string
  title: string
  subtitle: string | null
  main_image: string | null
  price: string
  sales_count: number
  total_stock?: number
  category?: { id: string; name: string } | null
}

/** P2-11：SKU id 已转 public_id 字符串 */
export interface ProductSku {
  id: string
  sku_code: string
  specs: Record<string, string>
  price: string
  stock: number
  status: number
}

/** 商品参数（V1.1 E01） */
export interface ProductAttributeItem {
  attribute_id: number
  name: string
  type: 'spec' | 'param'
  value: string
}

/** P2-11：商品 / 分类 / 品牌 id 均转 public_id 字符串 */
export interface ProductDetail {
  id: string
  title: string
  subtitle: string | null
  main_image: string | null
  images: string[]
  description: string | null
  price: string
  sales_count: number
  status: number
  category: { id: string; name: string } | null
  total_stock: number
  skus: ProductSku[]
  // V1.1 E01
  brand_id?: string | null
  brand?: { id: string; name: string } | null
  video_url?: string | null
  weight?: number
  attributes?: ProductAttributeItem[]
  // V1.1 F05
  is_favorited?: boolean
}
