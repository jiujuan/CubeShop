<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Search, FileText } from 'lucide-vue-next'

import {
  getPaymentReconciles,
  getPaymentReconcileDiffs,
  getPaymentReconcileStats,
  resolvePaymentReconcileDiff,
  RECONCILE_RUN_STATUS_CLASS,
  RECONCILE_RUN_STATUS_LABELS,
  RECONCILE_DIFF_TYPE_CLASS,
  RECONCILE_DIFF_TYPE_LABELS,
  RECONCILE_DIFF_TYPE_BAR,
  RECONCILE_DIFF_STATUS_CLASS,
  RECONCILE_DIFF_STATUS_LABELS,
  PAYMENT_CHANNEL_LABELS,
  PAY_PLATFORM_LABELS,
  exportPaymentReconcileDiffs,
  type PaymentChannel,
  type PayPlatform,
  type ReconcileRunStatus,
  type ReconcileDiffType,
  type ReconcileDiffStatus,
  type PaymentReconcileRunRow,
  type PaymentReconcileDiffRow,
  type PaymentReconcileStats,
} from '@/api/payment-reconcile'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 支付渠道日终对账（A7-支付渠道对账，权限 payment.reconcile.view / payment.reconcile.handle）
 *
 * 上半部「对账批次」按渠道/日汇总本地与渠道账单的笔数、金额与结论；
 * 下半部「差异工单」列出长短款、重复回调、漏单等差异，pending 可处置。
 * 差异处置是资金安全闭环的最后一步：resolve 按差异类型记录处置结论，ignore 仅关单。
 */
const channels = Object.keys(PAYMENT_CHANNEL_LABELS) as PaymentChannel[]

// ---------- 看板统计（可视化） ----------
const stats = ref<PaymentReconcileStats>({
  total_runs: 0,
  total_diffs: 0,
  pending_diffs: 0,
  processing_diffs: 0,
  resolved_diffs: 0,
  ignored_diffs: 0,
  by_type: {},
  by_channel: [],
  trend: [],
})
/** 分布展示的差异类型（UNKNOWN 仅在有数据时出现） */
const distTypes = computed<ReconcileDiffType[]>(() => {
  const base: ReconcileDiffType[] = ['MISSING_LOCAL', 'MISSING_CHANNEL', 'AMOUNT_MISMATCH', 'DUPLICATE_CALLBACK']
  if ((stats.value.by_type?.UNKNOWN ?? 0) > 0) base.push('UNKNOWN')
  return base
})
/** 类型分布条宽度占比（相对最大类型） */
function distPct(count: number): string {
  const max = Math.max(1, ...distTypes.value.map((t) => stats.value.by_type?.[t] ?? 0))
  return `${Math.round((count / max) * 100)}%`
}
/** 趋势柱高度占比（相对每日最大差异，最小 4% 保证可见） */
function trendHeight(diffs: number): string {
  const max = Math.max(1, ...stats.value.trend.map((p) => p.diffs))
  return `${Math.max(4, Math.round((diffs / max) * 100))}%`
}
/** 看板来源端筛选（不选 = 全部平台） */
const dashPlatform = ref<'' | PayPlatform>('')
/** 渠道拆分行宽度占比（相对最大差异数） */
function channelPct(diffs: number): string {
  const max = Math.max(1, ...stats.value.by_channel.map((r) => r.diffs))
  return `${Math.round((diffs / max) * 100)}%`
}
async function loadStats() {
  try {
    const { data } = await getPaymentReconcileStats({ platform: dashPlatform.value || undefined })
    stats.value = data.data
  } catch {
    // 看板保持零值
  }
}

// ---------- 对账批次 ----------
const runs = ref<PaymentReconcileRunRow[]>([])
const runPagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const runsLoading = ref(true)
const runDate = ref('')
const runChannel = ref<'' | PaymentChannel>('')
const runStatus = ref<'' | ReconcileRunStatus>('')

// ---------- 差异工单 ----------
const diffs = ref<PaymentReconcileDiffRow[]>([])
const diffPagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const diffsLoading = ref(true)
const tip = ref('')

const diffDate = ref('')
const diffChannel = ref<'' | PaymentChannel>('')
const diffType = ref<'' | ReconcileDiffType>('')
const diffStatus = ref<'' | ReconcileDiffStatus>('')
const diffPlatform = ref<'' | PayPlatform>('')
const diffKeyword = ref('')
const exporting = ref(false)

const confirmState = ref<{ id: number; payment_no: string; typeLabel: string; remark: string } | null>(null)
const pendingId = ref<number | null>(null)
const detailState = ref<PaymentReconcileDiffRow | null>(null)

async function loadRuns(page = 1) {
  runsLoading.value = true
  try {
    const { data } = await getPaymentReconciles({
      date: runDate.value || undefined,
      channel: runChannel.value || undefined,
      status: runStatus.value || undefined,
      page,
      page_size: runPagination.value.page_size,
    })
    runs.value = data.data.list
    runPagination.value = data.data.pagination
  } catch {
    runs.value = []
  } finally {
    runsLoading.value = false
  }
}

async function loadDiffs(page = 1) {
  diffsLoading.value = true
  tip.value = ''
  try {
    const { data } = await getPaymentReconcileDiffs({
      date: diffDate.value || undefined,
      channel: diffChannel.value || undefined,
      diff_type: diffType.value || undefined,
      status: diffStatus.value || undefined,
      platform: diffPlatform.value || undefined,
      keyword: diffKeyword.value.trim() || undefined,
      page,
      page_size: diffPagination.value.page_size,
    })
    diffs.value = data.data.list
    diffPagination.value = data.data.pagination
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '加载失败'
  } finally {
    diffsLoading.value = false
  }
}

function searchRuns() {
  loadRuns(1)
}

function searchDiffs() {
  loadDiffs(1)
}

/** 导出对账差异报告（CSV，随当前筛选全量） */
async function doExport() {
  exporting.value = true
  try {
    await exportPaymentReconcileDiffs({
      date: diffDate.value || undefined,
      channel: diffChannel.value || undefined,
      diff_type: diffType.value || undefined,
      status: diffStatus.value || undefined,
      platform: diffPlatform.value || undefined,
      keyword: diffKeyword.value.trim() || undefined,
    })
    tip.value = '对账差异报告已导出'
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '导出失败'
  } finally {
    exporting.value = false
  }
}

function goRunPage(page: number) {
  loadRuns(page)
}

function goDiffPage(page: number) {
  loadDiffs(page)
}

/** 金额展示：后端十进制字符串，统一 ¥ 前缀 */
function yuan(s: string | null): string {
  if (s === null || s === undefined || s === '') return '-'
  return `¥${s}`
}

function askResolve(row: PaymentReconcileDiffRow) {
  confirmState.value = {
    id: row.id,
    payment_no: row.payment_no || row.channel_trade_no || row.order_no || `#${row.id}`,
    typeLabel: row.diff_type_label,
    remark: '',
  }
}

async function doResolve() {
  const state = confirmState.value
  if (!state) return
  pendingId.value = state.id
  confirmState.value = null
  try {
    await resolvePaymentReconcileDiff(state.id, { action: 'resolve', remark: state.remark.trim() || undefined })
    tip.value = `已处置：${state.payment_no}（${state.typeLabel}）`
    await Promise.all([loadRuns(runPagination.value.page), loadDiffs(diffPagination.value.page)])
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '处置失败'
  } finally {
    pendingId.value = null
  }
}

async function doIgnore(row: PaymentReconcileDiffRow) {
  pendingId.value = row.id
  try {
    await resolvePaymentReconcileDiff(row.id, { action: 'ignore' })
    tip.value = `已忽略：${row.payment_no || row.channel_trade_no || row.order_no || `#${row.id}`}`
    await Promise.all([loadRuns(runPagination.value.page), loadDiffs(diffPagination.value.page)])
  } catch (e) {
    tip.value = (e as { message?: string })?.message ?? '操作失败'
  } finally {
    pendingId.value = null
  }
}

onMounted(() => {
  loadStats()
  loadRuns(1)
  loadDiffs(1)
})
</script>

<template>
  <div class="space-y-4">
    <!-- ============ 对账看板（可视化） ============ -->
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">对账看板</h2>
      <select v-model="dashPlatform" class="h-8 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-[13px] text-black" data-testid="dash-platform" @change="loadStats">
        <option value="">全部平台</option>
        <option v-for="(label, key) in PAY_PLATFORM_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
    </div>
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
      <div class="rounded-lg bg-white p-5 shadow-sm">
        <div class="text-xs text-slate-400">对账批次总数</div>
        <div class="mt-1 text-2xl font-semibold text-slate-800" data-testid="stat-runs">{{ stats.total_runs }}</div>
      </div>
      <div class="rounded-lg bg-white p-5 shadow-sm">
        <div class="text-xs text-slate-400">差异工单总数</div>
        <div class="mt-1 text-2xl font-semibold text-slate-800" data-testid="stat-diffs">{{ stats.total_diffs }}</div>
      </div>
      <div class="rounded-lg bg-white p-5 shadow-sm">
        <div class="text-xs text-slate-400">待处理</div>
        <div class="mt-1 text-2xl font-semibold" :class="stats.pending_diffs > 0 ? 'text-amber-600' : 'text-emerald-600'" data-testid="stat-pending">{{ stats.pending_diffs }}</div>
      </div>
      <div class="rounded-lg bg-white p-5 shadow-sm">
        <div class="text-xs text-slate-400">已闭环（处置+忽略）</div>
        <div class="mt-1 text-2xl font-semibold text-slate-800" data-testid="stat-closed">{{ stats.resolved_diffs + stats.ignored_diffs }}</div>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <!-- 差异类型分布 -->
      <div class="rounded-lg bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold text-slate-800">差异类型分布</h2>
        <div class="space-y-3">
          <div v-for="t in distTypes" :key="t" class="text-[13px]" :data-testid="`dist-${t}`">
            <div class="mb-1 flex justify-between">
              <span class="text-slate-600">{{ RECONCILE_DIFF_TYPE_LABELS[t] }}</span>
              <span class="font-mono text-slate-800">{{ stats.by_type[t] ?? 0 }}</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded bg-slate-100">
              <div class="h-2 rounded" :class="RECONCILE_DIFF_TYPE_BAR[t]" :style="{ width: distPct(stats.by_type[t] ?? 0) }"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- 近 14 天差异趋势 -->
      <div class="rounded-lg bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold text-slate-800">近 14 天差异趋势</h2>
        <div class="flex h-40 items-end gap-1" data-testid="trend">
          <div
            v-for="(p, idx) in stats.trend"
            :key="p.date"
            class="flex flex-1 flex-col items-center justify-end"
            :title="`${p.date}：${p.diffs} 笔`"
          >
            <div class="w-full rounded bg-[#1677ff]" :style="{ height: trendHeight(p.diffs) }"></div>
            <div class="mt-1 text-[10px] text-slate-400">{{ idx % 2 === 0 ? p.date.slice(5) : '' }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- 按渠道拆分 -->
    <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="by-channel">
      <h2 class="mb-4 text-sm font-semibold text-slate-800">按渠道拆分</h2>
      <div v-if="!stats.by_channel.length" class="py-6 text-center text-[13px] text-slate-400">暂无差异数据</div>
      <div class="space-y-3">
        <div v-for="r in stats.by_channel" :key="r.channel" class="text-[13px]">
          <div class="mb-1 flex items-center justify-between">
            <span class="text-slate-600">{{ PAYMENT_CHANNEL_LABELS[r.channel] ?? r.channel }}</span>
            <span class="text-slate-500">
              差异 <b class="font-mono text-slate-800">{{ r.diffs }}</b>
              · 待处理 <b class="font-mono text-amber-600">{{ r.pending }}</b>
              · 已处置 <b class="font-mono text-emerald-600">{{ r.resolved }}</b>
              · 已忽略 <b class="font-mono text-slate-500">{{ r.ignored }}</b>
            </span>
          </div>
          <div class="h-2 w-full overflow-hidden rounded bg-slate-100">
            <div class="h-2 rounded bg-[#1677ff]" :style="{ width: channelPct(r.diffs) }"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ 对账批次 ============ -->
    <div class="rounded-lg bg-white p-5 shadow-sm">
      <h2 class="mb-4 text-lg font-semibold text-slate-800">对账批次</h2>

      <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
        <input v-model="runDate" type="date" class="h-8 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-black" data-testid="run-date" />
        <select v-model="runChannel" class="h-8 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-black" data-testid="run-channel">
          <option value="">全部渠道</option>
          <option v-for="c in channels" :key="c" :value="c">{{ PAYMENT_CHANNEL_LABELS[c] }}</option>
        </select>
        <select v-model="runStatus" class="h-8 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-black" data-testid="run-status">
          <option value="">全部结论</option>
          <option v-for="(label, key) in RECONCILE_RUN_STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
        <button class="flex h-8 items-center gap-1 rounded bg-[#1677ff] px-3 text-white hover:bg-[#4096ff]" data-testid="run-search" @click="searchRuns">
          <Search class="h-3.5 w-3.5" /> 查询
        </button>
      </div>

      <table class="w-full text-[13px]">
        <thead>
          <tr class="border-b border-slate-200 text-left text-slate-500">
            <th class="px-3 py-1.5">对账日期</th>
            <th class="w-24 px-3 py-1.5">渠道</th>
            <th class="w-20 px-3 py-1.5">结论</th>
            <th class="w-16 px-3 py-1.5">本地</th>
            <th class="w-16 px-3 py-1.5">渠道</th>
            <th class="w-16 px-3 py-1.5">匹配</th>
            <th class="w-16 px-3 py-1.5">差异</th>
            <th class="w-28 px-3 py-1.5">本地金额</th>
            <th class="w-28 px-3 py-1.5">渠道金额</th>
            <th class="w-36 px-3 py-1.5">完成时间</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in runs" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`run-${row.id}`">
            <td class="px-3 py-1.5 font-mono text-black">{{ row.reconcile_date }}</td>
            <td class="px-3 py-1.5 text-black">{{ row.channel_label }}</td>
            <td class="px-3 py-1.5">
              <span class="rounded px-2 py-0.5 text-xs" :class="RECONCILE_RUN_STATUS_CLASS[row.status]">{{ RECONCILE_RUN_STATUS_LABELS[row.status] }}</span>
            </td>
            <td class="px-3 py-1.5 text-black">{{ row.local_count }}</td>
            <td class="px-3 py-1.5 text-black">{{ row.channel_count }}</td>
            <td class="px-3 py-1.5 text-black">{{ row.matched_count }}</td>
            <td class="px-3 py-1.5 font-medium" :class="row.diff_count > 0 ? 'text-red-500' : 'text-emerald-600'">{{ row.diff_count }}</td>
            <td class="px-3 py-1.5 font-mono text-black">{{ yuan(row.local_amount) }}</td>
            <td class="px-3 py-1.5 font-mono text-black">{{ yuan(row.channel_amount) }}</td>
            <td class="px-3 py-1.5 text-black">{{ row.finished_at || '-' }}</td>
          </tr>
          <tr v-if="runsLoading">
            <td colspan="10"><LoadingSpinner /></td>
          </tr>
          <tr v-if="!runs.length && !runsLoading">
            <td colspan="10" class="px-3 py-12 text-center text-slate-400" data-testid="run-empty">暂无对账批次</td>
          </tr>
        </tbody>
      </table>

      <TablePagination :pagination="runPagination" @change="goRunPage" />
    </div>

    <!-- ============ 差异工单 ============ -->
    <div class="rounded-lg bg-white p-5 shadow-sm">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-800">差异工单</h2>
      </div>

      <p v-if="tip" class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-600" data-testid="tip">{{ tip }}</p>

      <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
        <input v-model="diffDate" type="date" class="h-8 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-black" data-testid="filter-date" />
        <select v-model="diffChannel" class="h-8 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-black" data-testid="filter-channel">
          <option value="">全部渠道</option>
          <option v-for="c in channels" :key="c" :value="c">{{ PAYMENT_CHANNEL_LABELS[c] }}</option>
        </select>
        <select v-model="diffType" class="h-8 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-black" data-testid="filter-type">
          <option value="">全部类型</option>
          <option v-for="(label, key) in RECONCILE_DIFF_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
        <select v-model="diffStatus" class="h-8 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-black" data-testid="filter-status">
          <option value="">全部状态</option>
          <option v-for="(label, key) in RECONCILE_DIFF_STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
        <input v-model="diffKeyword" placeholder="支付单号 / 渠道流水号 / 订单号" class="h-8 w-56 rounded border border-slate-200 px-2 outline-none focus:border-[#1677ff] text-black" data-testid="filter-keyword" />
        <button class="flex h-8 items-center gap-1 rounded bg-[#1677ff] px-3 text-white hover:bg-[#4096ff]" data-testid="diff-search" @click="searchDiffs">
          <Search class="h-3.5 w-3.5" /> 查询
        </button>
        <button
          class="flex h-8 items-center gap-1 rounded border border-slate-200 px-3 text-slate-600 hover:bg-slate-50 disabled:opacity-40"
          :disabled="exporting"
          data-testid="diff-export"
          @click="doExport"
        >
          <FileText class="h-3.5 w-3.5" /> {{ exporting ? '导出中…' : '导出报告' }}
        </button>
      </div>

      <table class="w-full text-[13px]">
        <thead>
          <tr class="border-b border-slate-200 text-left text-slate-500">
            <th class="px-3 py-1.5">日期</th>
            <th class="w-20 px-3 py-1.5">渠道</th>
            <th class="w-20 px-3 py-1.5">平台</th>
            <th class="w-32 px-3 py-1.5">类型</th>
            <th class="px-3 py-1.5">关联单号</th>
            <th class="w-24 px-3 py-1.5">本地金额</th>
            <th class="w-24 px-3 py-1.5">渠道金额</th>
            <th class="w-20 px-3 py-1.5">状态</th>
            <th class="w-40 px-3 py-1.5">处置时间</th>
            <th class="w-44 px-3 py-1.5">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in diffs" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`row-${row.id}`">
            <td class="px-3 py-1.5 font-mono text-black">{{ row.reconcile_date }}</td>
            <td class="px-3 py-1.5 text-black">{{ row.channel_label }}</td>
            <td class="px-3 py-1.5 text-black">{{ row.platform_label || '-' }}</td>
            <td class="px-3 py-1.5">
              <span class="rounded px-2 py-0.5 text-xs" :class="RECONCILE_DIFF_TYPE_CLASS[row.diff_type]">{{ RECONCILE_DIFF_TYPE_LABELS[row.diff_type] }}</span>
            </td>
            <td class="px-3 py-1.5 text-black">
              <div class="font-mono">{{ row.payment_no || '-' }}</div>
              <div class="font-mono text-[11px] text-slate-400">{{ row.channel_trade_no || row.order_no || '' }}</div>
            </td>
            <td class="px-3 py-1.5 font-mono text-black">{{ yuan(row.local_amount) }}</td>
            <td class="px-3 py-1.5 font-mono text-black">{{ yuan(row.channel_amount) }}</td>
            <td class="px-3 py-1.5">
              <span class="rounded px-2 py-0.5 text-xs" :class="RECONCILE_DIFF_STATUS_CLASS[row.status]">{{ RECONCILE_DIFF_STATUS_LABELS[row.status] }}</span>
            </td>
            <td class="px-3 py-1.5 text-black">{{ row.handled_at || '-' }}</td>
            <td class="px-3 py-1.5">
              <button class="text-slate-500 hover:underline" :data-testid="`detail-${row.id}`" @click="detailState = row">详情</button>
              <template v-if="row.status === 'pending'">
                <span class="mx-1 text-slate-200">|</span>
                <button
                  class="text-[#1677ff] hover:underline disabled:opacity-40"
                  :disabled="pendingId === row.id"
                  :data-testid="`resolve-${row.id}`"
                  @click="askResolve(row)"
                >处置</button>
                <span class="mx-1 text-slate-200">|</span>
                <button
                  class="text-slate-500 hover:underline disabled:opacity-40"
                  :disabled="pendingId === row.id"
                  :data-testid="`ignore-${row.id}`"
                  @click="doIgnore(row)"
                >忽略</button>
              </template>
            </td>
          </tr>
          <tr v-if="diffsLoading">
            <td colspan="10"><LoadingSpinner /></td>
          </tr>
          <tr v-if="!diffs.length && !diffsLoading">
            <td colspan="10" class="px-3 py-12 text-center text-slate-400" data-testid="empty">暂无差异，资金一致</td>
          </tr>
        </tbody>
      </table>

      <TablePagination :pagination="diffPagination" @change="goDiffPage" />
    </div>

    <!-- 处置确认 -->
    <ConfirmDialog
      :open="!!confirmState"
      title="处置差异工单"
      :message="confirmState
        ? `差异类型：${confirmState.typeLabel}。处置将记录处置结论并关单，请确认已核实资金流向。`
        : ''"
      danger
      confirm-text="确认处置"
      @cancel="confirmState = null"
      @confirm="doResolve"
    >
      <input
        v-if="confirmState"
        v-model="confirmState.remark"
        placeholder="处置备注（可选，最多 500 字）"
        class="mt-3 h-8 w-full rounded border border-slate-200 px-2 text-[13px] text-black outline-none focus:border-[#1677ff]"
        data-testid="resolve-remark"
      />
    </ConfirmDialog>

    <!-- 差异详情 -->
    <ConfirmDialog
      :open="!!detailState"
      title="差异详情"
      :show-confirm="false"
      cancel-text="关闭"
      @cancel="detailState = null"
    >
      <div v-if="detailState" class="space-y-2 text-[13px] text-black">
        <div class="flex justify-between"><span class="text-slate-500">对账日期</span><span class="font-mono">{{ detailState.reconcile_date }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">渠道</span><span>{{ detailState.channel_label }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">平台</span><span>{{ detailState.platform_label || '-' }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">类型</span><span>{{ detailState.diff_type_label }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">支付单号</span><span class="font-mono">{{ detailState.payment_no || '-' }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">渠道流水号</span><span class="font-mono">{{ detailState.channel_trade_no || '-' }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">订单号</span><span class="font-mono">{{ detailState.order_no || '-' }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">本地金额</span><span class="font-mono">{{ yuan(detailState.local_amount) }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">渠道金额</span><span class="font-mono">{{ yuan(detailState.channel_amount) }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">本地状态</span><span class="font-mono">{{ detailState.local_status || '-' }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">渠道状态</span><span class="font-mono">{{ detailState.channel_status || '-' }}</span></div>
        <div v-if="detailState.detail" class="rounded bg-slate-50 p-2 text-[12px] leading-relaxed text-slate-600">
          <FileText class="mr-1 inline h-3.5 w-3.5 align-text-bottom" />{{ detailState.detail }}
        </div>
        <div v-if="detailState.handle_remark" class="rounded bg-slate-50 p-2 text-[12px] text-slate-600">处置备注：{{ detailState.handle_remark }}</div>
      </div>
    </ConfirmDialog>
  </div>
</template>
