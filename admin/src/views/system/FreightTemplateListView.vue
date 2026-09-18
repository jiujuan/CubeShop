<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  clearDefaultFreightTemplate,
  createFreightTemplate,
  deleteFreightTemplate,
  getFreightTemplates,
  getProvinces,
  setDefaultFreightTemplate,
  updateFreightTemplate,
  type FreightAreaRow,
  type FreightRules,
  type FreightTemplateRow,
  type ProvinceRow,
} from '@/api/shipping'
import { listProvinces } from '@/lib/region'
import { Pencil, Plus, Search, Trash2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 运费模板管理（T-053 Stage3，权限 shipping.manage）
 *
 * 规则编辑器按 mode 动态切换：
 *  - fixed：统一金额；
 *  - weight：首重/续重四元组；
 *  - region：多段「省份集合 → 计费」+ 可选 default 兜底（省 code 来自 /api/regions/provinces）。
 * 最终校验在后端 FreightRuleValidator（fail-closed 422），前端只做提示不重复造轮子。
 */

type Mode = 'fixed' | 'weight' | 'region'

/** 段内计费（编辑态）：feeType 决定落库为 amount 还是重量四元组 */
interface AreaForm {
  provinces: string[]
  feeType: 'amount' | 'weight'
  amount: string
  first_weight_g: number
  first_fee: string
  step_weight_g: number
  step_fee: string
}

const loading = ref(true)
const list = ref<FreightTemplateRow[]>([])
const pagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
/** 当前全局默认模板 id（0=无，走旧口径固定运费） */
const defaultId = ref(0)

const keyword = ref('')
const modeFilter = ref<'' | Mode>('')
const statusFilter = ref<'' | 0 | 1>('')

// ---------- 编辑弹窗状态 ----------
const editing = ref<FreightTemplateRow | null>(null) // null=关闭，{}=新增，row=编辑
const saving = ref(false)
const formError = ref('')
const form = reactive({
  name: '',
  status: 1,
  mode: 'fixed' as Mode,
  // fixed
  amount: '8.00',
  // weight
  first_weight_g: 1000,
  first_fee: '5.00',
  step_weight_g: 1000,
  step_fee: '2.00',
  // region
  areas: [] as AreaForm[],
  hasDefault: false,
  default: { provinces: [], feeType: 'amount', amount: '10.00', first_weight_g: 1000, first_fee: '5.00', step_weight_g: 1000, step_fee: '2.00' } as AreaForm,
})

const isCreate = computed(() => editing.value !== null && editing.value.id === undefined)

// ---------- 省份字典 + 常用快捷组 ----------
const provinces = ref<ProvinceRow[]>([])
const provinceName = computed<Record<string, string>>(() =>
  Object.fromEntries(provinces.value.map((p) => [p.code, p.name])),
)
const provincePicker = ref(-1) // 当前展开省份面板的 area 下标（-1 收起）

/** 常用省份快捷组（GB/T 2260 code 稳定，直接引用） */
const PRESETS: Array<{ label: string; codes: string[] }> = [
  { label: '江浙沪皖', codes: ['310000', '320000', '330000', '340000'] },
  { label: '京津冀晋蒙', codes: ['110000', '120000', '130000', '140000', '150000'] },
  { label: '华南（粤桂琼）', codes: ['440000', '450000', '460000'] },
  { label: '华中（豫鄂湘）', codes: ['410000', '420000', '430000'] },
  { label: '西南（渝川黔滇藏）', codes: ['500000', '510000', '520000', '530000', '540000'] },
  { label: '西北（陕甘青宁新）', codes: ['610000', '620000', '630000', '640000', '650000'] },
  { label: '东北（辽吉黑）', codes: ['210000', '220000', '230000'] },
  { label: '港澳台', codes: ['710000', '810000', '820000'] },
]

// ---------- 加载 ----------
async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getFreightTemplates({
      keyword: keyword.value.trim() || undefined,
      mode: modeFilter.value || undefined,
      status: statusFilter.value === '' ? undefined : statusFilter.value,
      page,
      per_page: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
    defaultId.value = data.data.default_id ?? 0
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

// ---------- 列表摘要 ----------
function rulesSummary(t: FreightTemplateRow): string {
  const r = t.rules ?? {}
  if (t.mode === 'fixed') return `统一 ¥${r.amount ?? '—'}`
  if (t.mode === 'weight') return `首重 ${fmtG(r.first_weight_g)} ¥${r.first_fee ?? '—'} · 续重 ${fmtG(r.step_weight_g)} ¥${r.step_fee ?? '—'}`
  const n = (r.areas ?? []).length
  const def = r.default ? ` 默认¥${r.default.amount ?? '重量计费'}` : ''
  return `${n} 个区域 +${def || ' 无默认'}`
}

function fmtG(g?: number): string {
  if (!g) return '—'
  return g >= 1000 ? `${g / 1000}kg` : `${g}g`
}

// ---------- 弹窗打开/关闭 ----------
function openCreate() {
  editing.value = {} as FreightTemplateRow
  formError.value = ''
  form.name = ''
  form.status = 1
  form.mode = 'fixed'
  form.amount = '8.00'
  form.first_weight_g = 1000
  form.first_fee = '5.00'
  form.step_weight_g = 1000
  form.step_fee = '2.00'
  form.areas = []
  form.hasDefault = false
  form.default = { provinces: [], feeType: 'amount', amount: '10.00', first_weight_g: 1000, first_fee: '5.00', step_weight_g: 1000, step_fee: '2.00' }
  provincePicker.value = -1
}

function openEdit(t: FreightTemplateRow) {
  editing.value = t
  formError.value = ''
  form.name = t.name
  form.status = t.status
  form.mode = t.mode as Mode
  const r = t.rules ?? {}
  form.amount = String(r.amount ?? '0.00')
  form.first_weight_g = Number(r.first_weight_g ?? 1000)
  form.first_fee = String(r.first_fee ?? '0.00')
  form.step_weight_g = Number(r.step_weight_g ?? 1000)
  form.step_fee = String(r.step_fee ?? '0.00')
  form.areas = (r.areas ?? []).map((a) => ({
    provinces: [...a.provinces],
    feeType: a.amount !== undefined ? 'amount' : 'weight',
    amount: String(a.amount ?? '0.00'),
    first_weight_g: Number(a.first_weight_g ?? 1000),
    first_fee: String(a.first_fee ?? '0.00'),
    step_weight_g: Number(a.step_weight_g ?? 1000),
    step_fee: String(a.step_fee ?? '0.00'),
  }))
  form.hasDefault = r.default != null
  form.default = r.default
    ? {
        provinces: [...r.default.provinces ?? []],
        feeType: r.default.amount !== undefined ? 'amount' : 'weight',
        amount: String(r.default.amount ?? '0.00'),
        first_weight_g: Number(r.default.first_weight_g ?? 1000),
        first_fee: String(r.default.first_fee ?? '0.00'),
        step_weight_g: Number(r.default.step_weight_g ?? 1000),
        step_fee: String(r.default.step_fee ?? '0.00'),
      }
    : { provinces: [], feeType: 'amount', amount: '10.00', first_weight_g: 1000, first_fee: '5.00', step_weight_g: 1000, step_fee: '2.00' }
  provincePicker.value = -1
}

function closeForm() {
  editing.value = null
}

/** 切换 mode：重置规则区（防止旧规则结构残留） */
function switchMode(mode: Mode) {
  form.mode = mode
  formError.value = ''
}

// ---------- region 编辑 ----------
function addArea() {
  form.areas.push({ provinces: [], feeType: 'amount', amount: '0.00', first_weight_g: 1000, first_fee: '5.00', step_weight_g: 1000, step_fee: '2.00' })
  provincePicker.value = form.areas.length - 1
}

function removeArea(i: number) {
  form.areas.splice(i, 1)
  if (provincePicker.value === i) provincePicker.value = -1
}

function toggleProvince(area: AreaForm, code: string) {
  const i = area.provinces.indexOf(code)
  if (i >= 0) area.provinces.splice(i, 1)
  else area.provinces.push(code)
}

function applyPreset(area: AreaForm, codes: string[]) {
  for (const c of codes) if (!area.provinces.includes(c)) area.provinces.push(c)
}

// ---------- 保存 ----------
const formValid = computed(() => {
  if (form.name.trim() === '') return false
  if (form.mode === 'fixed') return isAmount(form.amount)
  if (form.mode === 'weight') {
    return form.first_weight_g > 0 && form.step_weight_g > 0 && isAmount(form.first_fee) && isAmount(form.step_fee)
  }
  // region：至少一段且每段选了省 + 费用合法
  if (!form.areas.length) return false
  const areasOk = form.areas.every(
    (a) => a.provinces.length > 0 && (a.feeType === 'amount' ? isAmount(a.amount) : a.first_weight_g > 0 && a.step_weight_g > 0 && isAmount(a.first_fee) && isAmount(a.step_fee)),
  )
  const defaultOk = !form.hasDefault || (form.default.feeType === 'amount' ? isAmount(form.default.amount) : form.default.first_weight_g > 0 && form.default.step_weight_g > 0 && isAmount(form.default.first_fee) && isAmount(form.default.step_fee))
  return areasOk && defaultOk
})

function isAmount(v: string): boolean {
  return /^\d+(\.\d{1,2})?$/.test(v) && Number(v) >= 0
}

/** 编辑态 → rules 载荷（金额统一两位小数字符串） */
function buildRules(): FreightRules {
  const money = (v: string) => Number(v).toFixed(2)
  if (form.mode === 'fixed') return { amount: money(form.amount) }
  if (form.mode === 'weight') {
    return {
      first_weight_g: form.first_weight_g,
      first_fee: money(form.first_fee),
      step_weight_g: form.step_weight_g,
      step_fee: money(form.step_fee),
    }
  }
  const toSpec = (a: AreaForm): Omit<FreightAreaRow, 'provinces'> =>
    a.feeType === 'amount'
      ? { amount: money(a.amount) }
      : { first_weight_g: a.first_weight_g, first_fee: money(a.first_fee), step_weight_g: a.step_weight_g, step_fee: money(a.step_fee) }

  const rules: FreightRules = { areas: form.areas.map((a) => ({ provinces: [...a.provinces], ...toSpec(a) })) }
  if (form.hasDefault) rules.default = { provinces: [...form.default.provinces], ...toSpec(form.default) }
  return rules
}

async function doSave() {
  if (!formValid.value || saving.value) return
  saving.value = true
  formError.value = ''
  try {
    const payload = { name: form.name.trim(), mode: form.mode, rules: buildRules(), status: form.status }
    if (isCreate.value) {
      await createFreightTemplate(payload)
    } else if (editing.value) {
      await updateFreightTemplate(editing.value.id, payload)
    }
    editing.value = null
    await load(pagination.value.page)
  } catch (e) {
    formError.value = extractError(e, '保存失败，请检查规则填写')
  } finally {
    saving.value = false
  }
}

function extractError(e: unknown, fallback: string): string {
  const resp = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data
  const ruleErrors = resp?.errors?.rules
  if (ruleErrors?.length) return ruleErrors.join('；')
  return resp?.message || fallback
}

// ---------- 启停 / 删除 / 全局默认 ----------
async function toggleStatus(t: FreightTemplateRow) {
  await updateFreightTemplate(t.id, { status: t.status === 1 ? 0 : 1 })
  await load(pagination.value.page)
}

/** 设为全局默认：未绑定模板的商品行走该模板（结算页/详情页预估同步生效） */
async function makeDefault(t: FreightTemplateRow) {
  await setDefaultFreightTemplate(t.id)
  await load(pagination.value.page)
}

/** 取消全局默认：回到旧口径固定运费 */
async function unsetDefault() {
  await clearDefaultFreightTemplate()
  await load(pagination.value.page)
}

const deleting = ref<FreightTemplateRow | null>(null)

async function doDelete() {
  if (!deleting.value) return
  try {
    await deleteFreightTemplate(deleting.value.id)
    deleting.value = null
    await load(pagination.value.page)
  } catch (e) {
    deleting.value = null
    formError.value = extractError(e, '删除失败')
    // 复用列表顶部错误提示 3 秒后清除
    setTimeout(() => (formError.value = ''), 4000)
  }
}

onMounted(async () => {
  load()
  // 省份字典：优先本地公共字典（零网络往返，与下单/结算页同源）；
  // 本地不可用（文件缺失/解析失败）时回退到服务端接口，双失败则 region 编辑器降级提示
  try {
    provinces.value = await listProvinces()
  } catch {
    getProvinces()
      .then(({ data }) => (provinces.value = data.data.provinces))
      .catch(() => {})
  }
})
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">运费模板</h2>
      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" data-testid="tpl-create" @click="openCreate">
        <Plus class="mr-1 h-4 w-4" /> 新增模板
      </Button>
    </div>

    <!-- 列表顶部错误提示（删除被拒等） -->
    <div v-if="formError && !editing" data-testid="tpl-list-error" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-600">
      {{ formError }}
    </div>

    <div data-testid="tpl-rule-note" class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[13px] leading-6 text-red-600">
      <span class="font-semibold">运费模板生效规则（按优先级依次匹配，命中即止）：</span>
      ① 商品编辑页单独绑定了模板 → 首先用绑定的模板；
      ② 未绑定 → 按收货地区匹配启用中的「按地区」模板（多个命中取运费最低者）；
      ③ 地区也未命中 → 用「全局默认」模板；
      ④ 未设全局默认 → 按系统固定运费口径（order.freight_default）。
      注意：全局默认若是「按地区」模板且收货地区不在其范围（②③均未命中）→ 该地区不可配送，下单将被拒绝。
    </div>

    <!-- 筛选 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="keyword" type="text" placeholder="模板名称"
        class="w-48 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <select v-model="modeFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">全部方式</option>
        <option value="fixed">固定运费</option>
        <option value="weight">按重量</option>
        <option value="region">按地区</option>
      </select>
      <select v-model="statusFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">全部状态</option>
        <option :value="1">启用</option>
        <option :value="0">停用</option>
      </select>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="search"><Search class="mr-1 h-4 w-4" /> 搜索</Button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">名称</th>
          <th class="w-24 px-3 py-1.5">计费方式</th>
          <th class="px-3 py-1.5">规则摘要</th>
          <th class="w-20 px-3 py-1.5">状态</th>
          <th class="w-36 px-3 py-1.5">更新时间</th>
          <th class="w-52 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="t in list" :key="t.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 text-black">
            {{ t.name }}
            <span v-if="t.id === defaultId" class="ml-1 rounded bg-[#1677ff]/10 px-1.5 py-0.5 text-xs text-[#1677ff]" data-testid="tpl-default-badge">全局默认</span>
          </td>
          <td class="px-3 py-1.5">
            <span
              class="rounded px-2 py-0.5 text-xs"
              :class="t.mode === 'fixed' ? 'bg-blue-50 text-blue-600' : t.mode === 'weight' ? 'bg-purple-50 text-purple-600' : 'bg-amber-50 text-amber-600'"
            >{{ t.mode_label }}</span>
          </td>
          <td class="px-3 py-1.5 font-mono text-slate-500">{{ rulesSummary(t) }}</td>
          <td class="px-3 py-1.5">
            <button
              class="rounded px-2 py-0.5 text-xs"
              :class="t.status === 1 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'"
              :data-testid="`tpl-toggle-${t.id}`"
              :title="t.status === 1 ? '点击停用' : '点击启用'"
              @click="toggleStatus(t)"
            >{{ t.status === 1 ? '启用' : '停用' }}</button>
          </td>
          <td class="px-3 py-1.5 text-slate-400">{{ t.updated_at }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-2 text-[#1677ff]">
              <button v-if="t.id !== defaultId && t.status === 1" class="flex items-center gap-0.5 hover:underline" :data-testid="`tpl-set-default-${t.id}`" title="未绑定模板的商品将按此模板计费" @click="makeDefault(t)">
                设为默认
              </button>
              <button v-if="t.id === defaultId" class="flex items-center gap-0.5 text-slate-500 hover:underline" @click="unsetDefault">
                取消默认
              </button>
              <button class="flex items-center gap-0.5 hover:underline" :data-testid="`tpl-edit-${t.id}`" @click="openEdit(t)">
                <Pencil class="h-3 w-3" /> 编辑
              </button>
              <button class="flex items-center gap-0.5 text-red-500 hover:underline" :data-testid="`tpl-delete-${t.id}`" @click="deleting = t">
                <Trash2 class="h-3 w-3" /> 删除
              </button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="6"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="6" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 新增 / 编辑弹窗 -->
    <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-6" @click.self="closeForm">
      <div class="w-full max-w-2xl rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">{{ isCreate ? '新增运费模板' : `编辑：${editing.name}` }}</h3>

        <!-- 基础字段 -->
        <div class="mt-4 flex gap-3">
          <div class="flex-1">
            <label class="block text-xs text-slate-500">模板名称 <span class="text-red-500">*</span></label>
            <input
              v-model="form.name" type="text" placeholder="如 江浙沪优惠运费" data-testid="tpl-form-name"
              class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
            />
          </div>
          <div class="w-28">
            <label class="block text-xs text-slate-500">状态</label>
            <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]">
              <option :value="1">启用</option>
              <option :value="0">停用</option>
            </select>
          </div>
        </div>

        <!-- 计费方式切换 -->
        <label class="mt-4 block text-xs text-slate-500">计费方式</label>
        <div class="mt-1 flex gap-2">
          <button
            v-for="m in [
              { value: 'fixed', label: '固定运费' },
              { value: 'weight', label: '按重量' },
              { value: 'region', label: '按地区' },
            ]" :key="m.value"
            class="rounded-full border px-4 py-1 text-[13px]"
            :class="form.mode === m.value ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-300 text-slate-600 hover:border-[#1677ff]'"
            :data-testid="`tpl-mode-${m.value}`"
            @click="switchMode(m.value as Mode)"
          >{{ m.label }}</button>
        </div>

        <!-- fixed -->
        <div v-if="form.mode === 'fixed'" class="mt-3">
          <label class="block text-xs text-slate-500">统一运费（元） <span class="text-red-500">*</span></label>
          <input v-model="form.amount" type="text" inputmode="decimal" data-testid="tpl-form-amount" class="mt-1 w-40 rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
        </div>

        <!-- weight -->
        <div v-if="form.mode === 'weight'" class="mt-3 grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs text-slate-500">首重（克） <span class="text-red-500">*</span></label>
            <input v-model.number="form.first_weight_g" type="number" min="1" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
          <div>
            <label class="block text-xs text-slate-500">首重费用（元） <span class="text-red-500">*</span></label>
            <input v-model="form.first_fee" type="text" inputmode="decimal" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
          <div>
            <label class="block text-xs text-slate-500">续重单位（克） <span class="text-red-500">*</span></label>
            <input v-model.number="form.step_weight_g" type="number" min="1" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
          <div>
            <label class="block text-xs text-slate-500">续重单位费用（元） <span class="text-red-500">*</span></label>
            <input v-model="form.step_fee" type="text" inputmode="decimal" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
          </div>
          <p class="col-span-2 text-xs text-slate-400">不足一个续重单位按一个计（向上取整）。</p>
        </div>

        <!-- region -->
        <div v-if="form.mode === 'region'" class="mt-3">
          <div class="flex items-center justify-between">
            <label class="text-xs text-slate-500">区域规则（按省匹配，先命中先计费；可再配默认规则兜底）</label>
            <button class="text-xs text-[#1677ff] hover:underline" data-testid="tpl-area-add" @click="addArea">+ 添加区域</button>
          </div>

          <div v-for="(area, i) in form.areas" :key="i" class="mt-2 rounded-lg border border-slate-200 p-3">
            <div class="flex items-center justify-between">
              <button class="text-[13px] text-[#1677ff] hover:underline" :data-testid="`tpl-area-pick-${i}`" @click="provincePicker = provincePicker === i ? -1 : i">
                选择省份（已选 {{ area.provinces.length }} 个）
              </button>
              <button class="text-xs text-red-500 hover:underline" @click="removeArea(i)">删除区域</button>
            </div>

            <!-- 已选省份 chips -->
            <div v-if="area.provinces.length" class="mt-2 flex flex-wrap gap-1">
              <span v-for="code in area.provinces" :key="code" class="flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                {{ provinceName[code] ?? code }}
                <button class="text-slate-400 hover:text-red-500" @click="toggleProvince(area, code)">×</button>
              </span>
            </div>

            <!-- 省份选择面板 -->
            <div v-if="provincePicker === i" class="mt-2 rounded-md bg-slate-50 p-3">
              <div class="mb-2 flex flex-wrap gap-1">
                <button v-for="p in PRESETS" :key="p.label" class="rounded-full bg-white px-2 py-0.5 text-xs text-[#1677ff] ring-1 ring-[#1677ff]/40 hover:bg-[#1677ff]/10" @click="applyPreset(area, p.codes)">
                  +{{ p.label }}
                </button>
              </div>
              <div class="grid grid-cols-4 gap-x-3 gap-y-1">
                <label v-for="p in provinces" :key="p.code" class="flex items-center gap-1 text-xs text-slate-600">
                  <input type="checkbox" :checked="area.provinces.includes(p.code)" @change="toggleProvince(area, p.code)" />
                  {{ p.name }}
                </label>
              </div>
            </div>

            <!-- 该段计费 -->
            <div class="mt-2 flex items-center gap-2">
              <select v-model="area.feeType" class="rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]">
                <option value="amount">固定金额</option>
                <option value="weight">按重量</option>
              </select>
              <template v-if="area.feeType === 'amount'">
                <input v-model="area.amount" type="text" inputmode="decimal" placeholder="运费（元）" class="w-28 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
              </template>
              <template v-else>
                <input v-model.number="area.first_weight_g" type="number" min="1" title="首重（克）" class="w-24 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
                <input v-model="area.first_fee" type="text" inputmode="decimal" title="首重费用（元）" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
                <input v-model.number="area.step_weight_g" type="number" min="1" title="续重单位（克）" class="w-24 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
                <input v-model="area.step_fee" type="text" inputmode="decimal" title="续重费用（元）" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
              </template>
            </div>
          </div>

          <!-- 默认规则 -->
          <div class="mt-2 flex items-center gap-3 rounded-lg border border-dashed border-slate-300 p-3">
            <label class="flex items-center gap-1 text-[13px] text-slate-600">
              <input v-model="form.hasDefault" type="checkbox" />
              启用默认规则（未命中任何区域时）
            </label>
            <template v-if="form.hasDefault">
              <select v-model="form.default.feeType" class="rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]">
                <option value="amount">固定金额</option>
                <option value="weight">按重量</option>
              </select>
              <template v-if="form.default.feeType === 'amount'">
                <input v-model="form.default.amount" type="text" inputmode="decimal" placeholder="运费（元）" class="w-28 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
              </template>
              <template v-else>
                <input v-model.number="form.default.first_weight_g" type="number" min="1" title="首重（克）" class="w-24 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
                <input v-model="form.default.first_fee" type="text" inputmode="decimal" title="首重费用（元）" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
                <input v-model.number="form.default.step_weight_g" type="number" min="1" title="续重单位（克）" class="w-24 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
                <input v-model="form.default.step_fee" type="text" inputmode="decimal" title="续重费用（元）" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]" />
              </template>
            </template>
          </div>
          <p class="mt-1 text-xs text-slate-400">不启用默认规则时，未命中区域视为不可配送（下单将被拒绝）。</p>
        </div>

        <!-- 错误提示 -->
        <div v-if="formError" data-testid="tpl-form-error" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-600">{{ formError }}</div>

        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="closeForm">取消</button>
          <button
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="!formValid || saving"
            data-testid="tpl-form-save"
            @click="doSave"
          >{{ saving ? '保存中…' : '保存' }}</button>
        </div>
      </div>
    </div>

    <!-- 删除确认 -->
    <ConfirmDialog
      :open="!!deleting"
      title="删除运费模板"
      :message="deleting ? `确定删除「${deleting.name}」？若仍有商品绑定该模板，删除将被拒绝（可先停用）。` : ''"
      danger
      confirm-text="确认删除"
      @confirm="doDelete"
      @cancel="deleting = null"
    />
  </div>
</template>
