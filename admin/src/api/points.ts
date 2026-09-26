import request from './request'
import type { ApiResult } from './request'

// ---------- 会员积分（会员成长计划 S1 / 权限 member.view + member.manage） ----------

export interface UserPointAccount {
  /** 可用积分 */
  balance: number
  /** 冻结积分（下单占用，S5 起使用） */
  frozen: number
  /** 持有总额 = balance + frozen */
  total: number
  total_earn: number
  total_spend: number
}

export interface UserPointLogRow {
  id: number
  type: string
  type_label: string
  /** 可用积分变动，正=入账 负=出账 */
  points: number
  frozen_points: number
  balance_before: number
  balance_after: number
  remark: string | null
  created_at: string | null
}

export interface UserPointsDetail {
  user_id: number
  username: string
  nickname: string | null
  account: UserPointAccount
  logs: UserPointLogRow[]
}

export interface AdjustPointsPayload {
  /** 正=加分，负=减分；不能为 0，绝对值不超过 100000 */
  points: number
  reason: string
}

/** 用户积分账户与最近流水（member.view） */
export function getUserPoints(userId: number) {
  return request.get<ApiResult<UserPointsDetail>>(`/admin/users/${userId}/points`)
}

/** 人工调整积分（member.manage） */
export function adjustUserPoints(userId: number, payload: AdjustPointsPayload) {
  return request.post<ApiResult<{ account: UserPointAccount; log: UserPointLogRow }>>(
    `/admin/users/${userId}/points/adjust`,
    payload,
  )
}
