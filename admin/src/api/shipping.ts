import request from './request'
import type { ApiResult } from './request'

// ---------- 物流：运费模板（T-053 Stage1 后端 CRUD，本模块供商品表单下拉消费） ----------

export interface FreightTemplateRow {
  id: number
  name: string
  mode: 'fixed' | 'weight' | 'region' | string
  mode_label: string
  rules: Record<string, unknown>
  status: number
  created_at?: string
  updated_at?: string
}

/** 运费模板列表（status=1 只取启用中；下拉选项用） */
export function getFreightTemplates(params: { status?: number; per_page?: number } = {}) {
  return request.get<ApiResult<{ list: FreightTemplateRow[]; pagination: { total: number } }>>(
    '/admin/freight-templates',
    { params },
  )
}
