import request from './request'
import type { ApiResult } from './request'

// ---------- 物流：运费模板（T-053 Stage3：管理页 UI + 商品表单下拉共用） ----------

/** region 规则中的一段：指定省集合 → 计费（金额或重量四元组），feeSpec 结构见后端 FreightRuleValidator */
export interface FreightAreaRow {
  provinces: string[]
  amount?: string
  first_weight_g?: number
  first_fee?: string
  step_weight_g?: number
  step_fee?: string
}

export interface FreightRules {
  amount?: string
  first_weight_g?: number
  first_fee?: string
  step_weight_g?: number
  step_fee?: string
  areas?: FreightAreaRow[]
  default?: FreightAreaRow | null
}

export interface FreightTemplateRow {
  id: number
  name: string
  mode: 'fixed' | 'weight' | 'region' | string
  mode_label: string
  rules: FreightRules
  status: number
  created_at?: string
  updated_at?: string
}

export interface FreightTemplatePayload {
  name: string
  mode: string
  rules: FreightRules
  status: number
}

/** 运费模板列表（status=1 只取启用中，商品表单下拉用） */
export function getFreightTemplates(params: {
  keyword?: string
  mode?: string
  status?: number
  page?: number
  per_page?: number
} = {}) {
  return request.get<
    ApiResult<{
      list: FreightTemplateRow[]
      /** 当前全局默认模板 id（0=无，走旧口径固定运费） */
      default_id: number
      pagination: { page: number; page_size: number; total: number; total_pages: number }
    }>
  >('/admin/freight-templates', { params })
}

export function createFreightTemplate(data: FreightTemplatePayload) {
  return request.post<ApiResult<FreightTemplateRow>>('/admin/freight-templates', data)
}

export function updateFreightTemplate(id: number, data: Partial<FreightTemplatePayload>) {
  return request.put<ApiResult<FreightTemplateRow>>(`/admin/freight-templates/${id}`, data)
}

export function deleteFreightTemplate(id: number) {
  return request.delete<ApiResult<null>>(`/admin/freight-templates/${id}`)
}

/** 设为全局默认模板（未绑定模板的商品行走此模板） */
export function setDefaultFreightTemplate(id: number) {
  return request.post<ApiResult<{ default_id: number }>>(`/admin/freight-templates/${id}/set-default`)
}

/** 取消全局默认模板（回到旧口径固定运费） */
export function clearDefaultFreightTemplate() {
  return request.post<ApiResult<{ default_id: number }>>('/admin/freight-templates/clear-default')
}

// ---------- 行政区划：省级列表（region 编辑器省份多选用，公开接口可缓存） ----------

export interface ProvinceRow {
  code: string
  name: string
}

export function getProvinces() {
  return request.get<ApiResult<{ provinces: ProvinceRow[] }>>('/regions/provinces')
}
