<script setup lang="ts">
import { onMounted, ref } from 'vue'

import {
  getRefunds,
  processRefund,
  receiveRefund,
  REFUND_STATUS_CLASS,
  REFUND_STATUS_LABELS,
  REFUND_TYPE_LABELS,
  RETURN_STATUS_LABELS,
  type Refund,
  type RefundStatus,
  type ReturnCondition,
  type ReturnReceivedDetail,
} from '@/api/refund'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 退款处理页（权限 refund.view / refund.process，Roadmap P5）
 */
const refunds = ref<Refund[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')
const statusFilter = ref<'' | RefundStatus>('')

/** 审核确认弹层：{ id, action, label } */
const confirmState = ref<{ id: number; action: 'approve' | 'reject'; label: string } | null>(null)
const processing = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await getRefunds({
      status: statusFilter.value || undefined,
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

function filterStatus(status: '' | RefundStatus) {
  statusFilter.value = status
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

function askProcess(refund: Refund, action: 'approve' | 'reject') {
  confirmState.value = {
    id: refund.id,
    action,
    label: action === 'approve'
      ? `同意退款 ¥${refund.amount}（订单 ${refund.order_no}），退款成功后订单将变为「已退款」`
      : `拒绝退款 ¥${refund.amount}（订单 ${refund.order_no}），订单将回到「已支付」`,
  }
}

async function doProcess() {
  if (!confirmState.value) return
  processing.value = true
  tip.value = ''
  try {
    await processRefund(confirmState.value.id, confirmState.value.action, confirmState.value.action === 'reject' ? '不符合退款条件' : '沙箱退款成功')
    confirmState.value = null
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '处理失败'
  } finally {
    processing.value = false
  }
}

/** 确认收货弹层状态（退货退款专用） */
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
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <!-- 标题 -->
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">退款处理</h2>
    </div>

    <p v-if="tip" class="mb-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

    <!-- 状态快捷筛选（胶囊标签排） -->
    <div class="mb-4 flex flex-wrap gap-2">
      <button
        v-for="tab in [['', '全部'], ['pending', '待审核'], ['success', '退款成功'], ['rejected', '已拒绝']] as const"
        :key="tab[0]"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="statusFilter === tab[0] ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
        @click="filterStatus(tab[0] as RefundStatus | '')"
      >{{ tab[1] }}</button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">退款单号</th>
          <th class="px-3 py-1.5">类型</th>
          <th class="px-3 py-1.5">订单号</th>
          <th class="w-24 px-3 py-1.5">金额</th>
          <th class="px-3 py-1.5">原因</th>
          <th class="w-24 px-3 py-1.5">状态</th>
          <th class="w-40 px-3 py-1.5">申请时间</th>
          <th class="w-40 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="refund in refunds" :key="refund.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ refund.refund_no }}</td>
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
          <td class="px-3 py-1.5 text-black">{{ refund.created_at }}</td>
          <td class="px-3 py-1.5">
            <div v-if="refund.status === 'pending'" class="flex items-center gap-1">
              <button class="text-emerald-600 hover:underline" @click="askProcess(refund, 'approve')">同意</button>
              <span class="text-slate-200">|</span>
              <button class="text-red-500 hover:underline" @click="askProcess(refund, 'reject')">拒绝</button>
            </div>
            <button
              v-else-if="canReceive(refund)"
              class="rounded bg-purple-500 px-2 py-0.5 text-xs text-white hover:bg-purple-600"
              @click="askReceive(refund)"
            >确认收货</button>
            <span v-else-if="refund.admin_remark" class="text-xs text-slate-400">{{ refund.admin_remark }}</span>
            <span v-else class="text-slate-300">-</span>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="8"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!refunds.length && !loading">
          <td colspan="8" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <TablePagination :pagination="pagination" @change="goPage" />

    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.action === 'approve' ? '同意退款' : '拒绝退款'"
      :message="confirmState?.label"
      :danger="confirmState?.action === 'reject'"
      :confirm-text="processing ? '处理中…' : '确认'"
      @confirm="doProcess"
      @cancel="confirmState = null"
    />

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
            <tr v-for="(row, idx) in receiveState.rows" :key="row.sku_id" class="border-b border-slate-100">
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
  </div>
</template>
