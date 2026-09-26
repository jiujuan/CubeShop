<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { X } from 'lucide-vue-next'

import {
  getRefunds,
  receiveRefund,
  getRefundLogs,
  REFUND_CHANNEL_LABELS,
  REFUND_TYPE_LABELS,
  RETURN_STATUS_LABELS,
  RETURN_CONDITION_LABELS,
  type Refund,
  type ReturnCondition,
  type ReturnReceivedDetail,
  type RefundLogEntry,
} from '@/api/refund'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 退货处理页（权限 refund.view / refund.process）
 *
 * 聚焦 return_refund 类型：运营在此确认买家寄回的退货、做良品/残次质检、标记差异。
 * 与「退款处理」页互补——那里覆盖全部退款单的审核/重试，这里覆盖退货收货这一步骤。
 * 后端 receive 接口已做幂等、库存回加与差异记录；本页只负责呈现与提交。
 * 布局与「支付日志」页一致：标题/Tab/表格/分页整体收在一张白色卡片内。
 */
type ReturnTab = '' | 'waiting_return' | 'shipping' | 'received' | 'exception'

const refunds = ref<Refund[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')
const tab = ref<ReturnTab>('')

async function load() {
  loading.value = true
  try {
    const res = await getRefunds({
      type: 'return_refund',
      return_status: tab.value || undefined,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    refunds.value = res.data.data.list
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(load)

function filterTab(t: ReturnTab) {
  tab.value = t
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

// ---------- 详情抽屉（只读信息 + 收货确认） ----------

const detail = ref<Refund | null>(null)
const detailTip = ref('')

function openDetail(refund: Refund) {
  detail.value = refund
  detailTip.value = ''
}
function closeDetail() {
  if (receiveState.value?.loading) return
  detail.value = null
}

// ---------- 确认收货（核心步骤） ----------

const receiveState = ref<{
  refund: Refund | null
  rows: { sku_id: number; expected: number; received: number; condition: ReturnCondition }[]
  exception: string
  loading: boolean
  tip: string
} | null>(null)

/** 可收货：退货退款 + 已审核通过 + 等待/退货中（尚未收货） */
function canReceive(refund: Refund): boolean {
  return (
    refund.type === 'return_refund' &&
    refund.status === 'approved' &&
    (refund.return_status === 'waiting_return' || refund.return_status === 'shipping')
  )
}

function askReceive(refund: Refund) {
  detail.value = refund
  detailTip.value = ''
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
  if (receiveState.value?.loading) return
  receiveState.value = null
}

async function doReceive() {
  const state = receiveState.value
  if (!state || !state.refund) return
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
    await receiveRefund(state.refund.id, {
      received_details: receivedDetails,
      exception_reason: state.exception || undefined,
    })
    closeReceive()
    if (detail.value?.id === state.refund.id) detail.value = null
    await load()
  } catch (e) {
    state.tip = e instanceof Error ? e.message : '确认收货失败'
  } finally {
    state.loading = false
  }
}

// ---------- 全链路日志抽屉 ----------

const logsState = ref<{ refund: Refund | null; loading: boolean; logs: RefundLogEntry[]; tip: string } | null>(null)

async function openLogs(refund: Refund) {
  logsState.value = { refund, loading: true, logs: [], tip: '' }
  try {
    const { data } = await getRefundLogs(refund.id)
    if (logsState.value) logsState.value.logs = data.data.logs
  } catch (e) {
    if (logsState.value) logsState.value.tip = e instanceof Error ? e.message : '加载日志失败'
  } finally {
    if (logsState.value) logsState.value.loading = false
  }
}
function closeLogs() {
  logsState.value = null
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">退货处理</h2>
        <p class="mt-0.5 text-xs text-slate-400">仅退款类型：退货退款（确认收货 / 质检）</p>
      </div>
      <span class="text-xs text-slate-400">共 {{ pagination.total }} 条记录</span>
    </div>

    <!-- 退货状态 Tab -->
    <div class="mt-4 flex flex-wrap gap-2">
      <button
        class="rounded-md px-3 py-1.5 text-sm"
        :class="tab === '' ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600'"
        @click="filterTab('')"
      >
        全部
      </button>
      <button
        v-for="t in (['waiting_return', 'shipping', 'received', 'exception'] as const)"
        :key="t"
        class="rounded-md px-3 py-1.5 text-sm"
        :class="tab === t ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600'"
        @click="filterTab(t)"
      >
        {{ RETURN_STATUS_LABELS[t] }}
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-10">
      <LoadingSpinner />
    </div>
    <template v-else>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b text-left text-slate-500">
            <th class="py-2 pr-3">退款单号</th>
            <th class="py-2 pr-3">订单号</th>
            <th class="py-2 pr-3">渠道</th>
            <th class="py-2 pr-3">退货物流</th>
            <th class="py-2 pr-3">退货状态</th>
            <th class="py-2 pr-3">异常原因</th>
            <th class="py-2 pr-3">创建时间</th>
            <th class="py-2">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="r in refunds"
            :key="r.id"
            :data-testid="`return-row-${r.id}`"
            class="border-b last:border-0"
          >
            <td class="py-2 pr-3 font-mono text-xs">{{ r.refund_no }}</td>
            <td class="py-2 pr-3 font-mono text-xs">{{ r.order_no }}</td>
            <td class="py-2 pr-3">{{ REFUND_CHANNEL_LABELS[r.channel ?? ''] ?? r.channel ?? '-' }}</td>
            <td class="py-2 pr-3 text-xs">
              <span v-if="r.return_express_company || r.return_tracking_no">
                {{ r.return_express_company || '未知' }} / {{ r.return_tracking_no || '未填' }}
              </span>
              <span v-else class="text-slate-300">-</span>
            </td>
            <td class="py-2 pr-3">
              <span
                v-if="r.return_status"
                class="rounded px-2 py-0.5 text-xs"
                :class="{
                  'bg-orange-100 text-orange-500': r.return_status === 'waiting_return',
                  'bg-blue-100 text-blue-500': r.return_status === 'shipping',
                  'bg-green-100 text-green-600': r.return_status === 'received',
                  'bg-red-100 text-red-500': r.return_status === 'exception',
                }"
              >{{ RETURN_STATUS_LABELS[r.return_status] }}</span>
            </td>
            <td class="py-2 pr-3 text-xs text-red-500">{{ r.return_exception_reason || '-' }}</td>
            <td class="py-2 pr-3 text-xs text-slate-400">{{ r.created_at }}</td>
            <td class="py-2">
              <button class="text-[#1677ff]" @click="openDetail(r)">详情</button>
              <button
                v-if="canReceive(r)"
                :data-testid="`receive-${r.id}`"
                class="ml-3 text-[#1677ff]"
                @click="askReceive(r)"
              >确认收货</button>
              <button class="ml-3 text-slate-500" @click="openLogs(r)">日志</button>
            </td>
          </tr>
          <tr v-if="!refunds.length">
            <td colspan="8" class="py-10 text-center text-slate-400">暂无退货退款单</td>
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

    <!-- 详情 / 收货抽屉 -->
    <div
      v-if="detail"
      class="fixed inset-0 z-40 flex justify-end bg-black/30"
      @click.self="closeDetail"
    >
      <div class="w-[520px] overflow-y-auto bg-white p-5 shadow-xl">
        <div class="flex items-center justify-between">
          <h2 class="text-base font-semibold">退货详情</h2>
          <button class="text-slate-400" @click="closeDetail"><X /></button>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-y-2 text-sm">
          <dt class="text-slate-400">退款单号</dt>
          <dd class="font-mono text-xs">{{ detail.refund_no }}</dd>
          <dt class="text-slate-400">订单号</dt>
          <dd class="font-mono text-xs">{{ detail.order_no }}</dd>
          <dt class="text-slate-400">类型</dt>
          <dd>{{ REFUND_TYPE_LABELS[detail.type] }}</dd>
          <dt class="text-slate-400">渠道</dt>
          <dd>{{ REFUND_CHANNEL_LABELS[detail.channel ?? ''] ?? detail.channel ?? '-' }}</dd>
          <dt class="text-slate-400">退货物流</dt>
          <dd class="text-xs">{{ detail.return_express_company || '未知' }} / {{ detail.return_tracking_no || '未填' }}</dd>
          <dt class="text-slate-400">退货状态</dt>
          <dd>
            <span v-if="detail.return_status" class="rounded px-2 py-0.5 text-xs bg-blue-100 text-blue-500">
              {{ RETURN_STATUS_LABELS[detail.return_status] }}
            </span>
          </dd>
          <dt class="text-slate-400">异常原因</dt>
          <dd class="text-xs text-red-500">{{ detail.return_exception_reason || '-' }}</dd>
        </dl>

        <!-- 应退明细 -->
        <h3 class="mt-5 text-sm font-semibold text-slate-700">应退明细</h3>
        <table class="mt-2 w-full text-sm">
          <thead>
            <tr class="border-b text-left text-slate-500">
              <th class="py-1 pr-3">SKU</th>
              <th class="py-1 pr-3">应退数量</th>
              <th class="py-1">实收</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in (detail.return_details ?? [])" :key="d.sku_id" class="border-b last:border-0">
              <td class="py-1 pr-3 font-mono text-xs">{{ d.sku_id }}</td>
              <td class="py-1 pr-3">{{ d.quantity }}</td>
              <td class="py-1 text-xs text-slate-400">
                {{ (detail.return_received_details ?? []).find((x) => x.sku_id === d.sku_id)?.quantity ?? '-' }}
                <span
                  v-if="(detail.return_received_details ?? []).find((x) => x.sku_id === d.sku_id)?.condition === 'defective'"
                  class="ml-1 text-red-500"
                >残次</span>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- 确认收货表单 -->
        <div v-if="canReceive(detail)" class="mt-5 rounded-md border border-slate-200 p-3">
          <p class="text-sm font-semibold text-slate-700">确认收货 / 质检</p>
          <div v-if="receiveState && receiveState.refund?.id === detail.id" class="mt-3 space-y-3">
            <div v-for="row in receiveState.rows" :key="row.sku_id" class="flex items-center gap-3 text-sm">
              <span class="w-24 font-mono text-xs">SKU {{ row.sku_id }}</span>
              <span class="w-16 text-slate-400">应退 {{ row.expected }}</span>
              <input
                v-model.number="row.received"
                type="number"
                min="0"
                :max="row.expected"
                class="w-20 rounded border border-slate-300 px-2 py-1"
              />
              <select v-model="row.condition" class="rounded border border-slate-300 px-2 py-1">
                <option v-for="(label, c) in RETURN_CONDITION_LABELS" :key="c" :value="c">{{ label }}</option>
              </select>
            </div>
            <textarea
              v-model="receiveState.exception"
              rows="2"
              placeholder="异常原因（实收与应退不一致时建议填写）"
              class="w-full rounded border border-slate-300 px-2 py-1 text-sm"
            ></textarea>
            <p v-if="receiveState.tip" class="text-sm text-red-500">{{ receiveState.tip }}</p>
            <button
              :data-testid="`receive-submit-${detail.id}`"
              class="rounded bg-[#1677ff] px-4 py-1.5 text-sm text-white disabled:opacity-50"
              :disabled="receiveState.loading"
              @click="doReceive"
            >{{ receiveState.loading ? '提交中…' : '确认收货并退款' }}</button>
          </div>
          <button v-else class="mt-3 rounded border border-[#1677ff] px-4 py-1.5 text-sm text-[#1677ff]" @click="askReceive(detail)">
            开始收货登记
          </button>
        </div>
      </div>
    </div>

    <!-- 日志抽屉 -->
    <div
      v-if="logsState"
      class="fixed inset-0 z-40 flex justify-end bg-black/30"
      @click.self="closeLogs"
    >
      <div class="w-[480px] overflow-y-auto bg-white p-5 shadow-xl">
        <div class="flex items-center justify-between">
          <h2 class="text-base font-semibold">退款全链路日志</h2>
          <button class="text-slate-400" @click="closeLogs"><X /></button>
        </div>
        <div v-if="logsState.loading" class="py-10 text-center"><LoadingSpinner /></div>
        <ul v-else class="mt-4 space-y-2 text-xs">
          <li v-for="log in logsState.logs" :key="log.id" class="rounded bg-slate-50 p-2">
            <div class="flex justify-between text-slate-500">
              <span>{{ log.type }}</span>
              <span>{{ log.created_at }}</span>
            </div>
            <div v-if="log.channel_status" class="mt-1">渠道状态：{{ log.channel_status }}</div>
            <div v-if="log.note" class="mt-1 text-slate-600">{{ log.note }}</div>
          </li>
          <li v-if="!logsState.logs.length" class="py-6 text-center text-slate-400">暂无日志</li>
        </ul>
        <p v-if="logsState.tip" class="mt-3 text-sm text-red-500">{{ logsState.tip }}</p>
      </div>
    </div>
  </div>
</template>
