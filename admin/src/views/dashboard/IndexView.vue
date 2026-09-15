<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  AlertTriangle, BadgeDollarSign, ClipboardList, MessageSquare, PackageCheck,
  RotateCcw, TrendingDown, TrendingUp,
} from 'lucide-vue-next'
import {
  getReportOverview, getReportTrend, getTopProducts, getCategoryShare,
  type ReportOverview, type TopProduct, type CategoryShareItem, type TrendPoint,
} from '@/api/report'
import BarChart from '@/components/charts/BarChart.vue'
import DonutChart from '@/components/charts/DonutChart.vue'
import LineChart from '@/components/charts/LineChart.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 运营驾驶舱（V1.1 F03 / T-021）
 *
 * 指标卡（含环比）+ 销售趋势双轴 + 商品 TOP10 + 分类占比 + 待办聚合。
 * 图表为无依赖 SVG 实现，口径与后端 ReportService 一致。
 */
const router = useRouter()

const loading = ref(true)
const error = ref('')
const overview = ref<ReportOverview | null>(null)
const trend = ref<TrendPoint[]>([])
const topProducts = ref<TopProduct[]>([])
const categoryShare = ref<CategoryShareItem[]>([])

const trendDays = ref(30)

const daysOptions = [7, 30, 90]

/** 环比（相对昨日） */
function delta(today: string | number, yesterday: string | number): number {
  const t = Number(today)
  const y = Number(yesterday)
  if (y === 0) return t > 0 ? 100 : 0
  return Math.round(((t - y) / y) * 100)
}

const salesDelta = computed(() => (overview.value ? delta(overview.value.today.sales, overview.value.yesterday.sales) : 0))
const ordersDelta = computed(() => (overview.value ? delta(overview.value.today.orders, overview.value.yesterday.orders) : 0))

function money(v: string | number): string {
  const n = Number(v)
  if (Number.isNaN(n)) return '¥0.00'
  return `¥${n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
function thousands(v: number): string {
  return Number(v).toLocaleString('zh-CN')
}

interface MetricCard {
  key: string
  label: string
  value: string
  sub: string
  delta?: number
  icon: unknown
  tone: string
}

const cards = computed<MetricCard[]>(() => {
  const o = overview.value
  if (!o) return []
  return [
    {
      key: 'today_sales', label: '今日销售额', value: money(o.today.sales),
      sub: `昨日 ${money(o.yesterday.sales)}`, delta: salesDelta.value,
      icon: BadgeDollarSign, tone: 'text-[#1677ff] bg-blue-50',
    },
    {
      key: 'today_orders', label: '今日订单量', value: thousands(o.today.orders),
      sub: `昨日 ${o.yesterday.orders} 单`, delta: ordersDelta.value,
      icon: ClipboardList, tone: 'text-cyan-600 bg-cyan-50',
    },
    {
      key: 'aov', label: '今日客单价', value: money(o.today.aov),
      sub: `近 7 日 ${money(o.last_7_days.aov)}`,
      icon: BadgeDollarSign, tone: 'text-green-600 bg-green-50',
    },
    {
      key: 'conversion', label: '支付转化率', value: `${o.conversion_rate}%`,
      sub: `近 7 日 ${o.last_7_days.conversion_rate}%`,
      icon: TrendingUp, tone: 'text-purple-500 bg-purple-50',
    },
  ]
})

/** 待办聚合卡（点击直达） */
const todos = computed(() => {
  const p = overview.value?.pending
  if (!p) return []
  return [
    { key: 'ship', label: '待发货', value: p.ship, path: '/orders?status=paid', tone: 'text-orange-500 bg-orange-50', icon: PackageCheck },
    { key: 'refund', label: '待处理退款', value: p.refund, path: '/refunds', tone: 'text-red-500 bg-red-50', icon: RotateCcw },
    { key: 'review', label: '待审核评价', value: p.review, path: '/reviews?status=pending', tone: 'text-[#1677ff] bg-blue-50', icon: MessageSquare },
    { key: 'stock', label: '库存预警', value: p.stock_warning, path: '/products', tone: 'text-amber-600 bg-amber-50', icon: AlertTriangle },
  ]
})

/** 趋势图：订单量 + 销售额双序列 */
const trendLabels = computed(() => trend.value.map((t) => t.date.slice(5)))
const trendOrders = computed(() => trend.value.map((t) => t.orders))
const trendSales = computed(() => trend.value.map((t) => Number(t.sales)))

const topItems = computed(() => topProducts.value.map((p) => ({ label: p.title, value: Number(p.amount) })))
const shareItems = computed(() => categoryShare.value.map((c) => ({ label: c.category_name, value: Number(c.amount) })))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [o, t, tp, cs] = await Promise.all([
      getReportOverview(),
      getReportTrend(trendDays.value),
      getTopProducts({ limit: 10, days: trendDays.value }),
      getCategoryShare(trendDays.value),
    ])
    overview.value = o.data.data
    trend.value = t.data.data.series
    topProducts.value = tp.data.data.list
    categoryShare.value = cs.data.data.items
  } catch (e) {
    error.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

async function changeRange(days: number) {
  trendDays.value = days
  await load()
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-lg font-semibold">工作台</h1>
      <button class="text-[13px] text-slate-400 hover:text-[#1677ff]" @click="load">刷新</button>
    </div>

    <div v-if="loading" class="rounded-lg border border-slate-200 bg-white p-10"><LoadingSpinner /></div>

    <div v-else-if="error" class="flex items-center justify-between rounded-lg border border-red-200 bg-red-50 p-4 text-[13px] text-red-500">
      <span>{{ error }}</span>
      <button class="rounded border border-red-300 px-2 py-0.5 hover:bg-red-100" @click="load">重试</button>
    </div>

    <template v-else-if="overview">
      <!-- 指标卡 -->
      <div class="grid grid-cols-2 gap-3 xl:grid-cols-4" data-testid="metric-cards">
        <div
          v-for="card in cards" :key="card.key"
          class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-4"
          :data-testid="`metric-${card.key}`"
        >
          <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg" :class="card.tone">
            <component :is="card.icon" class="h-5 w-5" />
          </div>
          <div class="min-w-0">
            <p class="text-xs text-slate-400">{{ card.label }}</p>
            <p class="truncate text-xl font-semibold text-slate-800">{{ card.value }}</p>
            <p class="mt-0.5 flex items-center gap-1 text-xs text-slate-400">
              <template v-if="card.delta !== undefined">
                <component :is="card.delta >= 0 ? TrendingUp : TrendingDown" class="h-3 w-3" :class="card.delta >= 0 ? 'text-red-500' : 'text-green-600'" />
                较昨日 {{ card.delta >= 0 ? '+' : '' }}{{ card.delta }}%
              </template>
              <template v-else>{{ card.sub }}</template>
            </p>
          </div>
        </div>
      </div>

      <!-- 待办聚合 -->
      <div class="grid grid-cols-2 gap-3 xl:grid-cols-4" data-testid="todo-cards">
        <button
          v-for="todo in todos" :key="todo.key"
          class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-4 text-left transition-colors hover:border-[#1677ff]"
          :data-testid="`todo-${todo.key}`"
          @click="router.push(todo.path)"
        >
          <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="todo.tone">
            <component :is="todo.icon" class="h-4 w-4" />
          </div>
          <div>
            <p class="text-xs text-slate-400">{{ todo.label }}</p>
            <p class="text-lg font-semibold text-slate-800">{{ todo.value }}</p>
          </div>
        </button>
      </div>

      <!-- 趋势 -->
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-sm font-medium">销售趋势</h2>
          <div class="flex gap-1">
            <button
              v-for="d in daysOptions" :key="d"
              class="rounded px-2.5 py-1 text-xs transition-colors"
              :class="trendDays === d ? 'bg-[#1677ff] text-white' : 'text-slate-500 hover:bg-slate-100'"
              :data-testid="`trend-range-${d}`"
              @click="changeRange(d)"
            >近 {{ d }} 日</button>
          </div>
        </div>
        <LineChart
          :labels="trendLabels"
          :series="[
            { name: '订单量', data: trendOrders, color: '#1677ff' },
            { name: '销售额', data: trendSales, color: '#faad14' },
          ]"
          :format="(v) => thousands(Math.round(v))"
        />
      </div>

      <!-- TOP10 + 分类占比 -->
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
    </template>
  </div>
</template>
