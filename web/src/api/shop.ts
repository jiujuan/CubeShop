import request from './request'
import type { ApiResult, CategoryNode, Pagination, ProductBrief, ProductDetail, ProductSku, PublicPagination } from './types'

export type { CategoryNode, Pagination, ProductBrief, ProductDetail, ProductSku }

/** 商品列表 / 搜索（API 文档 4.1；V1.1 E01 支持品牌与属性筛选） */
export function getProducts(params: {
  keyword?: string
  category_id?: string
  min_price?: number
  max_price?: number
  sort?: string
  page?: number
  page_size?: number
  /** V1.1 E01：品牌筛选 */
  brand_id?: string
  /** V1.1 E01：属性筛选，格式 `${attribute_id}:${value}`；同属性 OR，跨属性 AND */
  attribute_values?: string[]
}) {
  // SEC-04：公开商品列表不返回精确总量
  return request.get<ApiResult<{ list: ProductBrief[]; pagination: PublicPagination }>>('/products', { params })
}

/** V1.1 E01：前台属性（按分类模板） */
export interface AttributeValueOption {
  id: number
  value: string
}

export interface AttributeOption {
  id: number
  name: string
  type: 'spec' | 'param'
  is_filterable: boolean
  is_multiple: boolean
  values: AttributeValueOption[]
}

/** V1.1 E01：品牌 */
export interface BrandOption {
  id: string
  name: string
  logo: string | null
}

/** 某分类下可用于筛选的属性 */
export function getAttributes(params: { category_id?: string; filterable?: 0 | 1; type?: 'spec' | 'param' } = {}) {
  return request.get<ApiResult<AttributeOption[]>>('/attributes', { params })
}

/** 品牌列表 */
/** 品牌列表（支持按分类过滤：后台配置了 category_brands 的分类只返回其可选品牌） */
export function getBrands(params?: { category_id?: string }) {
  return request.get<ApiResult<BrandOption[]>>('/brands', { params })
}

/** 商品详情（API 文档 4.2） */
export function getProduct(id: number | string) {
  return request.get<ApiResult<ProductDetail>>(`/products/${id}`)
}

/** 分类树（API 文档 4.3） */
export function getCategories() {
  return request.get<ApiResult<CategoryNode[]>>('/products/categories')
}

/** 热销 / 新品（API 文档 4.4） */
export function getHot(limit = 8) {
  return request.get<ApiResult<{ hot: ProductBrief[]; newest: ProductBrief[] }>>('/products/hot', {
    params: { limit },
  })
}

/** 首页推荐（P-HomeRecommend，API 无名）：后台勾选「首页推荐」的上架商品，按排序 + 上架时间倒序 */
export function getRecommended(limit = 12) {
  return request.get<ApiResult<{ list: ProductBrief[] }>>('/products/recommended', {
    params: { limit },
  })
}
