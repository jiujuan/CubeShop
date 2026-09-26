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
