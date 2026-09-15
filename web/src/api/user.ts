import request from './request'
import type { ApiResult } from './types'

// ---------- 购物车（API 文档 5） ----------

export interface CartItemView {
  id: number
  sku_id: number
  product_id: number | null
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

export function addToCart(skuId: number, quantity: number) {
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

// ---------- 收货地址（API 文档 3.3 ~ 3.7） ----------

export interface Address {
  id: number
  contact_name: string
  contact_phone: string
  contact_phone_full?: string
  province: string | null
  city: string | null
  district: string | null
  detail_address: string
  is_default: boolean
}

export type AddressPayload = Omit<Address, 'id' | 'contact_phone_full'>

export function getAddresses() {
  return request.get<ApiResult<Address[]>>('/user/addresses')
}

export function createAddress(data: AddressPayload) {
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
