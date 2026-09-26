<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { AlertTriangle, Clock3, RefreshCw } from 'lucide-vue-next'

import {
  getRefundStats,
  REFUND_STATUS_CLASS,
  REFUND_STATUS_LABELS,
  type RefundStats,
  type RefundStatus,
} from '@/api/refund'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 退款概览 / 异常队列页（权限 refund.view，只读聚合 GET /admin/refunds/stats）
 *
 * 指标卡：各状态计数 + 金额汇总（点击进入退款处理页对应状态队列）；
 * 账龄分桶：未完结单 <24h / 24-72h / >72h；
 * 异常队列：processing 超 24h（疑似回调丢失）、failed 重试耗尽（待人工）、
 * return_refund 待退货超 7 天未发货 —— 点击带筛选条件跳转退款处理页。
 */
const router = useRouter()

const loading = ref(true)
const tip = ref('')
const stats = ref<RefundStats | null>(null)

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const res = await getRefundStats()
    stats.value = res.data.data
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '加载概览失败'
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** 状态卡顺序（用 ref 键做 data-testid） */
const statusOrder: RefundStatus[] = ['pending', 'processing', 'approved', 'failed', 'success', 'rejected']

/** 队列卡点击 → 退款处理页带筛选 */
function goQueue(query: Record<string, string>) {
  router.push({ path: '/refunds', query })
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">退款概览</h2>
        <p class="mt-0.5 text-xs text-slate-400">各状态退款单计数与金额、未完结账龄、异常队列；点击卡片进入退款处理页对应队列</p>
      </div>
      <button
        class="rounded border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]"
        data-testid="stats-refresh"
        @click="load"
      >
        <RefreshCw class="mr-1 inline h-3.5 w-3.5" /> 刷新
      </button>
    </div>

    <LoadingSpinner v-if="loading" class="mt-8" />
    <p v-else-if="tip" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-600">{{ tip }}</p>

    <template v-else-if="stats">
      <!-- 状态指标卡 -->
      <h3 class="mt-5 text-sm font-semibold text-slate-700">按状态</h3>
      <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <button
          v-for="st in statusOrder"
          :key="st"
          class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-left transition-colors hover:border-[#1677ff]"
          :data-testid="`stats-status-${st}`"
          @click="goQueue({ status: st })"
        >
          <p class="text-xs text-slate-500">{{ REFUND_STATUS_LABELS[st] }}</p>
          <p class="mt-1 text-2xl font-semibold text-slate-800">{{ stats.status_counts[st] }}</p>
          <p class="mt-0.5 text-xs text-slate-400">¥{{ stats.status_amounts[st] }}</p>
          <span class="mt-2 inline-block rounded px-1.5 py-0.5 text-[10px]" :class="REFUND_STATUS_CLASS[st]">{{ REFUND_STATUS_LABELS[st] }}</span>
        </button>
      </div>

      <!-- 账龄分桶（未完结单，只读展示） -->
      <h3 class="mt-6 text-sm font-semibold text-slate-700">未完结账龄</h3>
      <div class="mt-2 grid grid-cols-3 gap-3">
        <div class="rounded-lg border border-slate-100 bg-slate-50 p-3" data-testid="stats-aging-lt-24h">
          <p class="flex items-center gap-1 text-xs text-slate-500"><Clock3 class="h-3.5 w-3.5" /> 24 小时内</p>
          <p class="mt-1 text-2xl font-semibold text-slate-800">{{ stats.aging.lt_24h }}</p>
        </div>
        <div class="rounded-lg border border-slate-100 bg-amber-50 p-3" data-testid="stats-aging-h24-72">
          <p class="flex items-center gap-1 text-xs text-amber-600"><Clock3 class="h-3.5 w-3.5" /> 24 ~ 72 小时</p>
          <p class="mt-1 text-2xl font-semibold text-amber-700">{{ stats.aging.h24_72 }}</p>
        </div>
        <div class="rounded-lg border border-slate-100 bg-red-50 p-3" data-testid="stats-aging-gt-72h">
          <p class="flex items-center gap-1 text-xs text-red-500"><Clock3 class="h-3.5 w-3.5" /> 超 72 小时</p>
          <p class="mt-1 text-2xl font-semibold text-red-600">{{ stats.aging.gt_72h }}</p>
        </div>
      </div>

      <!-- 异常队列（点击进入对应筛选列表） -->
      <h3 class="mt-6 text-sm font-semibold text-slate-700">异常队列</h3>
      <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <button
          class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-left transition-colors hover:border-[#1677ff]"
          :class="stats.queues.processing_stuck > 0 ? 'ring-1 ring-amber-200' : ''"
          data-testid="stats-queue-processing_stuck"
          @click="goQueue({ status: 'processing', aged_hours: '24' })"
        >
          <p class="flex items-center gap-1 text-xs text-slate-500"><AlertTriangle class="h-3.5 w-3.5 text-amber-500" /> 退款中超 24 小时</p>
          <p class="mt-1 text-2xl font-semibold text-slate-800">{{ stats.queues.processing_stuck }}</p>
          <p class="mt-0.5 text-xs text-slate-400">疑似渠道回调丢失，点击核查</p>
        </button>
        <button
          class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-left transition-colors hover:border-[#1677ff]"
          :class="stats.queues.failed_maxed > 0 ? 'ring-1 ring-red-200' : ''"
          data-testid="stats-queue-failed_maxed"
          @click="goQueue({ status: 'failed', retry_exhausted: '1' })"
        >
          <p class="flex items-center gap-1 text-xs text-slate-500"><AlertTriangle class="h-3.5 w-3.5 text-red-500" /> 重试耗尽待人工</p>
          <p class="mt-1 text-2xl font-semibold text-slate-800">{{ stats.queues.failed_maxed }}</p>
          <p class="mt-0.5 text-xs text-slate-400">failed 且达最大重试次数，点击处理</p>
        </button>
        <button
          class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-left transition-colors hover:border-[#1677ff]"
          :class="stats.queues.return_waiting_overdue > 0 ? 'ring-1 ring-purple-200' : ''"
          data-testid="stats-queue-return_waiting_overdue"
          @click="goQueue({ type: 'return_refund', return_status: 'waiting_return', aged_hours: '168' })"
        >
          <p class="flex items-center gap-1 text-xs text-slate-500"><AlertTriangle class="h-3.5 w-3.5 text-purple-500" /> 待退货超 7 天</p>
          <p class="mt-1 text-2xl font-semibold text-slate-800">{{ stats.queues.return_waiting_overdue }}</p>
          <p class="mt-0.5 text-xs text-slate-400">买家未寄回/物流未更新，点击跟进</p>
        </button>
      </div>
    </template>
  </div>
</template>
