import request from './request'
import type { ApiResult } from './types'

// ---------- 支付（API 文档 7 / Roadmap P5） ----------

export type PayChannel = 'wechat' | 'alipay'

export type PaymentStatus = 'pending' | 'success' | 'failed' | 'closed'

export interface PayParams {
  mode: 'sandbox'
  channel: PayChannel
  payment_no: string
  amount: string
  sandbox_pay_url: string
}

export interface CreatePaymentResult {
  payment_no: string
  amount: string
  channel: PayChannel
  status: PaymentStatus
  pay_params: PayParams
}

export interface PaymentStatusResult {
  payment_no: string
  order_no: string
  channel: PayChannel
  amount: string
  status: PaymentStatus
  paid_at: string | null
}

export interface CallbackResult {
  ok: boolean
  message: string
  status?: PaymentStatus
}

/** 发起支付 */
export function createPayment(orderNo: string, channel: PayChannel) {
  return request.post<ApiResult<CreatePaymentResult>>('/payments', { order_no: orderNo, channel })
}

/** 查询支付状态（轮询） */
export function getPaymentStatus(paymentNo: string) {
  return request.get<ApiResult<PaymentStatusResult>>(`/payments/${paymentNo}`)
}

/** 沙箱模拟支付（仅开发环境；生产由真实渠道回调） */
export function sandboxPay(paymentNo: string, result: 'success' | 'failed' = 'success') {
  return request.post<ApiResult<CallbackResult>>(`/payments/sandbox/${paymentNo}`, { result })
}
