<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Gavel, MessageSquare, Send, User, X } from 'lucide-vue-next'

import {
  getRefundDisputes,
  getRefundDispute,
  assignRefundDispute,
  resolveRefundDispute,
  postRefundDisputeMessage,
  REFUND_DISPUTE_STATUS_CLASS,
  REFUND_DISPUTE_REASON_LABELS,
  REFUND_DISPUTE_RESOLUTION_LABELS,
  REFUND_DISPUTE_ACTION_LABELS,
  type RefundDispute,
  type RefundDisputeStatus,
  type RefundDisputeReason,
  type RefundDisputeResolution,
  type RefundDisputeAction,
  type RefundDisputeMessage,
} from '@/api/refund-dispute'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 退款纠纷管理页（权限 refund.view / refund.process）
 *
 * 列表（状态 Tab + 原因筛选）→ 详情抽屉（退款快照 + 举证 + 消息线程 + 裁决表单）。
 * 裁决 resolution=resolved_refund 时按 refund_action 经 RefundService 驱动退款状态。
 */
type StatusTab = '' | RefundDisputeStatus

const disputes = ref<RefundDispute[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')
const tab = ref<StatusTab>('')
const reason = ref<RefundDisputeReason | ''>('')
const keyword = ref('')

async function load() {
  loading.value = true
  try {
    const res = await getRefundDisputes({
      status: (tab.value || undefined) as RefundDisputeStatus | undefined,
      reason_code: (reason.value || undefined) as RefundDisputeReason | undefined,
      keyword: keyword.value || undefined,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    disputes.value = res.data.data.list
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(load)

function filterTab(t: StatusTab) {
  tab.value = t
  pagination.value.page = 1
  load()
}

function search() {
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

// ---------- 详情抽屉（快照 + 举证 + 消息 + 裁决） ----------

const detail = ref<RefundDispute | null>(null)
const detailLoading = ref(false)
const detailTip = ref('')
const messages = ref<RefundDisputeMessage[]>([])
const messageBody = ref('')
const sending = ref(false)

const canOperate = computed(
  () => detail.value?.status === 'opened' || detail.value?.status === 'platform_involved',
)

async function openDetail(row: RefundDispute) {
  detail.value = row
  detailTip.value = ''
  messages.value = []
  detailLoading.value = true
  try {
    const res = await getRefundDispute(row.id)
    detail.value = res.data.data
    messages.value = res.data.data.messages ?? []
  } catch (e) {
    detailTip.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  detail.value = null
}

// ---------- 指派 / 裁决 / 留言 ----------

const assigneeId = ref('')
const resolution = ref<RefundDisputeResolution>('resolved_refund')
const refundAction = ref<RefundDisputeAction>('none')
const resolveNote = ref('')
const acting = ref(false)

async function doAssign() {
  if (!detail.value) return
  acting.value = true
  detailTip.value = ''
  try {
    const res = await assignRefundDispute(detail.value.id, Number(assigneeId.value))
    detail.value = res.data.data
    tip.value = ''
    await load()
  } catch (e) {
    detailTip.value = e instanceof Error ? e.message : '指派失败'
  } finally {
    acting.value = false
  }
}

async function doResolve() {
  if (!detail.value) return
  acting.value = true
  detailTip.value = ''
  try {
    const res = await resolveRefundDispute(detail.value.id, {
      resolution: resolution.value,
      refund_action: resolution.value === 'resolved_refund' ? refundAction.value : undefined,
      note: resolveNote.value || undefined,
    })
    detail.value = res.data.data
    await load()
  } catch (e) {
    detailTip.value = e instanceof Error ? e.message : '裁决失败'
  } finally {
    acting.value = false
  }
}

async function doSendMessage() {
  if (!detail.value || !messageBody.value.trim()) return
  sending.value = true
  try {
    await postRefundDisputeMessage(detail.value.id, messageBody.value.trim())
    messageBody.value = ''
    const res = await getRefundDispute(detail.value.id)
    messages.value = res.data.data.messages ?? []
  } catch (e) {
    detailTip.value = e instanceof Error ? e.message : '发送失败'
  } finally {
    sending.value = false
  }
}

const statusTabs: { key: StatusTab; label: string }[] = [
  { key: '', label: '全部' },
  { key: 'opened', label: '待介入' },
  { key: 'platform_involved', label: '已介入' },
  { key: 'resolved_refund', label: '支持买家' },
  { key: 'resolved_reject', label: '支持商家' },
  { key: 'closed', label: '已关闭' },
]
</script>

<template>
  <div class="p-5">
    <h1 class="text-lg font-semibold text-slate-800">退款纠纷管理</h1>
    <p class="mt-1 text-sm text-slate-400">
      买家对退款结论/退货认定发起申诉，平台介入调解；裁决「支持买家」时可驱动退款动作。
    </p>

    <!-- 状态 Tab -->
    <div class="mt-4 flex flex-wrap gap-2">
      <button
        v-for="t in statusTabs"
        :key="t.key"
        class="rounded-md px-3 py-1.5 text-sm"
        :class="tab === t.key ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600'"
        :data-testid="`dispute-tab-${t.key || 'all'}`"
        @click="filterTab(t.key)"
      >{{ t.label }}</button>
    </div>

    <!-- 筛选 -->
    <div class="mt-3 flex flex-wrap items-center gap-2">
      <select v-model="reason" class="rounded border border-slate-200 px-2 py-1.5 text-sm" data-testid="dispute-reason-filter" @change="search">
        <option value="">全部原因</option>
        <option v-for="(label, key) in REFUND_DISPUTE_REASON_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <input
        v-model="keyword"
        placeholder="退款单号 / 订单号 / 买家"
        class="w-64 rounded border border-slate-200 px-2 py-1.5 text-sm focus:border-[#1677ff] focus:outline-none"
        data-testid="dispute-keyword"
        @keyup.enter="search"
      />
      <button class="rounded bg-slate-700 px-3 py-1.5 text-sm text-white" @click="search">查询</button>
    </div>

    <LoadingSpinner v-if="loading" class="mt-6" />
    <p v-else-if="tip" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-600">{{ tip }}</p>

    <div v-else class="mt-4 rounded-lg bg-white p-5 shadow-sm">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-100 text-left text-slate-400">
            <th class="py-2 font-normal">纠纷单</th>
            <th class="py-2 font-normal">退款单号</th>
            <th class="py-2 font-normal">买家</th>
            <th class="py-2 font-normal">原因</th>
            <th class="py-2 font-normal">状态</th>
            <th class="py-2 font-normal">处理人</th>
            <th class="py-2 font-normal">发起时间</th>
            <th class="py-2 font-normal">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in disputes" :key="d.id" class="border-b border-slate-50">
            <td class="py-2.5 font-mono text-xs text-slate-500">#{{ d.id }}</td>
            <td class="py-2.5 font-mono text-xs">{{ d.refund_no }}</td>
            <td class="py-2.5">{{ d.buyer?.nickname || d.buyer?.username || '-' }}</td>
            <td class="py-2.5">{{ d.reason_label }}</td>
            <td class="py-2.5">
              <span class="rounded px-2 py-0.5 text-xs" :class="REFUND_DISPUTE_STATUS_CLASS[d.status]">{{ d.status_label }}</span>
            </td>
            <td class="py-2.5 text-slate-500">{{ d.assignee?.nickname || d.assignee?.username || '-' }}</td>
            <td class="py-2.5 text-slate-400">{{ d.created_at }}</td>
            <td class="py-2.5">
              <button class="text-[#1677ff]" :data-testid="`dispute-detail-${d.id}`" @click="openDetail(d)">详情</button>
            </td>
          </tr>
          <tr v-if="!disputes.length">
            <td colspan="8" class="py-8 text-center text-slate-400">暂无纠纷单</td>
          </tr>
        </tbody>
      </table>
      <TablePagination v-if="!loading" class="mt-4" :pagination="pagination" @change="goPage" />
    </div>

    <!-- 详情抽屉 -->
    <div v-if="detail" class="fixed inset-0 z-40 flex justify-end bg-black/30" @click.self="closeDetail">
      <div class="h-full w-[560px] overflow-y-auto bg-white p-5 shadow-xl" data-testid="dispute-detail">
        <div class="flex items-center justify-between">
          <h2 class="flex items-center gap-2 text-base font-semibold text-slate-800">
            <Gavel class="h-4 w-4 text-[#1677ff]" /> 纠纷详情 #{{ detail.id }}
          </h2>
          <button data-testid="dispute-detail-close" @click="closeDetail"><X class="h-4 w-4 text-slate-400" /></button>
        </div>

        <LoadingSpinner v-if="detailLoading" class="mt-6" />
        <template v-else>
          <p v-if="detailTip" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-600" data-testid="dispute-detail-tip">{{ detailTip }}</p>

          <!-- 退款快照 -->
          <div v-if="detail.refund" class="mt-4 rounded-lg bg-slate-50 p-3 text-sm" data-testid="dispute-refund-snapshot">
            <p class="font-medium text-slate-700">退款快照</p>
            <p class="mt-1 text-slate-500">退款单号：{{ detail.refund.refund_no }}（{{ detail.refund.status }}）</p>
            <p class="text-slate-500">金额：¥{{ detail.refund.amount }} · 类型：{{ detail.refund.type }} · 渠道：{{ detail.refund.channel || '-' }}</p>
            <p class="text-slate-500">买家理由：{{ detail.refund.reason || '-' }}</p>
            <p class="text-slate-500">后台备注：{{ detail.refund.admin_remark || '-' }}</p>
          </div>

          <!-- 买家主张 -->
          <div class="mt-3 rounded-lg bg-amber-50 p-3 text-sm">
            <p class="font-medium text-amber-700">买家主张（{{ detail.reason_label }}）</p>
            <p class="mt-1 text-slate-600">{{ detail.description || '（无描述）' }}</p>
            <div v-if="detail.evidence.length" class="mt-2 flex flex-wrap gap-2">
              <img v-for="(src, i) in detail.evidence" :key="i" :src="src" class="h-16 w-16 rounded object-cover" alt="举证" />
            </div>
          </div>

          <!-- 消息线程 -->
          <div class="mt-4">
            <p class="flex items-center gap-1 text-sm font-medium text-slate-700"><MessageSquare class="h-4 w-4 text-slate-400" /> 沟通记录</p>
            <div class="mt-2 space-y-2">
              <div
                v-for="m in messages"
                :key="m.id"
                class="rounded-md px-3 py-2 text-sm"
                :class="m.sender_type === 'admin' ? 'bg-blue-50' : 'bg-slate-50'"
                data-testid="dispute-message"
              >
                <p class="text-xs text-slate-400">{{ m.sender_type === 'admin' ? '平台' : m.sender_type === 'customer' ? '买家' : '系统' }} · {{ m.created_at }}</p>
                <p class="mt-0.5 text-slate-700">{{ m.body }}</p>
              </div>
              <p v-if="!messages.length" class="text-sm text-slate-400">暂无沟通记录</p>
            </div>
            <div v-if="canOperate" class="mt-2 flex gap-2">
              <input
                v-model="messageBody"
                placeholder="回复买家…"
                class="flex-1 rounded border border-slate-200 px-2 py-1.5 text-sm focus:border-[#1677ff] focus:outline-none"
                data-testid="dispute-message-input"
                @keyup.enter="doSendMessage"
              />
              <button class="rounded bg-[#1677ff] px-3 py-1.5 text-sm text-white disabled:opacity-50" :disabled="sending" data-testid="dispute-message-send" @click="doSendMessage">
                <Send class="h-4 w-4" />
              </button>
            </div>
          </div>

          <!-- 指派 -->
          <div v-if="detail.status === 'opened'" class="mt-4 rounded-lg bg-white p-3 ring-1 ring-slate-100">
            <p class="flex items-center gap-1 text-sm font-medium text-slate-700"><User class="h-4 w-4 text-slate-400" /> 指派处理人</p>
            <div class="mt-2 flex gap-2">
              <input v-model="assigneeId" placeholder="管理员 ID" class="w-32 rounded border border-slate-200 px-2 py-1.5 text-sm" data-testid="dispute-assignee-input" />
              <button class="rounded bg-slate-700 px-3 py-1.5 text-sm text-white disabled:opacity-50" :disabled="acting" data-testid="dispute-assign" @click="doAssign">指派并介入</button>
            </div>
          </div>

          <!-- 裁决 -->
          <div v-if="canOperate" class="mt-4 rounded-lg bg-white p-3 ring-1 ring-slate-100">
            <p class="text-sm font-medium text-slate-700">裁决</p>
            <div class="mt-2 space-y-2 text-sm">
              <label class="flex items-center gap-2">
                <input v-model="resolution" type="radio" value="resolved_refund" data-testid="dispute-resolution-refund" />
                支持买家（触发退款动作）
              </label>
              <label class="flex items-center gap-2">
                <input v-model="resolution" type="radio" value="resolved_reject" />
                支持商家（维持原结论）
              </label>
              <div v-if="resolution === 'resolved_refund'">
                <select v-model="refundAction" class="rounded border border-slate-200 px-2 py-1.5 text-sm" data-testid="dispute-action-select">
                  <option v-for="(label, key) in REFUND_DISPUTE_ACTION_LABELS" :key="key" :value="key">{{ label }}</option>
                </select>
                <p class="mt-1 text-xs text-slate-400">动作将经 RefundService 驱动退款状态；不合法组合会在提交时报错。</p>
              </div>
              <textarea v-model="resolveNote" rows="2" placeholder="裁决说明（买家可见）" class="w-full rounded border border-slate-200 px-2 py-1.5 text-sm focus:border-[#1677ff] focus:outline-none" data-testid="dispute-note" />
              <button class="rounded bg-[#1677ff] px-3 py-1.5 text-sm text-white disabled:opacity-50" :disabled="acting" data-testid="dispute-resolve" @click="doResolve">提交裁决</button>
            </div>
          </div>

          <!-- 裁决结果 -->
          <div v-else-if="detail.resolution" class="mt-4 rounded-lg bg-slate-50 p-3 text-sm" data-testid="dispute-resolution-result">
            <p class="font-medium text-slate-700">裁决：{{ REFUND_DISPUTE_RESOLUTION_LABELS[detail.resolution] }}</p>
            <p class="mt-1 text-slate-500">动作：{{ detail.refund_action ? REFUND_DISPUTE_ACTION_LABELS[detail.refund_action] : '-' }}</p>
            <p class="text-slate-500">说明：{{ detail.resolution_note || '-' }}</p>
            <p class="text-slate-400">裁决时间：{{ detail.resolved_at }}</p>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
