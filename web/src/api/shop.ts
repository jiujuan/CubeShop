import request from './request'
import type { ApiResult, CategoryNode, Pagination, ProductBrief, ProductDetail, ProductSku } from './types'

export type { CategoryNode, Pagination, ProductBrief, ProductDetail, ProductSku }

/** 商品列表 / 搜索（API 文档 4.1；V1.1 E01 支持品牌与属性筛选） */
export function getProducts(params: {
  keyword?: string
  category_id?: number
  min_price?: number
  max_price?: number
  sort?: string
  page?: number
  page_size?: number
  /** V1.1 E01：品牌筛选 */
  brand_id?: number
  /** V1.1 E01：属性筛选，格式 `${attribute_id}:${value}`；同属性 OR，跨属性 AND */
  attribute_values?: string[]
}) {
  return request.get<ApiResult<{ list: ProductBrief[]; pagination: Pagination }>>('/products', { params })
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
  id: number
  name: string
  logo: string | null
}

/** 某分类下可用于筛选的属性 */
export function getAttributes(params: { category_id?: number; filterable?: 0 | 1; type?: 'spec' | 'param' } = {}) {
  return request.get<ApiResult<AttributeOption[]>>('/attributes', { params })
}

/** 品牌列表 */
export function getBrands() {
  return request.get<ApiResult<BrandOption[]>>('/brands')
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
