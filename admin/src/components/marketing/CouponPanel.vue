<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Download, Pencil, Plus, SquarePlus, Ticket } from 'lucide-vue-next'
import {
  createCoupon, exportCoupon, getCouponStats, getCoupons, stopCoupon, updateCoupon,
  type AdminCoupon, type CouponPayload, type CouponStats, type CouponType, type CouponStatus,
} from '@/api/marketing'
import { getCategories } from '@/api/product'
import { getProducts } from '@/api/product'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 优惠券管理 Tab（V1.1 二期 F06 / T-040）
 *
 * 列表（发放/领取/核销/核销率）+ 创建/编辑表单（类型切换动态字段、
 * 范围选择器、绝对/相对有效期）+ **已发放券核心字段置灰**（仅名称/停发/延长有效期）
 * + 统计抽屉（领取率/核销率/带来订单）+ 明细导出。
 */
const list = ref<AdminCoupon[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')
const keyword = ref('')
const statusFilter = ref<'' | CouponStatus>('')

// 表单弹层
const formOpen = ref(false)
const editing = ref<AdminCoupon | null>(null)
const saving = ref(false)
const formError = ref('')

const form = ref<CouponPayload>(emptyForm())
const scopeCategories = ref<Array<{ id: number; name: string }>>([])
const scopeProducts = ref<Array<{ id: number; title: string }>>([])

function emptyForm(): CouponPayload {
  return {
    name: '', type: 'fixed', amount: null, percent: null, max_discount: null,
    min_spend: 0, scope: 'all', scope_refs: [], total_count: 100, per_user_limit: 1,
    valid_type: 'relative', valid_from: null, valid_to: null, valid_days: 30,
  }
}

// 统计抽屉
const statsOpen = ref(false)
const stats = ref<CouponStats | null>(null)
const statsLoading = ref(false)

const confirmState = ref<{ title: string; message: string; run: () => Promise<void> } | null>(null)

/** 已发放券：核心字段锁定（面额/门槛/范围/发放量/有效期类型等） */
const locked = computed(() => !!editing.value?.issued)

const pct = (n: number) => `${(n * 100).toFixed(1)}%`

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const res = await getCoupons({
      keyword: keyword.value || undefined,
      status: statusFilter.value || undefined,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    list.value = res.data.data.list
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(load)

function search() {
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  formError.value = ''
  formOpen.value = true
  loadScopeOptions()
}

function openEdit(c: AdminCoupon) {
  editing.value = c
  formError.value = ''
  form.value = {
    name: c.name,
    type: c.type,
    amount: c.amount,
    percent: c.percent,
    max_discount: c.max_discount,
    min_spend: c.min_spend,
    scope: c.scope,
    scope_refs: [...c.scope_refs],
    total_count: c.total_count,
    per_user_limit: c.per_user_limit,
    valid_type: c.valid_type,
    valid_from: c.valid_from,
    valid_to: c.valid_to,
    valid_days: c.valid_days,
    status: c.status,
  }
  formOpen.value = true
  loadScopeOptions()
}

async function loadScopeOptions() {
  if (scopeCategories.value.length) return
  try {
    const res = await getCategories()
    scopeCategories.value = res.data.data.filter((c: { parent_id: number }) => c.parent_id !== undefined).map((c: { id: number; name: string }) => ({ id: c.id, name: c.name }))
  } catch {
    scopeCategories.value = []
  }
  try {
    const res = await getProducts({ page: 1, page_size: 50 })
    scopeProducts.value = res.data.data.list.map((p: { id: number; title: string }) => ({ id: p.id, title: p.title }))
  } catch {
    scopeProducts.value = []
  }
}

/** 提交前组装载荷：已发放券仅发送白名单字段 */
function buildPayload(): Record<string, unknown> {
  const f = form.value
  if (locked.value) {
    return {
      name: f.name,
      status: f.status,
      valid_to: f.valid_type === 'absolute' ? f.valid_to : undefined,
    }
  }
  return {
    ...f,
    scope_refs: f.scope === 'all' ? [] : f.scope_refs,
  }
}

async function save() {
  formError.value = ''
  const f = form.value
  if (!f.name.trim()) { formError.value = '请填写券名称'; return }
  if (!locked.value && f.type === 'fixed' && !(Number(f.amount) > 0)) { formError.value = '请填写面额'; return }
  if (!locked.value && f.type === 'percent' && !(Number(f.percent) >= 1 && Number(f.percent) <= 99)) { formError.value = '折扣需为 1~99 的整数'; return }
  if (!locked.value && f.scope !== 'all' && !(f.scope_refs ?? []).length) { formError.value = '指定分类/商品范围时必须选择具体对象'; return }

  saving.value = true
  try {
    if (editing.value) await updateCoupon(editing.value.id, buildPayload())
    else await createCoupon(buildPayload() as unknown as CouponPayload)
    formOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

function askStop(c: AdminCoupon) {
  confirmState.value = {
    title: '停止发放',
    message: `确定停止发放「${c.name}」？停止后用户不可再领取（已领的仍可使用）。`,
    run: async () => {
      await stopCoupon(c.id)
      await load()
    },
  }
}

async function openStats(c: AdminCoupon) {
  statsOpen.value = true
  statsLoading.value = true
  stats.value = null
  try {
    const res = await getCouponStats(c.id)
    stats.value = res.data.data
  } finally {
    statsLoading.value = false
  }
}

async function doExport(c: AdminCoupon) {
  const res = await exportCoupon(c.id)
  const blob = new Blob([res.data as BlobPart], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `coupon-${c.id}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

/** 切换类型时清理互斥字段 */
function onTypeChange(t: CouponType) {
  form.value.type = t
  if (t === 'fixed') {
    form.value.percent = null
    form.value.max_discount = null
  } else {
    form.value.amount = null
  }
}

function toggleScopeRef(id: number) {
  const refs = form.value.scope_refs ?? []
  form.value.scope_refs = refs.includes(id) ? refs.filter((r) => r !== id) : [...refs, id]
}

function moneyText(c: AdminCoupon): string {
  if (c.type === 'percent') return c.percent !== null ? `${(c.percent / 10).toFixed(c.percent % 10 === 0 ? 0 : 1)}折` : '-'
  return c.amount !== null ? `¥${c.amount}` : '-'
}

function validText(c: AdminCoupon): string {
  if (c.valid_type === 'relative') return `领取后 ${c.valid_days} 天`
  return c.valid_to ? `至 ${c.valid_to.slice(0, 10)}` : '-'
}

defineExpose({ load, openCreate, openEdit, form, buildPayload })
</script>

<template>
  <div>
    <!-- 工具行 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <input
        v-model="keyword" type="text" placeholder="按券名称搜索"
        class="h-9 w-56 rounded-md border border-slate-300 px-3 text-sm outline-none focus:border-[#1677ff]"
        data-testid="coupon-search-input"
        @keyup.enter="search"
      />
      <select
        v-model="statusFilter" class="h-9 rounded-md border border-slate-300 px-2 text-sm"
        data-testid="coupon-status-filter" @change="search"
      >
        <option value="">全部状态</option>
        <option value="active">发放中</option>
        <option value="stopped">已停发</option>
      </select>
      <button
        v-permission="'marketing.manage'"
        class="flex items-center gap-1 rounded-md bg-[#1677ff] px-4 py-2 text-sm text-white hover:bg-[#4096ff]"
        data-testid="coupon-create-btn"
        @click="openCreate"
      ><Plus class="h-4 w-4" /> 新建优惠券</button>
    </div>

    <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

    <LoadingSpinner v-if="loading" />

    <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="w-full text-left text-[13px]">
        <thead class="bg-slate-50 text-slate-500">
          <tr>
            <th class="px-4 py-2.5">名称</th>
            <th class="px-4 py-2.5">类型</th>
            <th class="px-4 py-2.5">面额/折扣</th>
            <th class="px-4 py-2.5">门槛</th>
            <th class="px-4 py-2.5">范围</th>
            <th class="px-4 py-2.5">有效期</th>
            <th class="px-4 py-2.5">发放/领取/核销</th>
            <th class="px-4 py-2.5">核销率</th>
            <th class="px-4 py-2.5">状态</th>
            <th class="px-4 py-2.5">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in list" :key="c.id" class="border-t border-slate-100" :data-testid="`coupon-row-${c.id}`">
            <td class="px-4 py-2.5 font-medium text-slate-700">{{ c.name }}</td>
            <td class="px-4 py-2.5">{{ c.type_label }}</td>
            <td class="px-4 py-2.5 text-[#ff4d4f]">{{ moneyText(c) }}<span v-if="c.type === 'percent' && c.max_discount" class="text-slate-400">（封顶¥{{ c.max_discount }}）</span></td>
            <td class="px-4 py-2.5">{{ c.min_spend > 0 ? `¥${c.min_spend}` : '无' }}</td>
            <td class="px-4 py-2.5">{{ c.scope_label }}</td>
            <td class="px-4 py-2.5">{{ validText(c) }}</td>
            <td class="px-4 py-2.5 text-slate-500">{{ c.total_count }} / {{ c.issued_count }} / <span class="font-medium text-slate-700">{{ c.used_count }}</span></td>
            <td class="px-4 py-2.5">{{ pct(c.total_count > 0 ? c.used_count / c.total_count : 0) }}</td>
            <td class="px-4 py-2.5">
              <span class="rounded px-1.5 py-0.5 text-xs" :class="c.status === 'active' ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-400'">{{ c.status === 'active' ? '发放中' : '已停发' }}</span>
            </td>
            <td class="px-4 py-2.5">
              <div class="flex items-center gap-2 text-[#1677ff]">
                <button class="hover:underline" :data-testid="`coupon-stats-${c.id}`" @click="openStats(c)">统计</button>
                <button class="hover:underline" :data-testid="`coupon-edit-${c.id}`" @click="openEdit(c)"><Pencil class="h-3.5 w-3.5" /></button>
                <button v-if="c.status === 'active'" class="text-slate-400 hover:text-red-500" :data-testid="`coupon-stop-${c.id}`" @click="askStop(c)">停发</button>
                <button class="hover:underline" :data-testid="`coupon-export-${c.id}`" title="导出领取/核销明细" @click="doExport(c)"><Download class="h-3.5 w-3.5" /></button>
              </div>
            </td>
          </tr>
          <tr v-if="!list.length">
            <td colspan="10" class="px-4 py-10 text-center text-slate-400" data-testid="coupon-empty">暂无优惠券</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 分页 -->
    <div v-if="pagination.total_pages > 1" class="mt-3 flex items-center justify-end gap-2 text-xs text-slate-500">
      <button class="rounded border border-slate-200 px-3 py-1 disabled:opacity-40" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">上一页</button>
      <span>{{ pagination.page }} / {{ pagination.total_pages }}</span>
      <button class="rounded border border-slate-200 px-3 py-1 disabled:opacity-40" :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)">下一页</button>
    </div>

    <!-- 创建/编辑弹层 -->
    <Teleport to="body">
      <div v-if="formOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="formOpen = false">
        <div class="max-h-[88vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" data-testid="coupon-form-dialog">
          <h3 class="mb-4 flex items-center gap-2 text-base font-semibold text-slate-800">
            <Ticket class="h-4 w-4 text-[#1677ff]" /> {{ editing ? '编辑优惠券' : '新建优惠券' }}
          </h3>

          <p v-if="locked" class="mb-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-600" data-testid="coupon-locked-tip">
            该券已发放 {{ editing?.issued_count }} 张，面额/门槛/范围等核心字段已锁定（仅可改名称、停发或延长有效期）
          </p>
          <p v-if="formError" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="coupon-form-error">{{ formError }}</p>

          <div class="space-y-3 text-[13px]">
            <label class="block">
              <span class="mb-1 block text-slate-500">券名称 *</span>
              <input v-model="form.name" type="text" maxlength="100" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="coupon-form-name" />
            </label>

            <div class="grid grid-cols-2 gap-3">
              <label class="block">
                <span class="mb-1 block text-slate-500">类型 *</span>
                <select
                  :value="form.type" :disabled="locked"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400"
                  data-testid="coupon-form-type" @change="onTypeChange(($event.target as HTMLSelectElement).value as CouponType)"
                >
                  <option value="fixed">满减券</option>
                  <option value="percent">折扣券</option>
                </select>
              </label>
              <label v-if="form.type === 'fixed'" class="block">
                <span class="mb-1 block text-slate-500">面额（元）*</span>
                <input v-model.number="form.amount" type="number" min="0.01" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-amount" />
              </label>
              <template v-else>
                <label class="block">
                  <span class="mb-1 block text-slate-500">折扣（1~99，90=9折）*</span>
                  <input v-model.number="form.percent" type="number" min="1" max="99" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-percent" />
                </label>
                <label class="block">
                  <span class="mb-1 block text-slate-500">最高优惠封顶（元，可选）</span>
                  <input v-model.number="form.max_discount" type="number" min="0" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-max-discount" />
                </label>
              </template>
            </div>

            <div class="grid grid-cols-3 gap-3">
              <label class="block">
                <span class="mb-1 block text-slate-500">使用门槛（元）</span>
                <input v-model.number="form.min_spend" type="number" min="0" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-min-spend" />
              </label>
              <label class="block">
                <span class="mb-1 block text-slate-500">发放总量 *</span>
                <input v-model.number="form.total_count" type="number" min="1" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-total" />
              </label>
              <label class="block">
                <span class="mb-1 block text-slate-500">每人限领 *</span>
                <input v-model.number="form.per_user_limit" type="number" min="1" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-per-user" />
              </label>
            </div>

            <label class="block">
              <span class="mb-1 block text-slate-500">适用范围 *</span>
              <select
                v-model="form.scope" :disabled="locked"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400"
                data-testid="coupon-form-scope"
              >
                <option value="all">全场通用</option>
                <option value="category">指定分类</option>
                <option value="product">指定商品</option>
              </select>
            </label>
            <div v-if="form.scope === 'category'" class="rounded-md border border-slate-200 p-3" data-testid="coupon-form-scope-refs">
              <label v-for="c in scopeCategories" :key="c.id" class="mr-3 inline-flex items-center gap-1.5">
                <input type="checkbox" :checked="(form.scope_refs ?? []).includes(c.id)" :disabled="locked" @change="toggleScopeRef(c.id)" />
                <span>{{ c.name }}</span>
              </label>
            </div>
            <div v-if="form.scope === 'product'" class="max-h-36 overflow-y-auto rounded-md border border-slate-200 p-3" data-testid="coupon-form-scope-refs">
              <label v-for="p in scopeProducts" :key="p.id" class="mr-3 inline-flex items-center gap-1.5">
                <input type="checkbox" :checked="(form.scope_refs ?? []).includes(p.id)" :disabled="locked" @change="toggleScopeRef(p.id)" />
                <span class="max-w-40 truncate">{{ p.title }}</span>
              </label>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <label class="block">
                <span class="mb-1 block text-slate-500">有效期类型 *</span>
                <select v-model="form.valid_type" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-valid-type">
                  <option value="relative">领取后 N 天有效</option>
                  <option value="absolute">固定时间窗口</option>
                </select>
              </label>
              <label v-if="form.valid_type === 'relative'" class="block">
                <span class="mb-1 block text-slate-500">有效天数 *</span>
                <input v-model.number="form.valid_days" type="number" min="1" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-valid-days" />
              </label>
              <template v-else>
                <label class="block">
                  <span class="mb-1 block text-slate-500">开始时间 *</span>
                  <input v-model="form.valid_from" type="datetime-local" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-valid-from" />
                </label>
                <label class="block">
                  <span class="mb-1 block text-slate-500">结束时间 *</span>
                  <input v-model="form.valid_to" type="datetime-local" :disabled="locked" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff] disabled:bg-slate-100 disabled:text-slate-400" data-testid="coupon-form-valid-to" />
                </label>
              </template>
            </div>
          </div>

          <div class="mt-5 flex justify-end gap-3">
            <button class="rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-500" data-testid="coupon-form-cancel" @click="formOpen = false">取消</button>
            <button class="rounded-md bg-[#1677ff] px-5 py-2 text-sm text-white hover:bg-[#4096ff] disabled:opacity-50" :disabled="saving" data-testid="coupon-form-save" @click="save">{{ saving ? '保存中…' : '保存' }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- 统计抽屉 -->
    <Teleport to="body">
      <div v-if="statsOpen" class="fixed inset-0 z-50 flex justify-end bg-black/40" @click.self="statsOpen = false">
        <div class="h-full w-full max-w-md overflow-y-auto bg-white p-6 shadow-xl" data-testid="coupon-stats-drawer">
          <h3 class="mb-4 flex items-center gap-2 text-base font-semibold text-slate-800">
            <SquarePlus class="h-4 w-4 text-[#1677ff]" /> 券效果统计
          </h3>
          <LoadingSpinner v-if="statsLoading" />
          <template v-else-if="stats">
            <p class="mb-4 text-sm font-medium text-slate-700">{{ stats.name }}</p>
            <div class="grid grid-cols-2 gap-3 text-sm" data-testid="coupon-stats-grid">
              <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-400">发放总量</p><p class="mt-1 text-lg font-semibold" data-testid="coupon-stats-total">{{ stats.total_count }}</p></div>
              <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-400">领取量</p><p class="mt-1 text-lg font-semibold" data-testid="coupon-stats-received">{{ stats.received_count }}</p></div>
              <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-400">核销量</p><p class="mt-1 text-lg font-semibold text-[#52c41a]" data-testid="coupon-stats-used">{{ stats.used_count }}</p></div>
              <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-400">核销率</p><p class="mt-1 text-lg font-semibold text-[#1677ff]" data-testid="coupon-stats-use-rate">{{ pct(stats.use_rate) }}</p></div>
              <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-400">领取率</p><p class="mt-1 text-lg font-semibold">{{ pct(stats.issue_rate) }}</p></div>
              <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-400">带来订单数</p><p class="mt-1 text-lg font-semibold" data-testid="coupon-stats-orders">{{ stats.order_count }}</p></div>
              <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-400">优惠总金额</p><p class="mt-1 text-lg font-semibold text-[#ff4d4f]">¥{{ stats.discount_sum.toFixed(2) }}</p></div>
              <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-400">订单实付总额</p><p class="mt-1 text-lg font-semibold">¥{{ stats.order_amount_sum.toFixed(2) }}</p></div>
            </div>
          </template>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title"
      :message="confirmState?.message"
      @confirm="() => { confirmState?.run(); confirmState = null }"
      @cancel="confirmState = null"
    />
  </div>
</template>
