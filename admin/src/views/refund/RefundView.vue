<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ExternalLink, ImagePlus, X } from 'lucide-vue-next'

import {
  getRefundDetail,
  getRefunds,
  processRefund,
  receiveRefund,
  getRefundLogs,
  retryRefund,
  batchProcessRefunds,
  exportRefunds,
  REFUND_MAX_RETRY,
  REFUND_CHANNEL_LABELS,
  REFUND_ACTION_LABELS,
  REFUND_STATUS_CLASS,
  REFUND_STATUS_LABELS,
  REFUND_TYPE_LABELS,
  RETURN_STATUS_LABELS,
  type Refund,
  type RefundDetail,
  type RefundStatus,
  type RefundType,
  type ReturnCondition,
  type ReturnReceivedDetail,
  type RefundLogEntry,
} from '@/api/refund'
import { formatRefundLogFields } from '@/lib/refundLog'
import { uploadImage } from '@/api/product'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 退款处理页（权限 refund.view / refund.process，Roadmap P5）
 *
 * 操作列：详情（全部） + 同意/拒绝（待审核，附理由与说明图） + 确认收货（退货退款）
 */
const refunds = ref<Refund[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')
const statusFilter = ref<'' | RefundStatus>('')
const refundNo = ref('')
const orderNo = ref('')
/** 概览页队列跳转携带的附加筛选（账龄/重试耗尽/类型/退货状态） */
const queueFilter = ref<{ aged_hours?: number; retry_exhausted?: 1; type?: RefundType; return_status?: string }>({})

const MAX_IMAGES = 9

async function load() {
  selectedIds.value = []
  loading.value = true
  try {
    const res = await getRefunds({
      refund_no: refundNo.value.trim() || undefined,
      order_no: orderNo.value.trim() || undefined,
      status: statusFilter.value || undefined,
      ...queueFilter.value,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    refunds.value = res.data.data.list
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  // 支持「退款概览」异常队列跳转：/refunds?status=..&aged_hours=..&retry_exhausted=1&type=..&return_status=..
  const q = useRoute().query
  if (typeof q.status === 'string' && q.status) statusFilter.value = q.status as RefundStatus
  if (typeof q.type === 'string' && q.type) queueFilter.value.type = q.type as RefundType
  if (typeof q.return_status === 'string' && q.return_status) queueFilter.value.return_status = q.return_status
  if (typeof q.aged_hours === 'string' && q.aged_hours) queueFilter.value.aged_hours = Number(q.aged_hours)
  if (q.retry_exhausted === '1') queueFilter.value.retry_exhausted = 1
  load()
})

function filterStatus(status: '' | RefundStatus) {
  statusFilter.value = status
  queueFilter.value = {}
  pagination.value.page = 1
  load()
}

function search() {
  pagination.value.page = 1
  load()
}

function reset() {
  refundNo.value = ''
  orderNo.value = ''
  queueFilter.value = {}
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

// ---------- 详情弹层 ----------

const detailState = ref<{ loading: boolean; data: RefundDetail | null; tip: string } | null>(null)

async function openDetail(refund: Refund) {
  detailState.value = { loading: true, data: null, tip: '' }
  try {
    const { data } = await getRefundDetail(refund.id)
    if (detailState.value) detailState.value.data = data.data
  } catch (e) {
    if (detailState.value) detailState.value.tip = e instanceof Error ? e.message : '加载详情失败'
  } finally {
    if (detailState.value) detailState.value.loading = false
  }
}

function closeDetail() {
  detailState.value = null
}

// ---------- 图片灯箱（图层内放大，不跳转） ----------

const lightboxUrl = ref<string>('')

function openLightbox(url: string) {
  lightboxUrl.value = url
}

function closeLightbox() {
  lightboxUrl.value = ''
}

function onEsc(e: KeyboardEvent) {
  if (e.key === 'Escape') closeLightbox()
}

onMounted(() => window.addEventListener('keydown', onEsc))
onUnmounted(() => window.removeEventListener('keydown', onEsc))

/** 处理流水：把每条 content 转成中文键值行（含图片），供模板渲染 */
const detailLogs = computed(() =>
  (detailState.value?.data?.logs ?? []).map((log) => ({
    log,
    fields: formatRefundLogFields(log.content, log.content_data),
    // 操作人：优先昵称 / 用户名；取不到时回退角色名（用户 / 管理员）
    operator: log.operator?.nickname || log.operator?.username || (log.actor_type === 'customer' ? '用户' : '管理员'),
  })),
)

// ---------- 审核弹层（同意/拒绝 + 理由 + 图片） ----------

const processState = ref<{
  refund: Refund
  action: 'approve' | 'reject'
  remark: string
  images: string[]
  uploading: boolean
  loading: boolean
  tip: string
} | null>(null)

function askProcess(refund: Refund, action: 'approve' | 'reject') {
  processState.value = { refund, action, remark: '', images: [], uploading: false, loading: false, tip: '' }
}

function closeProcess() {
  if (processState.value?.loading) return
  processState.value = null
}

async function onProcessPickFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !processState.value) return
  if (processState.value.images.length >= MAX_IMAGES) {
    processState.value.tip = `最多上传 ${MAX_IMAGES} 张图片`
    return
  }
  processState.value.uploading = true
  processState.value.tip = ''
  try {
    const { data } = await uploadImage(file)
    processState.value.images.push(data.data.url)
  } catch (err) {
    processState.value.tip = err instanceof Error ? err.message : '图片上传失败'
  } finally {
    if (processState.value) processState.value.uploading = false
  }
}

function removeProcessImage(i: number) {
  processState.value?.images.splice(i, 1)
}

async function doProcess() {
  const state = processState.value
  if (!state) return
  state.loading = true
  state.tip = ''
  try {
    await processRefund(state.refund.id, state.action, {
      admin_remark: state.remark.trim() || undefined,
      admin_images: state.images.length ? state.images : undefined,
    })
    processState.value = null
    await load()
  } catch (e) {
    state.tip = e instanceof Error ? e.message : '处理失败'
  } finally {
    state.loading = false
  }
}

// ---------- 确认收货弹层（退货退款专用） ----------

const receiveState = ref<{
  refund: Refund | null
  rows: { sku_id: number; expected: number; received: number; condition: ReturnCondition }[]
  exception: string
  loading: boolean
  tip: string
} | null>(null)

/** 是否可确认收货：退货退款 + 已审核通过 + 等待/退货中 */
function canReceive(refund: Refund): boolean {
  return refund.type === 'return_refund'
    && refund.status === 'approved'
    && (refund.return_status === 'waiting_return' || refund.return_status === 'shipping')
}

function askReceive(refund: Refund) {
  receiveState.value = {
    refund,
    rows: (refund.return_details ?? []).map((d) => ({
      sku_id: d.sku_id,
      expected: d.quantity,
      received: d.quantity,
      condition: 'good' as ReturnCondition,
    })),
    exception: '',
    loading: false,
    tip: '',
  }
}

function closeReceive() {
  receiveState.value = null
}

async function doReceive() {
  if (!receiveState.value || !receiveState.value.refund) return
  const state = receiveState.value
  if (state.rows.some((r) => r.received < 0 || r.received > r.expected)) {
    state.tip = '实收数量需在 0 ~ 应退数量之间'
    return
  }
  state.loading = true
  state.tip = ''
  const receivedDetails: ReturnReceivedDetail[] = state.rows
    .filter((r) => r.received > 0)
    .map((r) => ({ sku_id: r.sku_id, quantity: r.received, condition: r.condition }))
  try {
    await receiveRefund(state.refund!.id, {
      received_details: receivedDetails,
      exception_reason: state.exception || undefined,
    })
    closeReceive()
    await load()
  } catch (e) {
    state.tip = e instanceof Error ? e.message : '确认收货失败'
  } finally {
    state.loading = false
  }
}

// ---------- 重试（失败态，复用 out_refund_no 幂等） ----------

const retryingId = ref<number | null>(null)

function canRetry(refund: Refund): boolean {
  return refund.status === 'failed' && refund.retry_count < (refund.max_retry ?? REFUND_MAX_RETRY)
}

async function doRetry(refund: Refund) {
  if (retryingId.value) return
  retryingId.value = refund.id
  tip.value = ''
  try {
    await retryRefund(refund.id)
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '重试失败'
  } finally {
    retryingId.value = null
  }
}

// ---------- 退款全链路日志抽屉 ----------

const logState = ref<{ loading: boolean; data: RefundLogEntry[]; tip: string } | null>(null)

async function openLogs(refund: Refund) {
  logState.value = { loading: true, data: [], tip: '' }
  try {
    const { data } = await getRefundLogs(refund.id)
    if (logState.value) logState.value.data = data.data.logs
  } catch (e) {
    if (logState.value) logState.value.tip = e instanceof Error ? e.message : '加载日志失败'
  } finally {
    if (logState.value) logState.value.loading = false
  }
}

function closeLogs() {
  logState.value = null
}

// ---------- 批量审核 + 导出（#5） ----------

const selectedIds = ref<number[]>([])
const batchBusy = ref(false)
const exporting = ref(false)
const batchTip = ref('')

const allSelected = computed(
  () => refunds.value.length > 0 && selectedIds.value.length === refunds.value.length,
)

function toggleSelectAll() {
  selectedIds.value = allSelected.value ? [] : refunds.value.map((r) => r.id)
}

function toggleSelect(id: number) {
  selectedIds.value = selectedIds.value.includes(id)
    ? selectedIds.value.filter((v) => v !== id)
    : [...selectedIds.value, id]
}

async function doBatch(action: 'approve' | 'reject') {
  if (!selectedIds.value.length || batchBusy.value) return
  batchBusy.value = true
  batchTip.value = ''
  tip.value = ''
  try {
    const { data: res } = await batchProcessRefunds(selectedIds.value, action)
    const r = res.data
    if (r.failed_count) {
      const failedText = r.failed
        .slice(0, 3)
        .map((f) => `${f.refund_no || '#' + f.id}（${f.reason}）`)
        .join('、')
      batchTip.value = ''
      tip.value = `批量审核完成：成功 ${r.succeeded_count} 单，失败 ${r.failed_count} 单：${failedText}${r.failed_count > 3 ? ' 等' : ''}`
    } else {
      batchTip.value = `批量审核完成：成功 ${r.succeeded_count} 单`
    }
    selectedIds.value = []
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '批量审核失败，请稍后重试'
  } finally {
    batchBusy.value = false
  }
}

async function doExport() {
  if (exporting.value) return
  exporting.value = true
  tip.value = ''
  try {
    await exportRefunds({
      refund_no: refundNo.value.trim() || undefined,
      order_no: orderNo.value.trim() || undefined,
      status: statusFilter.value || undefined,
    })
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '导出失败，请稍后重试'
  } finally {
    exporting.value = false
  }
}

</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <!-- 标题 -->
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">退款处理</h2>
      <button
        class="rounded bg-[#1677ff] px-3 py-1.5 text-sm text-white hover:bg-[#4096ff] disabled:opacity-50"
        data-testid="export-refunds"
        :disabled="exporting"
        @click="doExport"
      >{{ exporting ? '导出中…' : '导出 CSV' }}</button>
    </div>

    <p v-if="tip" class="mb-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

    <!-- 状态快捷筛选（胶囊标签排） -->
    <div class="mb-4 flex flex-wrap gap-2">
      <button
        v-for="tab in [['', '全部'], ['pending', '待审核'], ['processing', '退款中'], ['success', '退款成功'], ['failed', '退款失败'], ['rejected', '已拒绝']] as const"
        :key="tab[0]"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="statusFilter === tab[0] ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
        @click="filterStatus(tab[0] as RefundStatus | '')"
      >{{ tab[1] }}</button>
    </div>

    <!-- 搜索 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="refundNo" type="text" placeholder="退款单号"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="filter-refund-no" @keyup.enter="search"
      />
      <input
        v-model="orderNo" type="text" placeholder="订单号"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        data-testid="filter-order-no" @keyup.enter="search"
      />
      <button
        class="rounded bg-[#1677ff] px-5 py-1.5 text-sm text-white hover:bg-[#4096ff]"
        data-testid="filter-search"
        @click="search"
      >搜索</button>
      <button
        class="rounded border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]"
        @click="reset"
      >重置</button>
    </div>

    <!-- 批量审核工具条（#5） -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <template v-if="selectedIds.length">
        <span class="text-xs text-slate-500" data-testid="selected-count">已选 {{ selectedIds.length }} 单</span>
        <button
          class="rounded bg-emerald-500 px-3 py-1 text-xs text-white hover:bg-emerald-600 disabled:opacity-50"
          data-testid="batch-approve"
          :disabled="batchBusy"
          @click="doBatch('approve')"
        >批量同意</button>
        <button
          class="rounded bg-red-500 px-3 py-1 text-xs text-white hover:bg-red-600 disabled:opacity-50"
          data-testid="batch-reject"
          :disabled="batchBusy"
          @click="doBatch('reject')"
        >批量拒绝</button>
      </template>
    </div>
    <p v-if="batchTip" class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-600" data-testid="batch-tip">{{ batchTip }}</p>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-10 px-3 py-1.5">
            <input type="checkbox" data-testid="select-all" :checked="allSelected" @change="toggleSelectAll" />
          </th>
          <th class="px-3 py-1.5">退款单号</th>
          <th class="px-3 py-1.5">类型</th>
          <th class="px-3 py-1.5">订单号</th>
          <th class="w-24 px-3 py-1.5">金额</th>
          <th class="px-3 py-1.5">原因</th>
          <th class="w-24 px-3 py-1.5">状态</th>
          <th class="px-3 py-1.5">渠道退款</th>
          <th class="w-28 px-3 py-1.5">渠道状态</th>
          <th class="w-40 px-3 py-1.5">申请时间</th>
          <th class="w-52 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="refund in refunds" :key="refund.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5">
            <input type="checkbox" :data-testid="`select-${refund.id}`" :checked="selectedIds.includes(refund.id)" @change="toggleSelect(refund.id)" />
          </td>
          <td class="px-3 py-1.5 font-mono text-black">
            {{ refund.refund_no }}
            <span v-if="refund.images?.length" class="ml-1 rounded bg-amber-100 px-1 text-[10px] text-amber-600">图{{ refund.images.length }}</span>
          </td>
          <td class="px-3 py-1.5">
            <span class="rounded px-1.5 py-0.5 text-xs" :class="refund.type === 'return_refund' ? 'bg-purple-100 text-purple-600' : 'bg-slate-100 text-slate-500'">{{ REFUND_TYPE_LABELS[refund.type] }}</span>
          </td>
          <td class="px-3 py-1.5 font-mono text-black">{{ refund.order_no }}</td>
          <td class="px-3 py-1.5 font-medium text-[#ff4d4f]">¥{{ refund.amount }}</td>
          <td class="max-w-40 truncate px-3 py-1.5 text-black" :title="refund.reason || ''">{{ refund.reason || '-' }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="REFUND_STATUS_CLASS[refund.status]">{{ REFUND_STATUS_LABELS[refund.status] }}</span>
            <span v-if="refund.type === 'return_refund' && refund.return_status" class="ml-1 text-xs text-purple-500">· 退货{{ RETURN_STATUS_LABELS[refund.return_status] }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">
            <p v-if="refund.channel">{{ REFUND_CHANNEL_LABELS[refund.channel] || refund.channel }}</p>
            <p class="font-mono text-[11px] text-slate-500">{{ refund.out_refund_no || '-' }}</p>
            <p v-if="refund.channel_refund_no" class="font-mono text-[11px] text-slate-400">渠道：{{ refund.channel_refund_no }}</p>
          </td>
          <td class="px-3 py-1.5 text-black">
            <span class="rounded px-2 py-0.5 text-xs" :class="refund.refund_status === 'SUCCESS' ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'">{{ refund.refund_status || '-' }}</span>
            <p v-if="refund.failed_reason" class="mt-1 text-[11px] text-orange-500">{{ refund.failed_reason }}</p>
          </td>
          <td class="px-3 py-1.5 text-black">{{ refund.created_at }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-2">
              <button class="text-[#1677ff] hover:underline" :data-testid="`detail-${refund.id}`" @click="openDetail(refund)">详情</button>
              <template v-if="refund.status === 'pending'">
                <span class="text-slate-200">|</span>
                <button class="text-emerald-600 hover:underline" :data-testid="`approve-${refund.id}`" @click="askProcess(refund, 'approve')">同意</button>
                <span class="text-slate-200">|</span>
                <button class="text-red-500 hover:underline" :data-testid="`reject-${refund.id}`" @click="askProcess(refund, 'reject')">拒绝</button>
              </template>
              <template v-else-if="canReceive(refund)">
                <span class="text-slate-200">|</span>
                <button
                  class="rounded bg-purple-500 px-2 py-0.5 text-xs text-white hover:bg-purple-600"
                  :data-testid="`receive-${refund.id}`"
                  @click="askReceive(refund)"
                >确认收货</button>
              </template>
              <span class="text-slate-200">|</span>
              <button class="text-slate-500 hover:underline" :data-testid="`logs-${refund.id}`" @click="openLogs(refund)">日志</button>
              <template v-if="canRetry(refund)">
                <span class="text-slate-200">|</span>
                <button
                  class="rounded bg-orange-500 px-2 py-0.5 text-xs text-white hover:bg-orange-600 disabled:opacity-50"
                  :data-testid="`retry-${refund.id}`"
                  :disabled="retryingId === refund.id"
                  @click="doRetry(refund)"
                >{{ retryingId === refund.id ? '重试中…' : '重试' }}</button>
              </template>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="10"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!refunds.length && !loading">
          <td colspan="10" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 详情弹层 -->
    <div
      v-if="detailState"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-8"
      @click.self="closeDetail"
    >
      <div class="w-full max-w-3xl rounded-lg bg-white p-5 shadow-lg" data-testid="refund-detail">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-base font-semibold text-slate-800">退款详情</h3>
          <div class="flex items-center gap-2">
            <button
              class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-500 hover:text-[#1677ff]"
              :data-testid="`detail-logs-${detailState.data?.id}`"
              @click="openLogs(detailState.data!)"
            >退款日志</button>
            <button class="text-slate-400 hover:text-slate-600" @click="closeDetail"><X class="h-4 w-4" /></button>
          </div>
        </div>

        <p v-if="detailState.tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ detailState.tip }}</p>
        <div v-if="detailState.loading" class="py-10"><LoadingSpinner /></div>

        <template v-else-if="detailState.data">
          <div class="grid gap-4 sm:grid-cols-2">
            <!-- 基本信息 -->
            <section class="rounded-lg border border-slate-100 p-3 text-[13px]">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">退款信息</h4>
              <p class="text-black">单号：<span class="font-mono">{{ detailState.data.refund_no }}</span></p>
              <p class="mt-1 text-black">类型：{{ REFUND_TYPE_LABELS[detailState.data.type] }}</p>
              <p class="mt-1 text-black">金额：<span class="font-medium text-[#ff4d4f]">¥{{ detailState.data.amount }}</span></p>
              <p class="mt-1 text-black">
                状态：{{ REFUND_STATUS_LABELS[detailState.data.status] }}
                <span v-if="detailState.data.type === 'return_refund' && detailState.data.return_status" class="text-purple-500">· 退货{{ RETURN_STATUS_LABELS[detailState.data.return_status] }}</span>
              </p>
              <p class="mt-1 text-black">申请时间：{{ detailState.data.created_at }}</p>
              <p class="mt-1 text-black">退款理由：{{ detailState.data.reason || '-' }}</p>
            </section>

            <!-- 渠道退款信息 -->
            <section class="rounded-lg border border-slate-100 p-3 text-[13px]" data-testid="detail-channel">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">渠道退款</h4>
              <p class="text-black">渠道：{{ detailState.data.channel ? (REFUND_CHANNEL_LABELS[detailState.data.channel] || detailState.data.channel) : '-' }}</p>
              <p class="mt-1 text-black">我方退款单号：<span class="font-mono">{{ detailState.data.out_refund_no || '-' }}</span></p>
              <p class="mt-1 text-black">渠道退款单号：<span class="font-mono">{{ detailState.data.channel_refund_no || '-' }}</span></p>
              <p class="mt-1 text-black">渠道状态：{{ detailState.data.refund_status || '-' }}</p>
              <p class="mt-1 text-black">退款成功时间：{{ detailState.data.refunded_at || '-' }}</p>
              <p v-if="detailState.data.failed_reason" class="mt-1 text-orange-500">失败原因：{{ detailState.data.failed_reason }}</p>
              <p class="mt-1 text-black">重试次数：{{ detailState.data.retry_count }} / {{ detailState.data.max_retry ?? REFUND_MAX_RETRY }}</p>
            </section>

            <!-- 用户 / 订单 -->
            <section class="rounded-lg border border-slate-100 p-3 text-[13px]">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">用户与订单</h4>
              <p v-if="detailState.data.user" class="text-black">
                用户：{{ detailState.data.user.nickname || detailState.data.user.username || `#${detailState.data.user.id}` }}
                <template v-if="detailState.data.user.phone">（{{ detailState.data.user.phone }}）</template>
              </p>
              <p v-else class="text-slate-400">用户信息不可用</p>
              <p v-if="detailState.data.order" class="mt-1 text-black">订单号：<span class="font-mono">{{ detailState.data.order.order_no }}</span></p>
              <p v-if="detailState.data.order" class="mt-1 text-black">订单状态：{{ detailState.data.order.status }}｜实付：¥{{ detailState.data.order.pay_amount }}</p>
              <p v-if="detailState.data.order" class="mt-1 text-black">下单时间：{{ detailState.data.order.created_at }}</p>
            </section>
          </div>

          <!-- 用户凭证图片 -->
          <section v-if="detailState.data.images?.length" class="mt-4 rounded-lg border border-slate-100 p-3" data-testid="detail-user-images">
            <h4 class="mb-2 text-xs font-semibold text-slate-500">用户凭证图片（{{ detailState.data.images.length }}）</h4>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="(url, i) in detailState.data.images"
                :key="`u-${i}`"
                type="button"
                class="block h-20 w-20 overflow-hidden rounded border border-slate-200 transition-shadow hover:shadow-md"
                data-testid="user-image"
                @click="openLightbox(url)"
              >
                <img :src="url" class="h-full w-full object-cover" alt="凭证图" />
              </button>
            </div>
          </section>

          <!-- 商品明细（产品图 / 产品链接） -->
          <section class="mt-4 rounded-lg border border-slate-100 p-3" data-testid="detail-items">
            <h4 class="mb-2 text-xs font-semibold text-slate-500">订单商品</h4>
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                  <th class="px-2 py-1.5">商品</th>
                  <th class="w-40 px-2 py-1.5">规格</th>
                  <th class="w-16 px-2 py-1.5">数量</th>
                  <th class="w-24 px-2 py-1.5">小计</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(it, i) in detailState.data.items" :key="i" class="border-b border-slate-100 last:border-0">
                  <td class="px-2 py-2">
                    <div class="flex items-center gap-2">
                      <div class="h-10 w-10 shrink-0 overflow-hidden rounded border border-slate-100 bg-slate-50">
                        <img v-if="it.sku_image" :src="it.sku_image" class="h-full w-full object-cover" alt="" />
                      </div>
                      <div class="min-w-0">
                        <router-link
                          v-if="it.product_id"
                          :to="`/products/${it.product_id}`"
                          class="flex items-center gap-1 text-[#1677ff] hover:underline"
                        >
                          <span class="truncate">{{ it.product_title }}</span>
                          <ExternalLink class="h-3 w-3 shrink-0" />
                        </router-link>
                        <span v-else class="truncate text-black">{{ it.product_title }}</span>
                      </div>
                    </div>
                  </td>
                  <td class="px-2 py-2 text-slate-500">{{ Object.values(it.sku_specs).join(' / ') || '默认规格' }}</td>
                  <td class="px-2 py-2 text-black">{{ it.quantity }}</td>
                  <td class="px-2 py-2 text-black">¥{{ it.total_amount }}</td>
                </tr>
                <tr v-if="!detailState.data.items.length">
                  <td colspan="4" class="px-2 py-6 text-center text-slate-400">无商品明细</td>
                </tr>
              </tbody>
            </table>
          </section>

          <!-- 退货信息 -->
          <section v-if="detailState.data.type === 'return_refund'" class="mt-4 rounded-lg border border-purple-100 bg-purple-50/40 p-3 text-[13px]">
            <h4 class="mb-2 text-xs font-semibold text-purple-500">退货信息</h4>
            <p class="text-black">退货状态：{{ detailState.data.return_status ? RETURN_STATUS_LABELS[detailState.data.return_status] : '待开始' }}</p>
            <p class="mt-1 text-black">寄回物流：{{ detailState.data.return_express_company || '-' }} {{ detailState.data.return_tracking_no || '' }}</p>
            <p v-if="detailState.data.return_exception_reason" class="mt-1 text-orange-500">差异说明：{{ detailState.data.return_exception_reason }}</p>
            <p v-if="detailState.data.return_received_at" class="mt-1 text-black">收货时间：{{ detailState.data.return_received_at }}</p>
          </section>

          <!-- 后台处理 -->
          <section class="mt-4 rounded-lg border border-slate-100 p-3 text-[13px]" data-testid="detail-admin">
            <h4 class="mb-2 text-xs font-semibold text-slate-500">后台处理</h4>
            <p class="text-black">处理人：{{ detailState.data.processed_by_name || '-' }}｜处理时间：{{ detailState.data.processed_at || '-' }}</p>
            <p class="mt-1 text-black">处理理由：{{ detailState.data.admin_remark || '-' }}</p>
            <div v-if="detailState.data.admin_images?.length" class="mt-2 flex flex-wrap gap-2" data-testid="detail-admin-images">
              <button
                v-for="(url, i) in detailState.data.admin_images"
                :key="`a-${i}`"
                type="button"
                class="block h-20 w-20 overflow-hidden rounded border border-slate-200 transition-shadow hover:shadow-md"
                data-testid="admin-image"
                @click="openLightbox(url)"
              >
                <img :src="url" class="h-full w-full object-cover" alt="处理说明图" />
              </button>
            </div>
          </section>

          <!-- 处理流水 -->
          <section class="mt-4 rounded-lg border border-slate-100 p-3" data-testid="detail-logs">
            <h4 class="mb-2 text-xs font-semibold text-slate-500">处理记录</h4>
            <ol class="space-y-3 text-[13px]">
              <li v-for="entry in detailLogs" :key="entry.log.id" class="flex items-start gap-2" data-testid="log-entry">
                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[#1677ff]"></span>
                <div class="min-w-0 flex-1">
                  <p class="text-black">
                    {{ REFUND_ACTION_LABELS[entry.log.action] || entry.log.action }}
                    <span class="text-slate-400">
                      · {{ entry.operator }}
                      · {{ entry.log.created_at }}
                    </span>
                  </p>

                  <!-- 结构化字段：中文键值 + 图片缩略图 -->
                  <dl
                    v-if="entry.fields.length"
                    class="mt-1.5 space-y-1 rounded-md bg-slate-50 px-2.5 py-2 text-xs"
                    data-testid="log-fields"
                  >
                    <div v-for="f in entry.fields" :key="f.key" class="flex gap-2">
                      <dt v-if="f.label" class="w-24 shrink-0 text-slate-400">{{ f.label }}</dt>
                      <dd class="min-w-0 flex-1 text-slate-600">
                        <!-- 图片 -->
                        <div v-if="f.kind === 'images'" class="flex flex-wrap gap-1.5">
                          <button
                            v-for="(url, i) in f.images"
                            :key="i"
                            type="button"
                            class="h-14 w-14 overflow-hidden rounded border border-slate-200 transition-shadow hover:shadow-md"
                            data-testid="log-image"
                            @click="openLightbox(url)"
                          >
                            <img :src="url" class="h-full w-full object-cover" alt="" />
                          </button>
                        </div>
                        <!-- 多行明细 -->
                        <ul v-else-if="f.kind === 'lines'" class="list-inside list-disc space-y-0.5">
                          <li v-for="(line, i) in f.lines" :key="i">{{ line }}</li>
                        </ul>
                        <!-- 金额 -->
                        <span v-else-if="f.kind === 'money'" class="font-medium text-[#ff4d4f]">{{ f.text }}</span>
                        <!-- 兜底 JSON -->
                        <pre v-else-if="f.kind === 'json'" class="whitespace-pre-wrap break-all font-mono text-[11px] text-slate-500">{{ f.raw }}</pre>
                        <!-- 文本 -->
                        <span v-else>{{ f.text }}</span>
                      </dd>
                    </div>
                  </dl>
                </div>
              </li>
              <li v-if="!detailState.data.logs.length" class="text-slate-400">暂无处理记录</li>
            </ol>
          </section>
        </template>
      </div>
    </div>

    <!-- 审核弹层（同意/拒绝 + 理由 + 图片） -->
    <div
      v-if="processState"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
      @click.self="closeProcess"
    >
      <div class="w-full max-w-lg rounded-lg bg-white p-5 shadow-lg" data-testid="process-dialog">
        <h3 class="mb-1 text-base font-semibold text-slate-800">
          {{ processState.action === 'approve' ? '同意退款' : '拒绝退款' }}
        </h3>
        <p class="mb-3 text-xs text-slate-500">
          退款单 {{ processState.refund.refund_no }}（订单 {{ processState.refund.order_no }}），退款金额 ¥{{ processState.refund.amount }}
          <span v-if="processState.action === 'approve'">，同意后订单将变为「已退款」</span>
          <span v-else>，拒绝后订单将回到「已支付」</span>
        </p>

        <p v-if="processState.tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ processState.tip }}</p>

        <div class="mb-3">
          <label class="mb-1 block text-xs text-slate-500">
            {{ processState.action === 'approve' ? '同意理由（可选）' : '拒绝理由' }}
          </label>
          <textarea
            v-model="processState.remark"
            rows="3"
            maxlength="255"
            :placeholder="processState.action === 'approve' ? '如：核对无误，同意退款' : '如：商品已使用，不符合退款条件'"
            data-testid="process-remark"
            class="w-full resize-none rounded border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
          ></textarea>
        </div>

        <div class="mb-4">
          <label class="mb-1 block text-xs text-slate-500">说明图片（可选，最多 {{ MAX_IMAGES }} 张）</label>
          <div class="flex flex-wrap items-center gap-2">
            <div
              v-for="(url, i) in processState.images"
              :key="`p-${i}`"
              class="relative block h-16 w-16 overflow-hidden rounded border border-slate-200"
            >
              <button type="button" class="block h-full w-full" data-testid="process-thumb" @click="openLightbox(url)">
                <img :src="url" class="h-full w-full object-cover" alt="" />
              </button>
              <button
                class="absolute right-0 top-0 rounded-bl bg-black/50 px-1 text-[10px] text-white"
                type="button"
                @click="removeProcessImage(i)"
              >×</button>
            </div>
            <label
              class="flex h-16 w-16 cursor-pointer flex-col items-center justify-center gap-1 rounded border border-dashed border-slate-300 text-[11px] text-slate-400 hover:border-[#1677ff] hover:text-[#1677ff]"
            >
              <ImagePlus class="h-4 w-4" />
              {{ processState.uploading ? '上传中' : '上传' }}
              <input type="file" accept="image/*" class="hidden" :disabled="processState.uploading" data-testid="process-image-input" @change="onProcessPickFile" />
            </label>
          </div>
        </div>

        <div class="flex justify-end gap-2">
          <button
            class="rounded border border-slate-200 px-4 py-1.5 text-sm text-slate-500 hover:text-[#1677ff]"
            :disabled="processState.loading"
            @click="closeProcess"
          >取消</button>
          <button
            class="rounded px-4 py-1.5 text-sm text-white disabled:opacity-50"
            :class="processState.action === 'approve' ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-red-500 hover:bg-red-600'"
            :disabled="processState.loading || processState.uploading"
            data-testid="process-submit"
            @click="doProcess"
          >{{ processState.loading ? '处理中…' : (processState.action === 'approve' ? '确认同意' : '确认拒绝') }}</button>
        </div>
      </div>
    </div>

    <!-- 确认收货弹层（退货退款专用） -->
    <div
      v-if="receiveState"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
      @click.self="!receiveState.loading && closeReceive()"
    >
      <div class="w-full max-w-lg rounded-lg bg-white p-5 shadow-lg">
        <h3 class="mb-3 text-base font-semibold text-slate-800">
          确认收货（退货单 {{ receiveState.refund?.refund_no }}）
        </h3>
        <p class="mb-3 rounded-md bg-purple-50 px-3 py-2 text-xs text-purple-600">
          请按实际收货填写每项的「实收数量」与「状态」。正品将回加可售库存，残次不回加（记差异待处理）。确认后退款将完成、订单变为已退款。
        </p>

        <p v-if="receiveState.tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ receiveState.tip }}</p>

        <table class="mb-3 w-full text-[13px]">
          <thead>
            <tr class="border-b border-slate-200 text-left text-slate-500">
              <th class="px-2 py-1.5">商品</th>
              <th class="w-20 px-2 py-1.5">应退</th>
              <th class="w-24 px-2 py-1.5">实收</th>
              <th class="w-28 px-2 py-1.5">状态</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in receiveState.rows" :key="row.sku_id" class="border-b border-slate-100">
              <td class="px-2 py-1.5 text-black">SKU#{{ row.sku_id }}</td>
              <td class="px-2 py-1.5 text-black">{{ row.expected }}</td>
              <td class="px-2 py-1.5">
                <input
                  v-model.number="row.received"
                  type="number"
                  min="0"
                  :max="row.expected"
                  class="w-16 rounded border border-slate-300 px-2 py-1 text-sm outline-none focus:border-[#1677ff]"
                />
              </td>
              <td class="px-2 py-1.5">
                <select
                  v-model="row.condition"
                  class="rounded border border-slate-300 px-2 py-1 text-sm outline-none focus:border-[#1677ff]"
                >
                  <option value="good">正品</option>
                  <option value="defective">残次</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="mb-4">
          <label class="mb-1 block text-xs text-slate-500">异常/差异说明（可选）</label>
          <textarea
            v-model="receiveState.exception"
            rows="2"
            maxlength="500"
            placeholder="如少件、残次、实收与应退不符等"
            class="w-full resize-none rounded border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
          ></textarea>
        </div>

        <div class="flex justify-end gap-2">
          <button
            class="rounded border border-slate-200 px-4 py-1.5 text-sm text-slate-500 hover:text-[#1677ff]"
            :disabled="receiveState.loading"
            @click="closeReceive"
          >取消</button>
          <button
            class="rounded bg-purple-500 px-4 py-1.5 text-sm text-white hover:bg-purple-600 disabled:opacity-50"
            :disabled="receiveState.loading"
            @click="doReceive"
          >{{ receiveState.loading ? '处理中…' : '确认收货' }}</button>
        </div>
      </div>
    </div>

    <!-- 退款日志抽屉 -->
    <div
      v-if="logState"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-8"
      @click.self="closeLogs"
    >
      <div class="w-full max-w-2xl rounded-lg bg-white p-5 shadow-lg" data-testid="refund-logs">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-base font-semibold text-slate-800">退款全链路日志</h3>
          <button class="text-slate-400 hover:text-slate-600" @click="closeLogs"><X class="h-4 w-4" /></button>
        </div>
        <p v-if="logState.tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ logState.tip }}</p>
        <div v-if="logState.loading" class="py-10"><LoadingSpinner /></div>
        <template v-else>
          <ol class="space-y-3 text-[13px]">
            <li v-for="entry in logState.data" :key="entry.id" class="rounded-md border border-slate-100 p-3" data-testid="refund-log-entry">
              <div class="flex items-center justify-between">
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ entry.type }}</span>
                <span class="text-xs text-slate-400">{{ entry.created_at }}</span>
              </div>
              <p v-if="entry.channel" class="mt-1 text-black">渠道：{{ REFUND_CHANNEL_LABELS[entry.channel] || entry.channel }}</p>
              <p v-if="entry.out_refund_no" class="mt-1 text-black">退款单号：<span class="font-mono">{{ entry.out_refund_no }}</span></p>
              <p v-if="entry.channel_status" class="mt-1 text-black">渠道状态：{{ entry.channel_status }}</p>
              <p v-if="entry.note" class="mt-1 text-black">备注：{{ entry.note }}</p>
              <p v-if="entry.actor_type" class="mt-1 text-slate-400">操作方：{{ entry.actor_type }}{{ entry.actor_id ? ' #' + entry.actor_id : '' }}</p>
            </li>
            <li v-if="!logState.data.length" class="text-slate-400">暂无退款日志</li>
          </ol>
        </template>
      </div>
    </div>

    <!-- 图片灯箱：图层内放大查看，不跳转 -->
    <div
      v-if="lightboxUrl"
      class="fixed inset-0 z-[60] flex items-center justify-center bg-black/75 p-6"
      data-testid="image-lightbox"
      @click.self="closeLightbox"
    >
      <button
        type="button"
        class="absolute right-5 top-5 rounded-full bg-white/15 p-2 text-white transition-colors hover:bg-white/30"
        data-testid="lightbox-close"
        @click="closeLightbox"
      >
        <X class="h-5 w-5" />
      </button>
      <img :src="lightboxUrl" class="max-h-[85vh] max-w-[85vw] rounded-lg object-contain shadow-2xl" alt="图片预览" />
    </div>
  </div>
</template>
