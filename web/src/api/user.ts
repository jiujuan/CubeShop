import request from './request'
import type { ApiResult } from './types'

// ---------- 购物车（API 文档 5） ----------

export interface CartItemView {
  id: number
  /** P2-11：后端出口为 SKU public_id（ULID 字符串） */
  sku_id: string
  /** P2-11：后端出口为商品 public_id（ULID 字符串），商品已删时为 null */
  product_id: string | number | null
  title: string
  specs: Record<string, string>
  image: string | null
  price: string
  quantity: number
  stock: number
  valid: boolean
  invalid_reason: string | null
  subtotal: string
}

export interface CartSummary {
  items: CartItemView[]
  total_amount: string
  total_quantity: number
}

export function getCart() {
  return request.get<ApiResult<CartSummary>>('/cart')
}

export function addToCart(skuId: string, quantity: number) {
  return request.post<ApiResult<null>>('/cart', { sku_id: skuId, quantity })
}

export function updateCartItem(id: number, quantity: number) {
  return request.put<ApiResult<null>>(`/cart/${id}`, { quantity })
}

export function removeCartItem(id: number) {
  return request.delete<ApiResult<null>>(`/cart/${id}`)
}

export function clearCart() {
  return request.delete<ApiResult<null>>('/cart')
}

export function getCartCount() {
  return request.get<ApiResult<{ count: number }>>('/cart/count')
}

// ---------- 收货地址（API 文档 3.3 ~ 3.7 / V1.1 E04 T-028 增强） ----------

export interface Address {
  id: number
  contact_name: string
  contact_phone: string
  contact_phone_full?: string
  province: string | null
  city: string | null
  district: string | null
  detail_address: string
  label: string | null
  is_default: boolean
  used_count: number
  last_used_at: string | null
}

export type AddressPayload = Omit<Address, 'id' | 'contact_phone_full' | 'used_count' | 'last_used_at'>

/** 行政区划节点（GB/T 2260 编码树，T-053 Stage1 起前后端共用同一数据源） */
export interface RegionNode {
  code: string
  name: string
  children?: RegionNode[]
}
export interface RegionData {
  regions: RegionNode[]
}

export interface ParsedAddress {
  contact_name: string
  contact_phone: string
  province: string
  city: string
  district: string
  detail_address: string
  confidence: number
}

export function getAddresses() {
  return request.get<ApiResult<Address[]>>('/user/addresses')
}

export function createAddress(data: Partial<AddressPayload>) {
  return request.post<ApiResult<Address>>('/user/addresses', data)
}

export function updateAddress(id: number, data: Partial<AddressPayload>) {
  return request.put<ApiResult<Address>>(`/user/addresses/${id}`, data)
}

export function deleteAddress(id: number) {
  return request.delete<ApiResult<null>>(`/user/addresses/${id}`)
}

export function setDefaultAddress(id: number) {
  return request.post<ApiResult<null>>(`/user/addresses/${id}/default`)
}

/**
 * 行政区划原始树（服务端字典）。
 * 前端页面统一使用本地字典库 `@/lib/region`（同一份数据，按需 chunk 加载，零网络往返）；
 * 本接口保留给外部服务/第三方消费。
 */
export function getRegions() {
  return request.get<ApiResult<RegionData>>('/regions')
}

/** 一行文本智能解析（V1.1 E04 / T-028） */
export function parseAddress(text: string) {
  return request.post<ApiResult<ParsedAddress>>('/user/addresses/parse', { text })
}

// ---------- 账号安全（V1.1 E05-B / T-027） ----------

/** 修改密码：需旧密码，成功后其他设备 Token 失效 */
export function changePassword(data: { old_password: string; password: string; password_confirmation: string }) {
  return request.post<ApiResult<{ revoked_tokens: number }>>('/auth/password', data)
}

// ---------- 文件上传（V1.1 T-016 评价晒图复用） ----------

/** 上传图片（≤2MB，返回可访问 URL） */
export function uploadImage(file: File) {
  const form = new FormData()
  form.append('file', file)
  return request.post<ApiResult<{ url: string }>>('/user/upload', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

// ---------- 个人资料（V1.1 E05-A / T-026） ----------

export interface UserProfile {
  id: number
  username: string
  nickname: string | null
  avatar: string | null
  phone: string | null
  email: string | null
  roles: string[]
  created_at?: string
  last_login_at?: string
}

export function getProfile() {
  return request.get<ApiResult<UserProfile>>('/user/profile')
}

export function updateProfile(data: { nickname?: string; avatar?: string; email?: string; phone?: string }) {
  return request.put<ApiResult<UserProfile>>('/user/profile', data)
}
