import request from './request'
import type { ApiResult } from './types'

// ---------- 会员积分与签到（会员成长计划 S1 / S2） ----------

/** 签到状态（GET /checkin，只读展示用） */
export interface CheckinStatus {
  /** 业务日期 Y-m-d */
  date: string
  /** 今日是否已签到 */
  checked: boolean
  /** 有效连续天数（断签归 0，前端「已连续 X 天」必须用这个） */
  streak: number
  /** 今日可得（未签）/ 今日实发（已签） */
  today_points: number
  /** 明日可得（按「若接着签」预演，7 日循环到头回到第一档） */
  next_points: number
  /** 累计签到天数 */
  total_days: number
  /** 可用积分（展示余额用） */
  balance: number
  /** 里程碑天数，如 [7] */
  milestone_days: number[]
  /** 签到功能是否在后台开启 */
  available: boolean
}

/** 签到返回：本次实发 + 完整状态（可直接刷新卡片，无需再拉一次） */
export interface CheckinResult extends CheckinStatus {
  /** 本次实发积分 */
  points: number
}

/** 签到状态 */
export function getCheckinStatus() {
  return request.get<ApiResult<CheckinStatus>>('/checkin')
}

/** 签到（同一天只能一次，重复返回 40009 / HTTP 409） */
export function postCheckin() {
  return request.post<ApiResult<CheckinResult>>('/checkin')
}

// ---------- 我的积分（会员成长计划 S3） ----------

/** 积分账户概览（GET /user/points 的 account 段） */
export interface PointAccount {
  balance: number
  frozen: number
  total: number
  total_earn: number
  total_spend: number
}

/** 积分流水行 */
export interface PointLogRow {
  id: number
  type: string
  type_label: string
  points: number
  frozen_points: number
  balance_before: number
  balance_after: number
  remark: string | null
  created_at: string | null
}

/** 我的积分概览 */
export interface MyPoints {
  enabled: boolean
  name: string
  account: PointAccount
  logs: PointLogRow[]
}

/** 流水分页 */
export interface PointLogPage {
  list: PointLogRow[]
  pagination: { total: number; per_page: number; current_page: number; last_page: number }
}

/** 我的积分概览 */
export function getMyPoints() {
  return request.get<ApiResult<MyPoints>>('/user/points')
}

/** 我的积分流水（分页） */
export function getMyPointLogs(page = 1, perPage = 20) {
  return request.get<ApiResult<PointLogPage>>('/user/points/logs', {
    params: { page, per_page: perPage },
  })
}
