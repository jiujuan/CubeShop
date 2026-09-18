<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ChevronDown, ChevronLeft, ChevronRight, ImagePlus, RefreshCw, Search, X } from 'lucide-vue-next'
import {
  assignCsTicket, batchAssignCsTickets, changeCsTicketStatus, getCsAssignees, getCsQuickRepliesByType, getCsTicket, getCsTicketTypes, getCsTickets,
  replyCsTicket, setCsTicketPriority, type CsQuickReplyRow, type CsTicketActions, type CsTicketDetail, type CsTicketOrderSnapshot,
  type CsTicketRow, type CsTicketTypeOption, type CsTicketUserSummary,
} from '@/api/cs'
import { uploadImage } from '@/api/product'
import { REFUND_STATUS_LABELS } from '@/api/refund'
import { renderCsTemplate } from '@/utils/csTemplate'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 客服工单工作台（CS-114）
 *
 * 列表（六类筛选 + 待处理红点）+ 右侧三栏详情抽屉（用户/订单摘要 · 消息流 · 操作区）。
 * 无 cs.ticket.handle 时隐藏回复区与全部操作按钮（只读视图）。
 */
const auth = useAuthStore()
const canHandle = computed(() => auth.hasPermission('cs.ticket.handle'))

/** 状态机（与后端 CsTicket::TRANSITIONS 对齐） */
const TRANSITIONS: Record<string, string[]> = {
  pending: ['processing', 'closed'],
  processing: ['waiting_user', 'completed', 'closed'],
  waiting_user: ['processing', 'closed'],
  completed: ['processing', 'closed'],
  closed: [],
}
const STATUS_LABELS: Record<string, string> = {
  pending: '待处理', processing: '处理中', waiting_user: '等待用户回复', completed: '已完成', closed: '已关闭',
}

const loading = ref(true)
const list = ref<CsTicketRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const pendingCount = ref(0)
const types = ref<CsTicketTypeOption[]>([])
const assignees = ref<Array<{ id: number; username: string; nickname: string | null }>>([])
const tip = ref('')

const filters = ref<{ status: string; type_id: number | null; priority: number | null; keyword: string; created_start: string; created_end: string }>({
  status: '', type_id: null, priority: null, keyword: '', created_start: '', created_end: '',
})

// 详情
const selectedId = ref<number | null>(null)
const detailLoading = ref(false)
const detail = ref<CsTicketDetail | null>(null)
const userSummary = ref<CsTicketUserSummary | null>(null)
const orderSnapshot = ref<CsTicketOrderSnapshot | null>(null)
const actions = ref<CsTicketActions>({ can_reply: false, can_complete: false, can_close: false })

const replyContent = ref('')
const replyImages = ref<string[]>([])
const replyInternal = ref(false)
const sending = ref(false)
const uploadings = ref(false)
const assigning = ref(false)

// 快捷回复模板（CS-204：工作台下拉，按当前工单类型过滤）
const replyTextarea = ref<HTMLTextAreaElement | null>(null)
const quickReplies = ref<CsQuickReplyRow[]>([])
const quickReplyLoading = ref(false)
const quickReplyOpen = ref(false)
/** 快捷回复容器（按钮 + 面板），用于「点击外部关闭」的范围判断 */
const quickReplyRef = ref<HTMLElement | null>(null)

/** 点击面板外部（容器外任意处）时收起快捷回复面板 */
function onQuickReplyDocMousedown(e: MouseEvent) {
  if (!quickReplyOpen.value) return
  if (quickReplyRef.value && !quickReplyRef.value.contains(e.target as Node)) {
    quickReplyOpen.value = false
  }
}
onMounted(() => document.addEventListener('mousedown', onQuickReplyDocMousedown))
onBeforeUnmount(() => document.removeEventListener('mousedown', onQuickReplyDocMousedown))

const showDrawer = ref(false)
const confirmState = ref<{ type: 'status' | 'priority'; target: string | number } | null>(null)

const statusTargets = computed(() => (detail.value ? TRANSITIONS[detail.value.status] ?? [] : []))

async function loadList(page = 1) {
  loading.value = true
  try {
    const { data } = await getCsTickets({
      status: filters.value.status || undefined,
      type_id: filters.value.type_id ?? undefined,
      priority: filters.value.priority ?? undefined,
      keyword: filters.value.keyword.trim() || undefined,
      created_start: filters.value.created_start || undefined,
      created_end: filters.value.created_end || undefined,
      page,
      per_page: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
    pendingCount.value = data.data.meta.pending_count
  } finally {
    loading.value = false
  }
}

function resetFilters() {
  filters.value = { status: '', type_id: null, priority: null, keyword: '', created_start: '', created_end: '' }
  loadList(1)
}

async function openDetail(id: number) {
  selectedId.value = id
  showDrawer.value = true
  detailLoading.value = true
  replyContent.value = ''
  replyImages.value = []
  replyInternal.value = false
  try {
    const { data } = await getCsTicket(id)
    detail.value = data.data.ticket
    userSummary.value = data.data.user_summary
    orderSnapshot.value = data.data.order_snapshot
    actions.value = data.data.actions
    await loadQuickReplies()
  } finally {
    detailLoading.value = false
  }
}

async function afterMutation() {
  await Promise.all([loadList(pagination.value.page), selectedId.value ? refreshDetail() : Promise.resolve()])
}

async function refreshDetail() {
  if (selectedId.value === null) return
  const { data } = await getCsTicket(selectedId.value)
  detail.value = data.data.ticket
  userSummary.value = data.data.user_summary
  orderSnapshot.value = data.data.order_snapshot
  actions.value = data.data.actions
}

async function changeStatus(target: string) {
  if (selectedId.value === null) return
  tip.value = ''
  try {
    await changeCsTicketStatus(selectedId.value, target)
    await afterMutation()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '操作失败'
  } finally {
    confirmState.value = null
  }
}

async function doAssign(assigneeId: number | null) {
  if (selectedId.value === null) return
  assigning.value = true
  tip.value = ''
  try {
    await assignCsTicket(selectedId.value, assigneeId)
    await afterMutation()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '转交失败'
  } finally {
    assigning.value = false
  }
}

async function togglePriority() {
  if (!detail.value || selectedId.value === null) return
  const target = detail.value.priority === 1 ? 0 : 1
  tip.value = ''
  try {
    await setCsTicketPriority(selectedId.value, target)
    await afterMutation()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '操作失败'
  } finally {
    confirmState.value = null
  }
}

async function batchAssign(assigneeId: number | null, ids: number[]) {
  tip.value = ''
  try {
    await batchAssignCsTickets(ids, assigneeId)
    await loadList(pagination.value.page)
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '批量转交失败'
  }
}

async function onReplyFiles(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  for (const f of files) {
    if (replyImages.value.length >= 9) break
    uploadings.value = true
    try {
      const { data } = await uploadImage(f)
      replyImages.value.push(data.data.url)
    } catch (err) {
      tip.value = err instanceof Error ? err.message : '图片上传失败'
    } finally {
      uploadings.value = false
    }
  }
}

async function sendReply() {
  if (selectedId.value === null) return
  tip.value = ''
  if (!replyContent.value.trim() && !replyImages.value.length) {
    tip.value = '请输入内容或添加图片'
    return
  }
  sending.value = true
  try {
    await replyCsTicket(selectedId.value, {
      content: replyContent.value.trim() || undefined,
      images: replyImages.value.length ? replyImages.value : undefined,
      is_internal: replyInternal.value,
    })
    replyContent.value = ''
    replyImages.value = []
    replyInternal.value = false
    await afterMutation()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '回复失败'
  } finally {
    sending.value = false
  }
}

function closeDrawer() {
  showDrawer.value = false
  selectedId.value = null
  detail.value = null
  // 收起快捷回复面板，避免关抽屉再开新工单时面板保持展开态
  quickReplyOpen.value = false
}

// ---------- 快捷回复（CS-204） ----------

/** 按当前工单类型取用模板（通用 + 该类型专属） */
async function loadQuickReplies() {
  const typeId = detail.value?.type_id ?? null
  quickReplyLoading.value = true
  try {
    const { data } = await getCsQuickRepliesByType(typeId)
    quickReplies.value = data.data
  } catch {
    quickReplies.value = []
  } finally {
    quickReplyLoading.value = false
  }
}

/** 构建变量上下文（与后端 render() 一致） */
function templateContext() {
  return {
    user_nickname: detail.value?.user?.nickname ?? '',
    ticket_no: detail.value?.ticket_no ?? '',
    order_no: orderSnapshot.value?.order_no ?? '',
  }
}

/** 选中模板后插入到文本域光标处（无光标则追加到末尾），并完成变量替换 */
function insertQuickReply(item: CsQuickReplyRow) {
  const text = renderCsTemplate(item.content, templateContext())
  const el = replyTextarea.value
  if (!el) {
    replyContent.value += text
  } else {
    const start = el.selectionStart ?? replyContent.value.length
    const end = el.selectionEnd ?? replyContent.value.length
    replyContent.value = replyContent.value.slice(0, start) + text + replyContent.value.slice(end)
    // 还原光标到插入文本之后
    const pos = start + text.length
    requestAnimationFrame(() => {
      el.focus()
      el.setSelectionRange(pos, pos)
    })
  }
  quickReplyOpen.value = false
}

onMounted(async () => {
  await loadList(1)
  try { types.value = (await getCsTicketTypes()).data.data } catch { types.value = [] }
  try {
    // 可转交对象＝持有 cs.ticket.view 的启用账号（客服角色无需 account.manage）
    const { data } = await getCsAssignees()
    assignees.value = data.data.map((a) => ({ id: a.id, username: a.username, nickname: a.nickname }))
  } catch {
    assignees.value = []
  }
})

defineExpose({ batchAssign })
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="cs-ticket-view">
    <!-- 标题栏 -->
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">
        服务工单
        <span v-if="pendingCount" class="ml-1.5 rounded-full bg-[#ff4d4f] px-2 py-0.5 align-middle text-xs text-white" data-testid="cs-pending-count">{{ pendingCount }}</span>
      </h2>
      <button class="flex items-center gap-1 rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" data-testid="cs-refresh" @click="loadList(pagination.page)">
        <RefreshCw class="h-3.5 w-3.5" /> 刷新
      </button>
    </div>

    <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="cs-tip">{{ tip }}</p>

    <!-- 筛选 -->
    <div class="mb-3 flex flex-wrap items-center gap-2 text-[13px]" data-testid="cs-filters">
      <select v-model="filters.status" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-status-filter" @change="loadList(1)">
        <option value="">全部状态</option>
        <option v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select v-model.number="filters.type_id" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-type-filter" @change="loadList(1)">
        <option :value="null">全部类型</option>
        <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
      </select>
      <select v-model.number="filters.priority" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-priority-filter" @change="loadList(1)">
        <option :value="null">全部优先级</option>
        <option :value="0">普通</option>
        <option :value="1">紧急</option>
      </select>
      <input v-model="filters.created_start" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-start" @change="loadList(1)" />
      <input v-model="filters.created_end" type="date" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-end" @change="loadList(1)" />
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <Search class="h-3.5 w-3.5 text-slate-400" />
        <input v-model="filters.keyword" type="text" placeholder="工单号/手机号/订单号" class="w-44 outline-none" data-testid="cs-keyword-input" @keyup.enter="loadList(1)" />
      </div>
      <button class="rounded-md bg-[#1677ff] px-5 py-1.5 text-white hover:bg-[#4096ff]" data-testid="cs-search-btn" @click="loadList(1)">查询</button>
      <button class="rounded-md border border-slate-300 px-4 py-1.5 text-slate-600 hover:bg-slate-50" data-testid="cs-filter-reset" @click="resetFilters">重置</button>
    </div>

    <!-- 列表 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">工单号</th>
          <th class="px-3 py-1.5">类型</th>
          <th class="px-3 py-1.5">标题</th>
          <th class="px-3 py-1.5">用户</th>
          <th class="px-3 py-1.5">状态</th>
          <th class="px-3 py-1.5">优先级</th>
          <th class="px-3 py-1.5">创建时间</th>
          <th class="px-3 py-1.5">处理人</th>
          <th class="px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="t in list" :key="t.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`cs-ticket-row-${t.id}`">
          <td class="px-3 py-1.5 font-mono text-black">{{ t.ticket_no }}</td>
          <td class="px-3 py-1.5 text-black">{{ t.type?.name ?? t.type_name ?? '—' }}</td>
          <td class="max-w-[180px] truncate px-3 py-1.5 text-black">{{ t.title }}</td>
          <td class="px-3 py-1.5 text-black">{{ t.user?.nickname || t.user?.username || t.user_name || '—' }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="t.status === 'closed' ? 'bg-slate-100 text-slate-400' : 'bg-[#e6f4ff] text-[#1677ff]'">
              {{ t.status_label ?? STATUS_LABELS[t.status] ?? t.status }}
            </span>
          </td>
          <td class="px-3 py-1.5">
            <span v-if="t.priority === 1" class="rounded bg-[#fff1f0] px-1.5 py-0.5 text-xs text-[#ff4d4f]">紧急</span>
            <span v-else class="text-slate-400">普通</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ t.created_at }}</td>
          <td class="px-3 py-1.5 text-black" :data-testid="`cs-assignee-${t.id}`">{{ t.assignee?.username ?? t.assignee_name ?? '—' }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button class="hover:underline" :data-testid="`cs-detail-${t.id}`" @click="openDetail(t.id)">详情</button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="9"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="9" class="px-3 py-12 text-center text-slate-400" data-testid="cs-list-empty">暂无工单</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <div v-if="pagination.total_pages > 1" class="mt-4 flex items-center justify-between text-[13px] text-slate-500">
      <span>共 {{ pagination.total }} 条记录 / 每页 {{ pagination.page_size }} 条</span>
      <div class="flex items-center gap-1">
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page <= 1" @click="loadList(pagination.page - 1)"
        ><ChevronLeft class="h-4 w-4" /></button>
        <button
          v-for="page in pagination.total_pages" :key="page"
          class="h-7 min-w-7 rounded border px-1.5"
          :class="page === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
          @click="loadList(page)"
        >{{ page }}</button>
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page >= pagination.total_pages" @click="loadList(pagination.page + 1)"
        ><ChevronRight class="h-4 w-4" /></button>
      </div>
    </div>

    <!-- 详情抽屉 -->
    <div v-if="showDrawer" class="fixed inset-0 z-40 flex justify-end bg-black/30" data-testid="cs-ticket-drawer" @click.self="closeDrawer">
      <div class="flex h-full w-full max-w-[1000px] flex-col bg-slate-50">
        <div class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
          <h2 class="text-sm font-semibold text-slate-800">
            工单详情
            <span v-if="detail" class="ml-2 font-mono text-xs text-slate-400">{{ detail.ticket_no }}</span>
          </h2>
          <button class="rounded p-1 text-slate-400 hover:bg-slate-100" data-testid="cs-drawer-close" @click="closeDrawer"><X class="h-4 w-4" /></button>
        </div>

        <div v-if="detailLoading" class="flex flex-1 items-center justify-center text-sm text-slate-400">加载中…</div>

        <div v-else-if="detail" class="grid min-h-0 flex-1 grid-cols-1 gap-3 overflow-y-auto p-4 lg:grid-cols-12">
          <!-- 左：用户 + 订单摘要 -->
          <aside class="space-y-3 lg:col-span-3">
            <section class="rounded-lg bg-white p-4 text-[13px]" data-testid="cs-user-summary">
              <h3 class="mb-2 text-xs font-semibold text-slate-500">用户信息</h3>
              <p class="text-slate-700">{{ userSummary?.nickname || '—' }}</p>
              <p class="mt-1 text-xs text-slate-400">手机 {{ userSummary?.phone_masked || '—' }}</p>
              <p class="mt-0.5 text-xs text-slate-400">注册 {{ userSummary?.registered_at || '—' }}</p>
              <p class="mt-0.5 text-xs text-slate-400">历史工单 {{ userSummary?.ticket_count ?? 0 }} 条</p>
            </section>
            <section v-if="orderSnapshot" class="rounded-lg bg-white p-4 text-[13px]" data-testid="cs-order-card">
              <div class="mb-2 flex items-center justify-between">
                <h3 class="text-xs font-semibold text-slate-500">关联订单</h3>
                <RouterLink
                  :to="`/orders/${orderSnapshot.order_id}`"
                  class="text-xs text-[#1677ff] hover:underline"
                  data-testid="cs-order-jump"
                >查看订单 ›</RouterLink>
              </div>

              <div class="flex items-start gap-2">
                <img v-if="orderSnapshot.items[0]?.image" :src="orderSnapshot.items[0].image" class="h-16 w-16 shrink-0 rounded object-cover" alt="" />
                <div class="min-w-0">
                  <p class="truncate text-slate-700" data-testid="cs-order-no">{{ orderSnapshot.order_no }}</p>
                  <p class="mt-1 text-xs text-slate-400">
                    <span data-testid="cs-order-status">{{ orderSnapshot.status_label }}</span>
                    · 实付 ¥{{ orderSnapshot.pay_amount }} · {{ orderSnapshot.item_count }} 件
                  </p>
                </div>
              </div>

              <!-- 商品清单 -->
              <ul v-if="orderSnapshot.items.length" class="mt-2 space-y-1 border-t border-slate-100 pt-2" data-testid="cs-order-items">
                <li v-for="(it, idx) in orderSnapshot.items" :key="idx" class="flex items-center gap-2">
                  <span class="min-w-0 flex-1 truncate text-slate-600">{{ it.title }}</span>
                  <span class="shrink-0 text-xs text-slate-400">×{{ it.quantity }}</span>
                  <span class="shrink-0 text-slate-600">¥{{ it.price }}</span>
                </li>
              </ul>

              <!-- 收货（手机已脱敏） -->
              <div v-if="orderSnapshot.address.contact_name" class="mt-2 border-t border-slate-100 pt-2" data-testid="cs-order-address">
                <p class="text-slate-600">{{ orderSnapshot.address.contact_name }} {{ orderSnapshot.address.phone_masked }}</p>
                <p class="mt-0.5 text-xs text-slate-400">{{ orderSnapshot.address.full_address }}</p>
              </div>

              <!-- 物流（最新一条轨迹） -->
              <div v-if="orderSnapshot.shipping" class="mt-2 border-t border-slate-100 pt-2" data-testid="cs-order-shipping">
                <p class="text-slate-600">{{ orderSnapshot.shipping.company_name }} {{ orderSnapshot.shipping.tracking_no }}</p>
                <p v-if="orderSnapshot.shipping.latest_trace" class="mt-0.5 text-xs text-slate-400">
                  {{ orderSnapshot.shipping.latest_trace.context }} · {{ orderSnapshot.shipping.latest_trace.occurred_at }}
                </p>
                <p v-else class="mt-0.5 text-xs text-slate-400">暂无轨迹</p>
              </div>

              <!-- 退款记录 -->
              <ul v-if="orderSnapshot.refunds.length" class="mt-2 space-y-1 border-t border-slate-100 pt-2" data-testid="cs-order-refunds">
                <li v-for="r in orderSnapshot.refunds" :key="r.refund_no" class="flex items-center gap-2">
                  <span class="min-w-0 flex-1 truncate text-slate-600">{{ r.refund_no }}</span>
                  <span class="shrink-0 text-xs text-slate-400">{{ REFUND_STATUS_LABELS[r.status as keyof typeof REFUND_STATUS_LABELS] ?? r.status }}</span>
                  <span class="shrink-0 text-slate-600">¥{{ r.amount }}</span>
                </li>
              </ul>
            </section>
            <section class="rounded-lg bg-white p-4 text-[13px]" data-testid="cs-ticket-meta">
              <h3 class="mb-2 text-xs font-semibold text-slate-500">工单信息</h3>
              <p class="text-slate-700">{{ detail.title }}</p>
              <p class="mt-1 text-xs text-slate-400">类型 {{ detail.type?.name ?? detail.type_name ?? '—' }} · {{ detail.created_at }}</p>
              <p class="mt-0.5 text-xs text-slate-400">联系方式 {{ detail.contact || '—' }}</p>
            </section>
          </aside>

          <!-- 中：消息流 + 回复 -->
          <section class="flex min-h-0 flex-col lg:col-span-6">
            <div class="flex-1 space-y-3 overflow-y-auto rounded-lg bg-white p-4" data-testid="cs-messages">
              <div
                v-for="m in detail.messages" :key="m.id"
                class="flex flex-col"
                :class="m.sender_type === 'staff' ? 'items-end' : 'items-start'"
                :data-testid="`cs-msg-${m.id}`"
                :data-sender="m.sender_type"
              >
                <span class="mb-1 px-1 text-[11px] text-slate-400">
                  {{ m.sender_name }} · {{ m.created_at }}
                  <span v-if="m.is_internal" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-600" data-testid="cs-internal-tag">仅客服可见</span>
                </span>
                <div
                  class="max-w-[85%] whitespace-pre-wrap break-words rounded-lg px-3 py-2 text-[13px]"
                  :class="m.sender_type === 'staff'
                    ? 'bg-[#1677ff] text-white'
                    : m.sender_type === 'system'
                      ? 'bg-slate-100 italic text-slate-500'
                      : 'bg-slate-100 text-slate-700'"
                >{{ m.content }}</div>
                <div v-if="m.images?.length" class="mt-1 flex flex-wrap gap-2">
                  <img v-for="(img, i) in m.images" :key="i" :src="img" class="h-16 w-16 rounded border border-slate-200 object-cover" alt="" />
                </div>
              </div>
            </div>

            <!-- 回复区（无 handle 权限隐藏） -->
            <div v-if="canHandle" class="mt-3 rounded-lg bg-white p-3" data-testid="cs-reply-box">
              <div v-if="replyImages.length" class="mb-2 flex flex-wrap gap-2">
                <div v-for="(img, i) in replyImages" :key="img" class="relative h-14 w-14 overflow-hidden rounded border border-slate-200">
                  <img :src="img" class="h-full w-full object-cover" alt="" />
                  <button class="absolute right-0 top-0 bg-black/50 p-0.5 text-white" @click="replyImages.splice(i, 1)"><X class="h-3 w-3" /></button>
                </div>
              </div>
              <!-- 快捷回复下拉（CS-204：按当前工单类型过滤，插入到光标处 + 变量替换） -->
              <div ref="quickReplyRef" class="relative mb-2" data-testid="cs-quick-reply">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]"
                  data-testid="cs-quick-reply-toggle"
                  @click="quickReplyOpen = !quickReplyOpen"
                >
                  快捷回复 <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="quickReplyOpen ? 'rotate-180' : ''" />
                </button>
                <div
                  v-if="quickReplyOpen"
                  class="absolute bottom-full left-0 z-20 mb-1 max-h-64 w-80 overflow-y-auto rounded-md border border-slate-200 bg-white py-1 text-[13px] shadow-lg"
                  data-testid="cs-quick-reply-panel"
                >
                  <button
                    v-for="qr in quickReplies" :key="qr.id"
                    type="button"
                    class="block w-full px-3 py-2 text-left hover:bg-slate-50"
                    data-testid="cs-quick-reply-item"
                    @click="insertQuickReply(qr)"
                  >
                    <span class="font-medium text-slate-700">{{ qr.title }}</span>
                    <span class="mt-0.5 block truncate text-xs text-slate-400">{{ qr.content }}</span>
                  </button>
                  <div v-if="!quickReplyLoading && quickReplies.length === 0" class="px-3 py-2 text-xs text-slate-400" data-testid="cs-quick-reply-empty">
                    暂无模板，<RouterLink to="/cs/quick-replies" class="text-[#1677ff] hover:underline" data-testid="cs-quick-reply-goto">去添加</RouterLink>
                  </div>
                  <div v-if="quickReplyLoading" class="px-3 py-2 text-xs text-slate-400">加载中…</div>
                </div>
              </div>
              <textarea ref="replyTextarea" v-model="replyContent" rows="2" maxlength="2000" placeholder="回复用户…" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-[13px] outline-none focus:border-[#1677ff]" data-testid="cs-reply-input"></textarea>
              <div class="mt-2 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <label class="flex cursor-pointer items-center gap-1 text-xs text-slate-400 hover:text-[#1677ff]">
                    <ImagePlus class="h-4 w-4" /> {{ uploadings ? '上传中…' : '图片' }}
                    <input type="file" accept="image/*" multiple class="hidden" data-testid="cs-reply-images" @change="onReplyFiles" />
                  </label>
                  <label class="flex cursor-pointer items-center gap-1 text-xs text-slate-500" data-testid="cs-reply-internal-label">
                    <input v-model="replyInternal" type="checkbox" data-testid="cs-reply-internal" /> 内部备注
                  </label>
                </div>
                <button class="rounded-full bg-[#1677ff] px-5 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50" :disabled="sending" data-testid="cs-reply-send" @click="sendReply">{{ sending ? '发送中…' : '发送' }}</button>
              </div>
            </div>
          </section>

          <!-- 右：操作区（无 handle 权限隐藏） -->
          <aside v-if="canHandle" class="space-y-3 lg:col-span-3" data-testid="cs-actions">
            <section class="rounded-lg bg-white p-4 text-[13px]">
              <h3 class="mb-2 text-xs font-semibold text-slate-500">状态变更</h3>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="target in statusTargets" :key="target"
                  class="rounded-md border border-slate-200 px-3 py-1.5 text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]"
                  :data-testid="`cs-status-btn-${target}`"
                  @click="confirmState = { type: 'status', target }"
                >标记为「{{ STATUS_LABELS[target] }}」</button>
              </div>
              <p v-if="!statusTargets.length" class="text-xs text-slate-400">已关闭，无可用流转</p>
            </section>

            <section class="rounded-lg bg-white p-4 text-[13px]">
              <h3 class="mb-2 text-xs font-semibold text-slate-500">转交客服</h3>
              <select class="w-full rounded-md border border-slate-300 px-2 py-1.5" :value="detail.assignee_id ?? ''" :disabled="assigning" data-testid="cs-assign-select" @change="doAssign(($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)">
                <option value="">未分配</option>
                <option v-for="a in assignees" :key="a.id" :value="a.id">{{ a.nickname || a.username }}</option>
              </select>
            </section>

            <section class="rounded-lg bg-white p-4 text-[13px]">
              <h3 class="mb-2 text-xs font-semibold text-slate-500">优先级</h3>
              <button
                class="rounded-md border px-3 py-1.5"
                :class="detail.priority === 1 ? 'border-[#ff4d4f] text-[#ff4d4f]' : 'border-slate-200 text-slate-600'"
                data-testid="cs-priority-toggle"
                @click="confirmState = { type: 'priority', target: detail.priority === 1 ? 0 : 1 }"
              >{{ detail.priority === 1 ? '紧急（点击取消）' : '标记紧急' }}</button>
            </section>
          </aside>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :open="confirmState !== null"
      :title="confirmState?.type === 'priority' ? '优先级变更' : '状态变更'"
      message="确认执行该操作？"
      confirm-text="确认"
      @confirm="confirmState?.type === 'priority' ? togglePriority() : changeStatus(String(confirmState?.target))"
      @cancel="confirmState = null"
    />
  </div>
</template>
