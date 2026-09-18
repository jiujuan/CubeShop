<script setup lang="ts">
import { onMounted, ref } from 'vue'

import { getRefunds, processRefund, REFUND_STATUS_CLASS, REFUND_STATUS_LABELS, type Refund, type RefundStatus } from '@/api/refund'
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
          <td class="px-3 py-1.5 font-mono text-black">{{ refund.order_no }}</td>
          <td class="px-3 py-1.5 font-medium text-[#ff4d4f]">¥{{ refund.amount }}</td>
          <td class="max-w-40 truncate px-3 py-1.5 text-black" :title="refund.reason || ''">{{ refund.reason || '-' }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="REFUND_STATUS_CLASS[refund.status]">{{ REFUND_STATUS_LABELS[refund.status] }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ refund.created_at }}</td>
          <td class="px-3 py-1.5">
            <div v-if="refund.status === 'pending'" class="flex items-center gap-1">
              <button class="text-emerald-600 hover:underline" @click="askProcess(refund, 'approve')">同意</button>
              <span class="text-slate-200">|</span>
              <button class="text-red-500 hover:underline" @click="askProcess(refund, 'reject')">拒绝</button>
            </div>
            <span v-else-if="refund.admin_remark" class="text-xs text-slate-400">{{ refund.admin_remark }}</span>
            <span v-else class="text-slate-300">-</span>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="7"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!refunds.length && !loading">
          <td colspan="7" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
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
  </div>
</template>
