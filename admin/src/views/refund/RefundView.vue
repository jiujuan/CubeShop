<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RotateCcw } from 'lucide-vue-next'
import { getRefunds, processRefund, REFUND_STATUS_CLASS, REFUND_STATUS_LABELS, type Refund, type RefundStatus } from '@/api/refund'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

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
  <div class="space-y-4">
    <h1 class="text-lg font-semibold">退款处理</h1>

    <p v-if="tip" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

    <section class="rounded-lg border border-slate-200 bg-white">
      <h2 class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-sm font-medium">
        <RotateCcw class="h-4 w-4 text-[#1677ff]" />
        退款申请（共 {{ pagination.total }} 条）
      </h2>

      <!-- 状态筛选 -->
      <div class="flex flex-wrap gap-2 border-b border-slate-50 px-4 py-2.5">
        <button
          v-for="tab in [['', '全部'], ['pending', '待审核'], ['success', '退款成功'], ['rejected', '已拒绝']] as const"
          :key="tab[0]"
          class="rounded-full px-3 py-1 text-xs transition-colors"
          :class="statusFilter === tab[0] ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
          @click="filterStatus(tab[0] as RefundStatus | '')"
        >{{ tab[1] }}</button>
      </div>

      <div v-if="loading" class="px-4 py-6"><LoadingSpinner /></div>

      <table v-else class="w-full text-[13px]">
        <thead>
          <tr class="border-b border-slate-100 text-left text-slate-400">
            <th class="px-4 py-2 font-normal">退款单号</th>
            <th class="px-4 py-2 font-normal">订单号</th>
            <th class="px-4 py-2 font-normal">金额</th>
            <th class="px-4 py-2 font-normal">原因</th>
            <th class="px-4 py-2 font-normal">状态</th>
            <th class="px-4 py-2 font-normal">申请时间</th>
            <th class="px-4 py-2 text-right font-normal">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="refund in refunds" :key="refund.id" class="border-b border-slate-50 hover:bg-slate-50/50">
            <td class="px-4 py-2 font-mono text-slate-600">{{ refund.refund_no }}</td>
            <td class="px-4 py-2 font-mono text-slate-600">{{ refund.order_no }}</td>
            <td class="px-4 py-2 font-medium text-[#ff4d4f]">¥{{ refund.amount }}</td>
            <td class="max-w-40 truncate px-4 py-2 text-slate-500" :title="refund.reason || ''">{{ refund.reason || '-' }}</td>
            <td class="px-4 py-2">
              <span class="rounded px-1.5 py-0.5 text-xs" :class="REFUND_STATUS_CLASS[refund.status]">{{ REFUND_STATUS_LABELS[refund.status] }}</span>
            </td>
            <td class="px-4 py-2 text-slate-500">{{ refund.created_at }}</td>
            <td class="px-4 py-2 text-right">
              <template v-if="refund.status === 'pending'">
                <button class="rounded border border-green-200 px-2 py-1 text-xs text-green-600 hover:bg-green-50" @click="askProcess(refund, 'approve')">同意</button>
                <button class="ml-1.5 rounded border border-red-200 px-2 py-1 text-xs text-red-500 hover:bg-red-50" @click="askProcess(refund, 'reject')">拒绝</button>
              </template>
              <span v-else-if="refund.admin_remark" class="text-xs text-slate-400">{{ refund.admin_remark }}</span>
              <span v-else class="text-slate-300">-</span>
            </td>
          </tr>
          <tr v-if="!refunds.length">
            <td colspan="7" class="px-4 py-8 text-center text-slate-400">暂时无数据</td>
          </tr>
        </tbody>
      </table>

      <div class="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-[13px] text-slate-500">
        <span>共 {{ pagination.total }} 条记录 / 每页 {{ pagination.page_size }} 条</span>
        <div class="flex items-center gap-1.5">
          <button class="rounded border border-slate-200 px-2 py-1 disabled:opacity-40" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">上一页</button>
          <button
            v-for="p in pagination.total_pages"
            :key="p"
            class="min-w-8 rounded border px-2 py-1"
            :class="p === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
            @click="goPage(p)"
          >{{ p }}</button>
          <button class="rounded border border-slate-200 px-2 py-1 disabled:opacity-40" :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)">下一页</button>
        </div>
      </div>
    </section>

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
