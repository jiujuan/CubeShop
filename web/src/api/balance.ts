import request from './request'
import type { ApiResult, Pagination } from './types'
import type { PayChannel, PayParams, PaymentStatus } from './payment'

// ---------- 余额与充值（收银台方案 §6.5 / Roadmap P6） ----------

/** 余额汇总 */
export interface BalanceAccount {
  balance: string
  frozen: string
  total_recharge: string
  total_consume: string
}

export type RechargeStatus = 'pending' | 'reviewing' | 'success' | 'failed' | 'closed'

/** 充值记录 */
export interface RechargeRecord {
  id: number
  recharge_no: string
  amount: string
  gift_amount: string
  total: string
  channel: PayChannel
  channel_label: string
  status: RechargeStatus
  status_label: string
  paid_at: string | null
  created_at: string
}

/** 余额流水 */
export interface BalanceLogItem {
  id: number
  type: 'recharge' | 'consume' | 'refund' | 'admin_adjust'
  type_label: string
  amount: string
  balance_before: string
  balance_after: string
  related_type: string | null
  remark: string | null
  created_at: string
}

/** 发起充值返回（结构与订单支付一致，pay_params 复用同一套调起逻辑） */
export interface CreateRechargeResult {
  recharge_no: string
  payment_no: string
  biz_type: 'recharge'
  amount: string
  gift_amount: string
  channel: PayChannel
  status: PaymentStatus
  pay_params: PayParams
}

export interface Paginated<T> {
  list: T[]
  pagination: Pagination
}

/** 余额汇总 */
export function getBalance() {
  return request.get<ApiResult<BalanceAccount>>('/user/balance')
}

/** 发起充值 */
export function createRecharge(amount: string, channel: PayChannel, extra?: Record<string, unknown>) {
  return request.post<ApiResult<CreateRechargeResult>>('/user/balance/recharges', { amount, channel, extra })
}

/** 充值记录（分页） */
export function getRecharges(params: { status?: string; page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<Paginated<RechargeRecord>>>('/user/balance/recharges', { params })
}

/** 余额流水（分页） */
export function getBalanceLogs(params: { type?: string; page?: number; page_size?: number } = {}) {
  return request.get<ApiResult<Paginated<BalanceLogItem>>>('/user/balance/logs', { params })
}
