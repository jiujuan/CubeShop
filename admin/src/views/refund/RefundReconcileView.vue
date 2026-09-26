<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { X } from 'lucide-vue-next'

import {
  getPaymentReconcileDiffs,
  resolvePaymentReconcileDiff,
  exportPaymentReconcileDiffs,
  RECONCILE_DIFF_TYPE_LABELS,
  RECONCILE_DIFF_TYPE_CLASS,
  RECONCILE_DIFF_STATUS_LABELS,
  RECONCILE_DIFF_STATUS_CLASS,
  PAYMENT_CHANNEL_LABELS,
  type PaymentChannel,
  type ReconcileDiffStatus,
  type PaymentReconcileDiffRow,
} from '@/api/payment-reconcile'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 退款对账差异页（权限 payment.reconcile.view / handle）
 *
 * Phase 5 的 RefundReconcileService 已把退款差异写入 payment_reconciliation_diffs
 * （diff_type 以 REFUND_ 开头）。本页通过 category=refund 过滤出来，供财务/运营核查处置。
 * 复用支付对账的差异处置/导出能力（同一张表、同一控制器）。
 * 布局与「支付日志」页一致：标题/Tab/表格/分页整体收在一张白色卡片内。
 */
type DiffTab = '' | ReconcileDiffStatus

const diffs = ref<PaymentReconcileDiffRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')
const tab = ref<DiffTab>('')
const resolveTip = ref('')

async function load() {
  loading.value = true
  try {
    const res = await getPaymentReconcileDiffs({
      category: 'refund',
      status: tab.value || undefined,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    diffs.value = res.data.data.list
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(load)

function filterTab(t: DiffTab) {
  tab.value = t
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

async function resolveDiff(row: PaymentReconcileDiffRow, action: 'resolve' | 'ignore') {
  resolveTip.value = ''
  try {
    await resolvePaymentReconcileDiff(row.id, { action })
    await load()
  } catch (e) {
    resolveTip.value = e instanceof Error ? e.message : '处置失败'
  }
}

function exportCsv() {
  exportPaymentReconcileDiffs({ category: 'refund', status: tab.value || undefined })
}

// ---------- 处置详情抽屉（只读展示差异双方字段） ----------

const detail = ref<PaymentReconcileDiffRow | null>(null)
function openDetail(row: PaymentReconcileDiffRow) {
  detail.value = row
}
function closeDetail() {
  detail.value = null
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">退款对账差异</h2>
        <p class="mt-0.5 text-xs text-slate-400">
          由「退款对账」任务每日比对本地退款与渠道状态生成；差异类型以 REFUND_ 开头。
        </p>
      </div>
      <button class="rounded bg-[#1677ff] px-3 py-1.5 text-sm text-white hover:bg-[#4096ff]" @click="exportCsv">导出 CSV</button>
    </div>

    <!-- 处置状态 Tab -->
    <div class="mt-4 flex flex-wrap gap-2">
      <button
        class="rounded-md px-3 py-1.5 text-sm"
        :class="tab === '' ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600'"
        @click="filterTab('')"
      >全部</button>
      <button
        v-for="t in (['pending', 'processing', 'resolved', 'ignored'] as const)"
        :key="t"
        class="rounded-md px-3 py-1.5 text-sm"
        :class="tab === t ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600'"
        @click="filterTab(t)"
      >{{ RECONCILE_DIFF_STATUS_LABELS[t] }}</button>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><LoadingSpinner /></div>
    <template v-else>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-100 text-left text-slate-500">
            <th class="py-2 pr-3">对账日期</th>
            <th class="py-2 pr-3">渠道</th>
            <th class="py-2 pr-3">差异类型</th>
            <th class="py-2 pr-3">退款单号</th>
            <th class="py-2 pr-3">渠道退单号</th>
            <th class="py-2 pr-3">本地状态</th>
            <th class="py-2 pr-3">渠道状态</th>
            <th class="py-2 pr-3">处置</th>
            <th class="py-2">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="d in diffs"
            :key="d.id"
            :data-testid="`diff-row-${d.id}`"
            class="border-b last:border-0"
          >
            <td class="py-2 pr-3 text-xs">{{ d.reconcile_date }}</td>
            <td class="py-2 pr-3">{{ PAYMENT_CHANNEL_LABELS[d.channel as PaymentChannel] ?? d.channel }}</td>
            <td class="py-2 pr-3">
              <span class="rounded px-2 py-0.5 text-xs" :class="RECONCILE_DIFF_TYPE_CLASS[d.diff_type]">
                {{ RECONCILE_DIFF_TYPE_LABELS[d.diff_type] }}
              </span>
            </td>
            <td class="py-2 pr-3 font-mono text-xs">{{ d.payment_no || '-' }}</td>
            <td class="py-2 pr-3 font-mono text-xs">{{ d.channel_trade_no || '-' }}</td>
            <td class="py-2 pr-3 text-xs">{{ d.local_status || '-' }}</td>
            <td class="py-2 pr-3 text-xs">{{ d.channel_status || '-' }}</td>
            <td class="py-2 pr-3">
              <span class="rounded px-2 py-0.5 text-xs" :class="RECONCILE_DIFF_STATUS_CLASS[d.status]">
                {{ RECONCILE_DIFF_STATUS_LABELS[d.status] }}
              </span>
            </td>
            <td class="py-2">
              <button class="text-[#1677ff]" @click="openDetail(d)">详情</button>
              <button
                v-if="d.status === 'pending' || d.status === 'processing'"
                :data-testid="`resolve-${d.id}`"
                class="ml-3 text-[#1677ff]"
                @click="resolveDiff(d, 'resolve')"
              >处置</button>
              <button
                v-if="d.status === 'pending' || d.status === 'processing'"
                class="ml-3 text-slate-500"
                @click="resolveDiff(d, 'ignore')"
              >忽略</button>
            </td>
          </tr>
          <tr v-if="!diffs.length">
            <td colspan="9" class="py-10 text-center text-slate-400">暂无退款对账差异</td>
          </tr>
        </tbody>
      </table>

      <TablePagination
        class="mt-4"
        :pagination="pagination"
        @change="goPage"
      />
    </template>

    <p v-if="tip" class="mt-3 text-sm text-red-500">{{ tip }}</p>
    <p v-if="resolveTip" class="mt-3 text-sm text-red-500">{{ resolveTip }}</p>

    <!-- 差异详情抽屉 -->
    <div
      v-if="detail"
      class="fixed inset-0 z-40 flex justify-end bg-black/30"
      @click.self="closeDetail"
    >
      <div class="w-[480px] overflow-y-auto bg-white p-5 shadow-xl">
        <div class="flex items-center justify-between">
          <h2 class="text-base font-semibold">差异详情</h2>
          <button class="text-slate-400" @click="closeDetail"><X /></button>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-y-2 text-sm">
          <dt class="text-slate-400">对账日期</dt>
          <dd class="text-xs">{{ detail.reconcile_date }}</dd>
          <dt class="text-slate-400">渠道</dt>
          <dd>{{ PAYMENT_CHANNEL_LABELS[detail.channel as PaymentChannel] ?? detail.channel }}</dd>
          <dt class="text-slate-400">差异类型</dt>
          <dd>
            <span class="rounded px-2 py-0.5 text-xs" :class="RECONCILE_DIFF_TYPE_CLASS[detail.diff_type]">
              {{ RECONCILE_DIFF_TYPE_LABELS[detail.diff_type] }}
            </span>
          </dd>
          <dt class="text-slate-400">退款单号</dt>
          <dd class="font-mono text-xs">{{ detail.payment_no || '-' }}</dd>
          <dt class="text-slate-400">渠道退单号</dt>
          <dd class="font-mono text-xs">{{ detail.channel_trade_no || '-' }}</dd>
          <dt class="text-slate-400">订单号</dt>
          <dd class="font-mono text-xs">{{ detail.order_no || '-' }}</dd>
          <dt class="text-slate-400">本地状态</dt>
          <dd class="text-xs">{{ detail.local_status || '-' }}</dd>
          <dt class="text-slate-400">渠道状态</dt>
          <dd class="text-xs">{{ detail.channel_status || '-' }}</dd>
          <dt class="text-slate-400">本地金额</dt>
          <dd class="text-xs">{{ detail.local_amount ?? '-' }}</dd>
          <dt class="text-slate-400">渠道金额</dt>
          <dd class="text-xs">{{ detail.channel_amount ?? '-' }}</dd>
          <dt class="text-slate-400">处置状态</dt>
          <dd>
            <span class="rounded px-2 py-0.5 text-xs" :class="RECONCILE_DIFF_STATUS_CLASS[detail.status]">
              {{ RECONCILE_DIFF_STATUS_LABELS[detail.status] }}
            </span>
          </dd>
          <dt class="text-slate-400">处置备注</dt>
          <dd class="text-xs">{{ detail.handle_remark || '-' }}</dd>
        </dl>
        <p v-if="detail.detail" class="mt-4 rounded bg-slate-50 p-2 text-xs text-slate-600">{{ detail.detail }}</p>
      </div>
    </div>
  </div>
</template>
