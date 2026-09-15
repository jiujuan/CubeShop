<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ChevronLeft, ChevronRight, Search } from 'lucide-vue-next'
import {
  getPaymentLog,
  getPaymentLogs,
  PAYMENT_EVENT_LABELS,
  type PaymentLogRow,
} from '@/api/payment'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 支付日志（API 文档 8.12，权限 payment.view，只读）
 * 用于排查渠道回调与异步通知问题；列表展示摘要，详情展示完整 JSON。
 */
const loading = ref(true)
const list = ref<PaymentLogRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })

const paymentNo = ref('')
const eventFilter = ref('')
const startDate = ref('')
const endDate = ref('')

// 详情
const detail = ref<PaymentLogRow | null>(null)
const detailLoading = ref(false)

const eventOptions = Object.entries(PAYMENT_EVENT_LABELS).map(([value, label]) => ({ value, label }))

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getPaymentLogs({
      payment_no: paymentNo.value.trim() || undefined,
      event: eventFilter.value || undefined,
      start_time: startDate.value ? `${startDate.value} 00:00:00` : undefined,
      end_time: endDate.value ? `${endDate.value} 23:59:59` : undefined,
      page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

function reset() {
  paymentNo.value = ''
  eventFilter.value = ''
  startDate.value = ''
  endDate.value = ''
  load(1)
}

async function openDetail(row: PaymentLogRow) {
  detailLoading.value = true
  detail.value = null
  try {
    const { data } = await getPaymentLog(row.id)
    detail.value = data.data
  } finally {
    detailLoading.value = false
  }
}

function pretty(v: unknown): string {
  if (v === null || v === undefined) return '—'
  return JSON.stringify(v, null, 2)
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">支付日志</h2>
        <p class="mt-0.5 text-xs text-slate-400">记录支付单创建、渠道回调与后台关闭的原始数据，只读</p>
      </div>
      <span class="text-xs text-slate-400">共 {{ pagination.total }} 条记录</span>
    </div>

    <!-- 筛选区 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="paymentNo" type="text" placeholder="支付单号"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="filter-payment-no" @keyup.enter="search"
      />
      <select v-model="eventFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="filter-event">
        <option value="">全部事件</option>
        <option v-for="opt in eventOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <input v-model="startDate" type="date" class="outline-none" data-testid="filter-start" />
        <span class="text-slate-300">–</span>
        <input v-model="endDate" type="date" class="outline-none" data-testid="filter-end" />
      </div>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" data-testid="filter-search" @click="search">
        <Search class="mr-1 h-4 w-4" /> 搜索
      </Button>
      <Button variant="outline" @click="reset">重置</Button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-16 px-3 py-1.5">ID</th>
          <th class="px-3 py-1.5">支付单号</th>
          <th class="w-28 px-3 py-1.5">事件</th>
          <th class="px-3 py-1.5">请求摘要</th>
          <th class="px-3 py-1.5">响应摘要</th>
          <th class="w-40 px-3 py-1.5">时间</th>
          <th class="w-16 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ row.id }}</td>
          <td class="px-3 py-1.5 font-mono text-black">{{ row.payment_no || '-' }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.event_label }}</td>
          <td class="max-w-64 truncate px-3 py-1.5 font-mono text-black" :title="row.request_preview || ''">
            {{ row.request_preview || '-' }}
          </td>
          <td class="max-w-64 truncate px-3 py-1.5 font-mono text-black" :title="row.response_preview || ''">
            {{ row.response_preview || '-' }}
          </td>
          <td class="px-3 py-1.5 text-black">{{ row.created_at || '-' }}</td>
          <td class="px-3 py-1.5">
            <button class="text-[#1677ff] hover:underline" :data-testid="`detail-${row.id}`" @click="openDetail(row)">详情</button>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="7"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="7" class="px-3 py-12 text-center text-slate-400" data-testid="empty-state">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <div class="mt-4 flex items-center justify-between text-[13px] text-slate-500">
      <span>共 {{ pagination.total }} 条记录 / 每页 {{ pagination.page_size }} 条</span>
      <div class="flex items-center gap-1">
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)"
        ><ChevronLeft class="h-4 w-4" /></button>
        <button
          v-for="page in pagination.total_pages" :key="page"
          class="h-7 min-w-7 rounded border px-1.5"
          :class="page === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
          @click="goPage(page)"
        >{{ page }}</button>
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)"
        ><ChevronRight class="h-4 w-4" /></button>
      </div>
    </div>

    <!-- 详情弹窗（完整 JSON） -->
    <div
      v-if="detail || detailLoading"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6"
      data-testid="log-detail"
      @click.self="detail = null"
    >
      <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-base font-semibold">支付日志详情</h2>
          <button class="text-slate-400 hover:text-slate-600" @click="detail = null">✕</button>
        </div>

        <template v-if="detail">
          <div class="mb-3 grid grid-cols-2 gap-y-1 text-[13px]">
            <p><span class="text-slate-400">日志 ID：</span><span class="font-mono text-black">{{ detail.id }}</span></p>
            <p><span class="text-slate-400">事件：</span><span class="text-black">{{ detail.event_label }}</span></p>
            <p><span class="text-slate-400">支付单号：</span><span class="font-mono text-black">{{ detail.payment_no || '-' }}</span></p>
            <p><span class="text-slate-400">时间：</span><span class="text-black">{{ detail.created_at || '-' }}</span></p>
          </div>

          <h3 class="mb-1 text-[13px] font-semibold text-slate-700">请求数据</h3>
          <pre class="mb-3 max-h-60 overflow-auto rounded-lg bg-slate-50 p-3 text-[12px] leading-5 text-black" data-testid="detail-request">{{ pretty(detail.request_data) }}</pre>

          <h3 class="mb-1 text-[13px] font-semibold text-slate-700">响应数据</h3>
          <pre class="max-h-60 overflow-auto rounded-lg bg-slate-50 p-3 text-[12px] leading-5 text-black" data-testid="detail-response">{{ pretty(detail.response_data) }}</pre>
        </template>
        <LoadingSpinner v-else />
      </div>
    </div>
  </div>
</template>
