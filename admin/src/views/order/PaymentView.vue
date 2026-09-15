<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronLeft, ChevronRight, Download, Search } from 'lucide-vue-next'
import {
  closePayment,
  exportPayments,
  getPayment,
  getPayments,
  reviewPayment,
  PAYMENT_CHANNEL_LABELS,
  PAYMENT_STATUS_CLASS,
  PAYMENT_STATUS_LABELS,
  type PaymentChannel,
  type PaymentDetail,
  type PaymentRow,
  type PaymentStatus,
} from '@/api/payment'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 支付管理（API 文档 8.11）
 * 查看：payment.view（运营 + 超管）；关闭待支付单：payment.manage（仅超管）
 */
const loading = ref(true)
const list = ref<PaymentRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const summary = ref({ total: 0, success_count: 0, success_amount: '0.00', pending_count: 0, failed_count: 0 })

// 筛选条件
const paymentNo = ref('')
const orderNo = ref('')
const channelFilter = ref<'' | PaymentChannel>('')
const statusFilter = ref<'' | PaymentStatus>('')
const startDate = ref('')
const endDate = ref('')

// 详情 / 关闭
const detail = ref<PaymentDetail | null>(null)
const detailLoading = ref(false)
const closeTarget = ref<PaymentRow | null>(null)
const closeReason = ref('')
const closing = ref(false)
const error = ref('')

// 线下转账核账
const reviewTarget = ref<PaymentRow | null>(null)
const reviewPass = ref(true)
const reviewRemark = ref('')
const reviewing = ref(false)
const reviewError = ref('')

const statusTabs: Array<{ value: '' | PaymentStatus; label: string }> = [
  { value: '', label: '全部' },
  ...(Object.keys(PAYMENT_STATUS_LABELS) as PaymentStatus[]).map((s) => ({ value: s, label: PAYMENT_STATUS_LABELS[s] })),
]

const queryParams = computed(() => ({
  payment_no: paymentNo.value.trim() || undefined,
  order_no: orderNo.value.trim() || undefined,
  channel: channelFilter.value || undefined,
  status: statusFilter.value || undefined,
  start_time: startDate.value ? `${startDate.value} 00:00:00` : undefined,
  end_time: endDate.value ? `${endDate.value} 23:59:59` : undefined,
  page_size: pagination.value.page_size,
}))

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getPayments({ ...queryParams.value, page })
    list.value = data.data.list
    pagination.value = data.data.pagination
    summary.value = data.data.summary
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

function reset() {
  paymentNo.value = ''
  orderNo.value = ''
  channelFilter.value = ''
  statusFilter.value = ''
  startDate.value = ''
  endDate.value = ''
  load(1)
}

function filterStatus(status: '' | PaymentStatus) {
  statusFilter.value = status
  load(1)
}

function money(v: string | number): string {
  const n = typeof v === 'number' ? v : Number.parseFloat(v)
  if (Number.isNaN(n)) return String(v)
  return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function openDetail(row: PaymentRow) {
  detailLoading.value = true
  detail.value = null
  try {
    const { data } = await getPayment(row.id)
    detail.value = data.data
  } finally {
    detailLoading.value = false
  }
}

async function doClose() {
  if (!closeTarget.value) return
  closing.value = true
  error.value = ''
  try {
    await closePayment(closeTarget.value.id, closeReason.value.trim() || undefined)
    const closedId = closeTarget.value.id
    closeTarget.value = null
    closeReason.value = ''
    if (detail.value?.id === closedId) {
      const { data } = await getPayment(closedId)
      detail.value = data.data
    }
    await load(pagination.value.page)
  } catch (e) {
    error.value = e instanceof Error ? e.message : '关闭失败'
  } finally {
    closing.value = false
  }
}

async function doExport() {
  await exportPayments(queryParams.value, `支付单导出-${new Date().toISOString().slice(0, 10)}.csv`)
}

async function doReview() {
  if (!reviewTarget.value) return
  reviewing.value = true
  reviewError.value = ''
  const id = reviewTarget.value.id
  try {
    await reviewPayment(id, reviewPass.value, reviewPass.value ? undefined : reviewRemark.value.trim())
    reviewTarget.value = null
    reviewRemark.value = ''
    if (detail.value?.id === id) {
      const { data } = await getPayment(id)
      detail.value = data.data
    }
    await load(pagination.value.page)
  } catch (e) {
    reviewError.value = e instanceof Error ? e.message : '核账失败'
  } finally {
    reviewing.value = false
  }
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
      <h2 class="text-lg font-semibold text-slate-800">支付管理</h2>
      <Button variant="outline" @click="doExport"><Download class="mr-1 h-4 w-4" /> 导出 CSV</Button>
    </div>

    <!-- 汇总统计 -->
    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5" data-testid="payment-summary">
      <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
        <p class="text-xs text-slate-400">支付单总数</p>
        <p class="text-base font-semibold text-black" data-testid="summary-total">{{ summary.total }}</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
        <p class="text-xs text-slate-400">成功笔数</p>
        <p class="text-base font-semibold text-black" data-testid="summary-success-count">{{ summary.success_count }}</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
        <p class="text-xs text-slate-400">成功金额</p>
        <p class="text-base font-semibold text-black" data-testid="summary-success-amount">¥{{ money(summary.success_amount) }}</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
        <p class="text-xs text-slate-400">待支付</p>
        <p class="text-base font-semibold text-orange-500" data-testid="summary-pending">{{ summary.pending_count }}</p>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
        <p class="text-xs text-slate-400">失败笔数</p>
        <p class="text-base font-semibold text-red-500" data-testid="summary-failed">{{ summary.failed_count }}</p>
      </div>
    </div>

    <!-- 筛选区 -->
    <div class="mb-3 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="paymentNo" type="text" placeholder="支付单号"
        class="w-44 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="filter-payment-no" @keyup.enter="search"
      />
      <input
        v-model="orderNo" type="text" placeholder="订单号"
        class="w-44 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="filter-order-no" @keyup.enter="search"
      />
      <select v-model="channelFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="filter-channel">
        <option value="">全部渠道</option>
        <option v-for="(label, value) in PAYMENT_CHANNEL_LABELS" :key="value" :value="value">{{ label }}</option>
      </select>
      <select v-model="statusFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="filter-status">
        <option value="">全部状态</option>
        <option v-for="tab in statusTabs.slice(1)" :key="tab.value" :value="tab.value">{{ tab.label }}</option>
      </select>
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <input v-model="startDate" type="date" class="outline-none" data-testid="filter-start" />
        <span class="text-slate-300">–</span>
        <input v-model="endDate" type="date" class="outline-none" data-testid="filter-end" />
      </div>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" data-testid="filter-search" @click="search">
        <Search class="mr-1 h-4 w-4" /> 搜索
      </Button>
      <Button variant="outline" data-testid="filter-reset" @click="reset">重置</Button>
    </div>

    <!-- 状态快捷筛选 -->
    <div class="mb-4 flex flex-wrap gap-2">
      <button
        v-for="tab in statusTabs"
        :key="tab.value"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="statusFilter === tab.value ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
        :data-testid="`status-tab-${tab.value || 'all'}`"
        @click="filterStatus(tab.value)"
      >{{ tab.label }}</button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">支付单号</th>
          <th class="px-3 py-1.5">订单号</th>
          <th class="w-16 px-3 py-1.5">用户</th>
          <th class="w-24 px-3 py-1.5">渠道</th>
          <th class="w-24 px-3 py-1.5">金额</th>
          <th class="w-24 px-3 py-1.5">状态</th>
          <th class="px-3 py-1.5">渠道交易号</th>
          <th class="w-40 px-3 py-1.5">支付时间</th>
          <th class="w-28 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ row.payment_no }}</td>
          <td class="px-3 py-1.5 font-mono text-black">{{ row.order_no }}</td>
          <td class="px-3 py-1.5 text-black">#{{ row.user_id }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.channel_label }}</td>
          <td class="px-3 py-1.5 font-medium text-black">¥{{ money(row.amount) }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="PAYMENT_STATUS_CLASS[row.status]">{{ row.status_label }}</span>
          </td>
          <td class="max-w-48 truncate px-3 py-1.5 font-mono text-black" :title="row.channel_trade_no || ''">
            {{ row.channel_trade_no || '-' }}
          </td>
          <td class="px-3 py-1.5 text-black">{{ row.paid_at || '-' }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button class="hover:underline" :data-testid="`detail-${row.id}`" @click="openDetail(row)">详情</button>
              <template v-if="row.status === 'pending'">
                <span class="text-slate-200">|</span>
                <button
                  v-permission="'payment.manage'"
                  class="text-orange-500 hover:underline"
                  :data-testid="`close-${row.id}`"
                  @click="closeTarget = row; closeReason = ''"
                >关闭</button>
              </template>
              <template v-if="row.status === 'reviewing'">
                <span class="text-slate-200">|</span>
                <button
                  v-permission="'payment.offline.review'"
                  class="text-orange-500 hover:underline"
                  :data-testid="`review-${row.id}`"
                  @click="reviewTarget = row; reviewPass = true; reviewRemark = ''"
                >核账</button>
              </template>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="9"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="9" class="px-3 py-12 text-center text-slate-400" data-testid="empty-state">暂时无数据</td>
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

    <!-- 详情抽屉 -->
    <div v-if="detail || detailLoading" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" data-testid="payment-detail" @click.self="detail = null">
      <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-base font-semibold">支付单详情</h2>
          <button class="text-slate-400 hover:text-slate-600" @click="detail = null">✕</button>
        </div>

        <template v-if="detail">
          <div class="mb-4 grid grid-cols-2 gap-y-1 text-[13px]">
            <p><span class="text-slate-400">支付单号：</span><span class="font-mono text-black">{{ detail.payment_no }}</span></p>
            <p><span class="text-slate-400">订单号：</span><span class="font-mono text-black">{{ detail.order_no }}</span></p>
            <p><span class="text-slate-400">渠道：</span><span class="text-black">{{ detail.channel_label }}</span></p>
            <p><span class="text-slate-400">金额：</span><span class="font-medium text-black">¥{{ money(detail.amount) }}</span></p>
            <p>
              <span class="text-slate-400">状态：</span>
              <span class="rounded px-1.5 py-0.5 text-xs" :class="PAYMENT_STATUS_CLASS[detail.status]">{{ detail.status_label }}</span>
            </p>
            <p><span class="text-slate-400">渠道交易号：</span><span class="font-mono text-black">{{ detail.channel_trade_no || '-' }}</span></p>
            <p><span class="text-slate-400">创建时间：</span><span class="text-black">{{ detail.created_at }}</span></p>
            <p><span class="text-slate-400">支付时间：</span><span class="text-black">{{ detail.paid_at || '-' }}</span></p>
          </div>

          <div v-if="detail.order" class="mb-4 rounded-lg bg-slate-50 p-3 text-[13px]" data-testid="detail-order">
            <p><span class="text-slate-400">关联订单：</span><span class="font-mono text-black">{{ detail.order.order_no }}</span></p>
            <p class="mt-1">
              <span class="text-slate-400">订单状态：</span><span class="text-black">{{ detail.order.status_label }}</span>
              <span class="ml-3 text-slate-400">实付：</span><span class="text-black">¥{{ money(detail.order.pay_amount) }}</span>
            </p>
          </div>

          <h3 class="mb-2 text-[13px] font-semibold text-slate-700">支付日志（{{ detail.logs.length }} 条）</h3>
          <div v-if="detail.logs.length" class="space-y-2" data-testid="detail-logs">
            <div v-for="log in detail.logs" :key="log.id" class="rounded-lg border border-slate-100 p-2.5 text-[12px]">
              <div class="flex items-center justify-between">
                <span class="font-medium text-black">{{ log.event_label }}</span>
                <span class="text-slate-400">{{ log.created_at }}</span>
              </div>
              <p v-if="log.request_preview" class="mt-1 break-all font-mono text-slate-500">请求：{{ log.request_preview }}</p>
              <p v-if="log.response_preview" class="mt-0.5 break-all font-mono text-slate-500">响应：{{ log.response_preview }}</p>
            </div>
          </div>
          <p v-else class="px-3 py-6 text-center text-slate-400">暂无支付日志</p>

          <div v-if="detail.status === 'reviewing'" class="mt-4 flex justify-end">
            <Button class="bg-orange-500 px-4 hover:bg-orange-600" v-permission="'payment.offline.review'" @click="reviewTarget = detail; reviewPass = true; reviewRemark = ''">去核账</Button>
          </div>
        </template>
        <LoadingSpinner v-else />
      </div>
    </div>

    <!-- 关闭确认（含原因输入） -->
    <div
      v-if="closeTarget"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6"
      data-testid="close-dialog"
      @click.self="closeTarget = null"
    >
      <div class="w-full max-w-md rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">确认关闭该支付单？</h3>
        <p class="mt-2 text-[13px] leading-5 text-slate-500">
          关闭后支付单 <span class="font-mono text-slate-700">{{ closeTarget.payment_no }}</span>（金额
          ¥{{ money(closeTarget.amount) }}）将置为「已关闭」，买家需重新发起支付。
          订单本身不会被取消，此操作不可撤销。
        </p>
        <input
          v-model="closeReason"
          placeholder="关闭原因（可选，将记入操作日志）"
          class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
          data-testid="close-reason"
        />
        <p v-if="error" class="mt-2 text-xs text-red-500" data-testid="close-error">{{ error }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button
            class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50"
            @click="closeTarget = null"
          >取消</button>
          <button
            class="rounded-md bg-[#ff4d4f] px-4 py-1.5 text-[13px] text-white hover:bg-[#ff7875] disabled:opacity-50"
            :disabled="closing"
            data-testid="close-submit"
            @click="doClose"
          >{{ closing ? '关闭中…' : '确认关闭' }}</button>
        </div>
      </div>
    </div>

    <!-- 线下转账核账确认 -->
    <div
      v-if="reviewTarget"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6"
      data-testid="review-dialog"
      @click.self="reviewTarget = null"
    >
      <div class="w-full max-w-md rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">线下转账核账</h3>
        <p class="mt-2 text-[13px] text-slate-500">
          支付单 <span class="font-mono text-slate-700">{{ reviewTarget.payment_no }}</span>
          {{ reviewPass ? '通过后将驱动订单支付成功（或充值入账）。' : '驳回后支付单置为失败，用户可重新提交或换渠道。' }}
        </p>
        <div class="mt-3 flex gap-4">
          <label class="flex items-center gap-1 text-[13px]"><input v-model="reviewPass" type="radio" :value="true" class="h-4 w-4" />通过</label>
          <label class="flex items-center gap-1 text-[13px]"><input v-model="reviewPass" type="radio" :value="false" class="h-4 w-4" />驳回</label>
        </div>
        <input
          v-if="!reviewPass"
          v-model="reviewRemark"
          placeholder="驳回原因（必填）"
          class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
          data-testid="review-remark"
        />
        <p v-if="reviewError" class="mt-2 text-xs text-red-500" data-testid="review-error">{{ reviewError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="reviewTarget = null">取消</button>
          <button
            class="rounded-md px-4 py-1.5 text-[13px] text-white hover:opacity-90 disabled:opacity-50"
            :class="reviewPass ? 'bg-green-600' : 'bg-red-500'"
            :disabled="reviewing || (!reviewPass && !reviewRemark.trim())"
            data-testid="review-submit"
            @click="doReview"
          >{{ reviewing ? '处理中…' : (reviewPass ? '确认通过' : '确认驳回') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
