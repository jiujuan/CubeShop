import request from './request'
import type { ApiResult } from './request'

export interface HealthData {
  status: string
  app: string
  env: string
  time: string
  database: { ok: boolean; error: string | null }
}

/** 健康检查 */
export function getHealth() {
  return request.get<ApiResult<HealthData>>('/health')
}
