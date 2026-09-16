import request from './request'
import type { ApiResult } from './types'

// ---------- 支付（API 文档 7 / Roadmap P5） ----------

export type PayChannel = 'wechat' | 'alipay' | 'balance' | 'offline' | 'mock'

export type PaymentStatus = 'pending' | 'success' | 'failed' | 'closed' | 'reviewing'

export type PayChannelType = 'qrcode' | 'redirect' | 'form' | 'direct' | 'voucher' | 'mock'

/** 线下收款账户（单账户，§4.3） */
export interface OfflineReceipt {
  bank_name?: string
  account_name?: string
  account_no?: string
  qrcode_url?: string
}

/**
 * 发起支付返回给前端的调起参数（按 type 分支渲染，§6.3）
 */
export type PayParams =
  | { type: 'qrcode'; code_url: string; expire_at?: string }
  | { type: 'redirect'; pay_url: string }
  | { type: 'form'; form_html: string }
  | { type: 'direct'; paid_at: string }
  | { type: 'voucher'; receipt: OfflineReceipt }
  | { type: 'mock'; sandbox_pay_url: string; [k: string]: unknown }

export interface CreatePaymentResult {
  payment_no: string
  order_no: string
  biz_type: 'order' | 'recharge'
  amount: string
  channel: PayChannel
  status: PaymentStatus
  pay_params: PayParams
}

export interface PaymentStatusResult {
  payment_no: string
  order_no: string
  order_id?: number | null
  biz_type: 'order' | 'recharge'
  channel: PayChannel
  amount: string
  status: PaymentStatus
  paid_at: string | null
  review_remark?: string | null
  channel_trade_no?: string | null
}

/** 收银台渠道项 */
export interface CashierChannel {
  code: PayChannel
  name: string
  sort: number
  sandbox: boolean
  balance?: string
  receipt?: OfflineReceipt
}

export interface RechargeConfig {
  enabled: boolean
  amounts: string[]
  min_amount: string
  max_single: string
  max_daily: string
  gift_rules: { amount: number; gift: number }[]
}

export interface CashierChannelData {
  default_channel: PayChannel
  channels: CashierChannel[]
  recharge?: RechargeConfig
}

export interface SyncResult {
  payment_no: string
  status: PaymentStatus
  synced: boolean
  message: string
}

export interface CallbackResult {
  ok: boolean
  message: string
  status?: PaymentStatus
}

/** 收银台首屏渠道列表 */
export function getChannels(scene: 'order' | 'recharge' = 'order') {
  return request.get<ApiResult<CashierChannelData>>('/payments/channels', { params: { scene } })
}

/** 发起支付 */
export function createPayment(orderNo: string, channel: PayChannel, extra?: Record<string, unknown>) {
  return request.post<ApiResult<CreatePaymentResult>>('/payments', { order_no: orderNo, channel, extra })
}

/** 查询支付状态（轮询） */
export function getPaymentStatus(paymentNo: string) {
  return request.get<ApiResult<PaymentStatusResult>>(`/payments/${paymentNo}`)
}

/** 主动查单补偿（回调丢失时触发） */
export function syncPayment(paymentNo: string) {
  return request.post<ApiResult<SyncResult>>(`/payments/${paymentNo}/sync`)
}

/** 线下转账凭证上传 */
export function uploadVoucher(file: File) {
  const fd = new FormData()
  fd.append('file', file)
  return request.post<ApiResult<{ url: string }>>('/user/upload-voucher', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

/** 沙箱模拟支付（仅开发环境；生产由真实渠道回调） */
export function sandboxPay(paymentNo: string, result: 'success' | 'failed' = 'success') {
  return request.post<ApiResult<CallbackResult>>(`/payments/sandbox/${paymentNo}`, { result })
}
