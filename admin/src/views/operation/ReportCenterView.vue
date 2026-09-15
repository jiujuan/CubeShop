<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Download } from 'lucide-vue-next'
import {
  exportOrders, getCategoryShare, getReportOverview, getReportTrend, getReportUsers, getTopProducts,
  type CategoryShareItem, type ExportResult, type ReportOverview, type TopProduct, type TrendPoint, type UserReport,
} from '@/api/report'
import BarChart from '@/components/charts/BarChart.vue'
import DonutChart from '@/components/charts/DonutChart.vue'
import LineChart from '@/components/charts/LineChart.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 报表中心（V1.1 F03 / T-021）
 *
 * 区间查询（快捷 + 自定义）+ 明细导出（含上限提示）。
 * 区间上限 90 天：超出时提示并阻断导出。
 */
const MAX_RANGE_DAYS = 90

type QuickKey = 'today' | 'yesterday' | 'last7' | 'last30' | 'custom'

const quickOptions: Array<{ key: QuickKey; label: string }> = [
  { key: 'today', label: '今日' },
  { key: 'yesterday', label: '昨日' },
  { key: 'last7', label: '近 7 日' },
  { key: 'last30', label: '近 30 日' },
  { key: 'custom', label: '自定义' },
]

const quick = ref<QuickKey>('last7')
const start = ref(fmtDate(daysAgo(6)))
const end = ref(fmtDate(new Date()))

const loading = ref(true)
const exporting = ref(false)
const error = ref('')
const tip = ref('')
const tipType = ref<'ok' | 'warn'>('ok')

const overview = ref<ReportOverview | null>(null)
const trend = ref<TrendPoint[]>([])
const topProducts = ref<TopProduct[]>([])
const categoryShare = ref<CategoryShareItem[]>([])
const userReport = ref<UserReport | null>(null)
const exportResult = ref<ExportResult | null>(null)

const rangeDays = computed(() => {
  const s = new Date(start.value).getTime()
  const e = new Date(end.value).getTime()
  if (Number.isNaN(s) || Number.isNaN(e) || e < s) return 0
  return Math.floor((e - s) / 86400000) + 1
})

const rangeValid = computed(() => rangeDays.value >= 1 && rangeDays.value <= MAX_RANGE_DAYS)

const trendLabels = computed(() => trend.value.map((t) => t.date.slice(5)))
const topItems = computed(() => topProducts.value.map((p) => ({ label: p.title, value: Number(p.amount) })))
const shareItems = computed(() => categoryShare.value.map((c) => ({ label: c.category_name, value: Number(c.amount) })))

function pad(n: number): string {
  return String(n).padStart(2, '0')
}
function fmtDate(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
function daysAgo(n: number): Date {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d
}
function money(v: string | number): string {
  const n = Number(v)
  return Number.isNaN(n) ? '¥0.00' : `¥${n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
function thousands(v: number): string {
  return Number(v).toLocaleString('zh-CN')
}

/** 应用快捷区间 */
function applyQuick(key: QuickKey) {
  quick.value = key
  const today = fmtDate(new Date())
  if (key === 'today') { start.value = today; end.value = today }
  else if (key === 'yesterday') { const y = fmtDate(daysAgo(1)); start.value = y; end.value = y }
  else if (key === 'last7') { start.value = fmtDate(daysAgo(6)); end.value = today }
  else if (key === 'last30') { start.value = fmtDate(daysAgo(29)); end.value = today }
  // custom：保持当前值，由用户手动选择
  if (key !== 'custom') load()
}

/** 自定义区间变化：标记为 custom 并刷新 */
function onCustomChange() {
  quick.value = 'custom'
  load()
}

async function load() {
  loading.value = true
  error.value = ''
  tip.value = ''
  try {
    const span = rangeDays.value >= 1 && rangeDays.value <= MAX_RANGE_DAYS ? rangeDays.value : 30
    const [o, t, tp, cs, u] = await Promise.all([
      getReportOverview(),
      getReportTrend(span),
      getTopProducts({ limit: 10, days: span }),
      getCategoryShare(span),
      getReportUsers(span),
    ])
    overview.value = o.data.data
    trend.value = t.data.data.series
    topProducts.value = tp.data.data.list
    categoryShare.value = cs.data.data.items
    userReport.value = u.data.data
  } catch (e) {
    error.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

async function doExport() {
  tip.value = ''
  if (!rangeValid.value) {
    tipType.value = 'warn'
    tip.value = `区间需在 1 ~ ${MAX_RANGE_DAYS} 天内（当前 ${rangeDays.value || 0} 天）`
    return
  }
  exporting.value = true
  try {
    const { data } = await exportOrders(start.value, end.value)
    exportResult.value = data.data
    tipType.value = 'ok'
    tip.value = data.data.truncated
      ? `已导出前 ${data.data.rows.length} 行（共 ${data.data.total} 行，超出上限 ${data.data.limit}，请缩小区间）`
      : `已导出 ${data.data.rows.length} 行`
  } catch (e) {
    tipType.value = 'warn'
    tip.value = e instanceof Error ? e.message : '导出失败'
  } finally {
    exporting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-lg font-semibold">报表中心</h1>
    </div>

    <!-- 区间选择 -->
    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-white p-4" data-testid="range-bar">
      <div class="flex gap-1">
        <button
          v-for="opt in quickOptions" :key="opt.key"
          class="rounded px-2.5 py-1 text-xs transition-colors"
          :class="quick === opt.key ? 'bg-[#1677ff] text-white' : 'text-slate-500 hover:bg-slate-100'"
          :data-testid="`quick-${opt.key}`"
          @click="applyQuick(opt.key)"
        >{{ opt.label }}</button>
      </div>
      <div class="ml-2 flex items-center gap-1.5 text-[13px]">
        <input
          v-model="start" type="date" data-testid="range-start"
          class="rounded-md border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]"
          @change="onCustomChange"
        />
        <span class="text-slate-400">至</span>
        <input
          v-model="end" type="date" data-testid="range-end"
          class="rounded-md border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]"
          @change="onCustomChange"
        />
      </div>
      <span class="text-xs text-slate-400" data-testid="range-days">共 {{ rangeDays }} 天（上限 {{ MAX_RANGE_DAYS }} 天）</span>
      <button
        class="ml-auto flex items-center gap-1.5 rounded-md bg-[#1677ff] px-3 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-60"
        :disabled="exporting" data-testid="export-btn"
        @click="doExport"
      >
        <Download class="h-3.5 w-3.5" />{{ exporting ? '导出中…' : '导出明细' }}
      </button>
    </div>

    <p
      v-if="tip"
      class="rounded-md px-3 py-2 text-[13px]"
      :class="tipType === 'ok' ? 'bg-green-50 text-green-600' : 'bg-amber-50 text-amber-600'"
      data-testid="export-tip"
    >{{ tip }}</p>

    <div v-if="loading" class="rounded-lg border border-slate-200 bg-white p-10"><LoadingSpinner /></div>

    <div v-else-if="error" class="flex items-center justify-between rounded-lg border border-red-200 bg-red-50 p-4 text-[13px] text-red-500">
      <span>{{ error }}</span>
      <button class="rounded border border-red-300 px-2 py-0.5 hover:bg-red-100" @click="load">重试</button>
    </div>

    <template v-else>
      <!-- 概览数字 -->
      <div v-if="overview" class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <p class="text-xs text-slate-400">区间订单量</p>
          <p class="text-xl font-semibold text-slate-800">{{ thousands(trend.reduce((s, t) => s + t.orders, 0)) }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <p class="text-xs text-slate-400">区间销售额</p>
          <p class="text-xl font-semibold text-slate-800">{{ money(trend.reduce((s, t) => s + Number(t.sales), 0)) }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <p class="text-xs text-slate-400">新增用户</p>
          <p class="text-xl font-semibold text-slate-800">{{ userReport?.total_new_users ?? 0 }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <p class="text-xs text-slate-400">复购率</p>
          <p class="text-xl font-semibold text-slate-800">{{ userReport?.repurchase_rate ?? 0 }}%</p>
          <p class="mt-0.5 text-xs text-slate-400">复购 {{ userReport?.repeat_buyers ?? 0 }} / 买家 {{ userReport?.buyers ?? 0 }}</p>
        </div>
      </div>

      <!-- 趋势 -->
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-3 text-sm font-medium">订单与销售趋势</h2>
        <LineChart
          :labels="trendLabels"
          :series="[
            { name: '订单量', data: trend.map((t) => t.orders), color: '#1677ff' },
            { name: '销售额', data: trend.map((t) => Number(t.sales)), color: '#faad14' },
          ]"
          :format="(v) => thousands(Math.round(v))"
        />
      </div>

      <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-medium">商品销售 TOP10</h2>
          <BarChart :items="topItems" :format="(v) => money(v)" />
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-medium">分类销售额占比</h2>
          <DonutChart :items="shareItems" :format="(v) => money(v)" />
        </div>
      </div>

      <!-- 导出预览 -->
      <div v-if="exportResult" class="rounded-lg border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <h2 class="text-sm font-medium">导出预览</h2>
          <span class="text-xs text-slate-400">共 {{ exportResult.total }} 行{{ exportResult.truncated ? `（截取前 ${exportResult.limit} 行）` : '' }}</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="bg-slate-50 text-left text-slate-400">
                <th v-for="k in Object.keys(exportResult.rows[0] ?? {})" :key="k" class="px-4 py-2 font-normal">{{ k }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in exportResult.rows.slice(0, 50)" :key="i" class="border-t border-slate-50">
                <td v-for="(v, k) in row" :key="k" class="px-4 py-2 text-slate-600">{{ v }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="exportResult.rows.length > 50" class="border-t border-slate-50 px-4 py-2 text-xs text-slate-400">仅预览前 50 行，完整数据请下载导出文件</p>
      </div>
    </template>
  </div>
</template>
