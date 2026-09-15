<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronLeft, ChevronRight, MessageSquare, Search, Star, Trash2 } from 'lucide-vue-next'
import {
  approveReview,
  deleteReview,
  getReviews,
  rejectReview,
  replyReview,
  REVIEW_STATUS_CLASS,
  REVIEW_STATUS_LABELS,
  setAuditMode,
  type AdminReview,
  type AdminReviewStatus,
  type ReviewStats,
} from '@/api/review'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 评价管理（V1.1 F01 / T-017，权限 review.manage）
 *
 * 列表筛选 + 待审核优先 + 统计小卡 + 审核/驳回/回复/删除 + 审核模式开关（config.manage）。
 */
const auth = useAuthStore()

const list = ref<AdminReview[]>([])
const stats = ref<ReviewStats>({ pending: 0, today: 0, total: 0, avg: 0 })
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')

const keyword = ref('')
const statusFilter = ref<'' | AdminReviewStatus>('')
const ratingFilter = ref<number | 0>(0)

const auditMode = ref(false)
const canManageConfig = computed(() => auth.hasPermission('config.manage'))

// 详情抽屉
const detail = ref<AdminReview | null>(null)

// 操作弹层
const confirmState = ref<{ title: string; message: string; danger?: boolean; run: () => Promise<void> } | null>(null)
const processing = ref(false)

// 回复输入
const replyTarget = ref<AdminReview | null>(null)
const replyText = ref('')

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const res = await getReviews({
      keyword: keyword.value || undefined,
      status: statusFilter.value || undefined,
      rating: ratingFilter.value || undefined,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    list.value = res.data.data.list
    stats.value = res.data.data.stats
    auditMode.value = res.data.data.audit_mode
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(load)

function search() {
  pagination.value.page = 1
  load()
}

function filterStatus(s: '' | AdminReviewStatus) {
  statusFilter.value = s
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

function askApprove(r: AdminReview) {
  confirmState.value = {
    title: '通过审核',
    message: `确定通过「${r.product_title}」的这条评价？通过后将在商品页展示。`,
    run: async () => {
      await approveReview(r.id)
      await load()
    },
  }
}

function askReject(r: AdminReview) {
  const reason = prompt('请输入驳回原因（必填）：')
  if (reason === null) return
  if (!reason.trim()) {
    tip.value = '驳回原因不能为空'
    return
  }
  confirmState.value = {
    title: '驳回评价',
    message: `确定驳回这条评价？原因：${reason.trim()}`,
    danger: true,
    run: async () => {
      await rejectReview(r.id, reason.trim())
      await load()
    },
  }
}

function askDelete(r: AdminReview) {
  confirmState.value = {
    title: '删除评价',
    message: `确定删除「${r.product_title}」的这条评价？该操作不可恢复。`,
    danger: true,
    run: async () => {
      await deleteReview(r.id)
      await load()
    },
  }
}

async function doConfirm() {
  if (!confirmState.value) return
  processing.value = true
  tip.value = ''
  try {
    await confirmState.value.run()
    confirmState.value = null
    detail.value = null
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '操作失败'
  } finally {
    processing.value = false
  }
}

function openReply(r: AdminReview) {
  replyTarget.value = r
  replyText.value = r.reply_content ?? ''
}

async function submitReply() {
  if (!replyTarget.value) return
  if (!replyText.value.trim()) {
    tip.value = '回复内容不能为空'
    return
  }
  processing.value = true
  tip.value = ''
  try {
    await replyReview(replyTarget.value.id, replyText.value.trim())
    replyTarget.value = null
    replyText.value = ''
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '回复失败'
  } finally {
    processing.value = false
  }
}

async function toggleAuditMode() {
  if (!canManageConfig.value) {
    tip.value = '仅超级管理员可调整审核模式'
    return
  }
  tip.value = ''
  try {
    const next = !auditMode.value
    await setAuditMode(next)
    auditMode.value = next
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '更新失败'
  }
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">评价管理</h2>
      <div class="flex items-center gap-2 text-[13px]">
        <span class="text-slate-500">先审后显</span>
        <button
          type="button"
          class="relative h-5 w-10 rounded-full transition-colors"
          :class="auditMode ? 'bg-[#1677ff]' : 'bg-slate-300'"
          :disabled="!canManageConfig"
          data-testid="audit-mode-toggle"
          @click="toggleAuditMode"
        >
          <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-all" :class="auditMode ? 'left-5' : 'left-0.5'"></span>
        </button>
        <span :class="auditMode ? 'text-[#1677ff]' : 'text-slate-400'">{{ auditMode ? '已开启' : '已关闭' }}</span>
        <span v-if="!canManageConfig" class="text-xs text-slate-400" data-testid="audit-mode-readonly">（仅超管可调整）</span>
      </div>
    </div>

    <!-- 统计小卡 -->
    <div class="mb-4 grid grid-cols-4 gap-3" data-testid="review-stats">
      <div class="rounded-lg bg-amber-50 px-4 py-3">
        <div class="text-2xl font-bold text-amber-600">{{ stats.pending }}</div>
        <div class="text-xs text-slate-500">待审核</div>
      </div>
      <div class="rounded-lg bg-blue-50 px-4 py-3">
        <div class="text-2xl font-bold text-[#1677ff]">{{ stats.today }}</div>
        <div class="text-xs text-slate-500">今日新增</div>
      </div>
      <div class="rounded-lg bg-slate-50 px-4 py-3">
        <div class="text-2xl font-bold text-slate-700">{{ stats.total }}</div>
        <div class="text-xs text-slate-500">评价总数</div>
      </div>
      <div class="rounded-lg bg-orange-50 px-4 py-3">
        <div class="text-2xl font-bold text-[#ff6a00]">{{ stats.avg || '-' }}</div>
        <div class="text-xs text-slate-500">平均分</div>
      </div>
    </div>

    <p v-if="tip" class="mb-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

    <!-- 筛选 -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <button
        v-for="tab in [['', '全部'], ['pending', '待审核'], ['approved', '已通过'], ['rejected', '已拒绝']] as const"
        :key="tab[0]"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="statusFilter === tab[0] ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
        :data-testid="`status-filter-${tab[0] || 'all'}`"
        @click="filterStatus(tab[0] as AdminReviewStatus | '')"
      >{{ tab[1] }}</button>

      <div class="ml-auto flex items-center gap-2">
        <select
          v-model.number="ratingFilter"
          class="rounded-md border border-slate-300 px-2 py-1 text-xs outline-none focus:border-[#1677ff]"
          @change="search"
        >
          <option :value="0">全部评分</option>
          <option v-for="r in [5, 4, 3, 2, 1]" :key="r" :value="r">{{ r }} 星</option>
        </select>
        <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2">
          <Search class="h-3.5 w-3.5 text-slate-400" />
          <input
            v-model="keyword"
            placeholder="商品名/内容"
            class="w-40 py-1 text-xs outline-none"
            data-testid="review-keyword"
            @keyup.enter="search"
          />
        </div>
        <button class="rounded-md bg-[#1677ff] px-3 py-1 text-xs text-white hover:bg-[#4096ff]" @click="search">查询</button>
      </div>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">商品</th>
          <th class="w-32 px-3 py-1.5">用户</th>
          <th class="w-24 px-3 py-1.5">评分</th>
          <th class="px-3 py-1.5">内容</th>
          <th class="w-24 px-3 py-1.5">状态</th>
          <th class="w-36 px-3 py-1.5">时间</th>
          <th class="w-56 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in list" :key="r.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`review-row-${r.id}`">
          <td class="max-w-40 truncate px-3 py-1.5 text-black" :title="r.product_title || ''">{{ r.product_title || '-' }}</td>
          <td class="px-3 py-1.5 text-black">
            {{ r.nickname || '匿名用户' }}
            <span v-if="r.is_anonymous" class="ml-1 rounded bg-slate-100 px-1 text-[11px] text-slate-400">匿名</span>
          </td>
          <td class="px-3 py-1.5">
            <span class="flex items-center gap-0.5 text-[#ffb400]">
              <Star v-for="n in 5" :key="n" class="h-3 w-3" :fill="n <= r.rating ? '#ffb400' : 'none'" :class="n <= r.rating ? '' : 'text-slate-200'" />
            </span>
          </td>
          <td class="max-w-56 truncate px-3 py-1.5 text-black" :title="r.content || ''">{{ r.content || '-' }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="REVIEW_STATUS_CLASS[r.status]">{{ r.status_label || REVIEW_STATUS_LABELS[r.status] }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ r.created_at }}</td>
          <td class="px-3 py-1.5">
            <div class="flex flex-wrap items-center gap-1">
              <button class="text-slate-500 hover:text-[#1677ff]" @click="detail = r">详情</button>
              <template v-if="r.status === 'pending'">
                <span class="text-slate-200">|</span>
                <button class="text-emerald-600 hover:underline" :data-testid="`approve-${r.id}`" @click="askApprove(r)">通过</button>
                <span class="text-slate-200">|</span>
                <button class="text-red-500 hover:underline" :data-testid="`reject-${r.id}`" @click="askReject(r)">驳回</button>
              </template>
              <span class="text-slate-200">|</span>
              <button class="text-[#1677ff] hover:underline" :data-testid="`reply-${r.id}`" @click="openReply(r)">{{ r.reply_content ? '改回复' : '回复' }}</button>
              <span class="text-slate-200">|</span>
              <button class="text-red-500 hover:underline" :data-testid="`delete-${r.id}`" @click="askDelete(r)"><Trash2 class="inline h-3.5 w-3.5" /></button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="7"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="7" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
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
          v-for="p in pagination.total_pages"
          :key="p"
          class="h-7 min-w-7 rounded border px-1.5"
          :class="p === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
          @click="goPage(p)"
        >{{ p }}</button>
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)"
        ><ChevronRight class="h-4 w-4" /></button>
      </div>
    </div>

    <!-- 详情抽屉 -->
    <div v-if="detail" class="fixed inset-0 z-40 flex justify-end bg-black/30" @click.self="detail = null">
      <div class="h-full w-[440px] overflow-y-auto bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
          <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
            <MessageSquare class="h-4 w-4 text-[#1677ff]" /> 评价详情
          </h3>
          <button class="text-slate-400 hover:text-slate-600" @click="detail = null">✕</button>
        </div>

        <dl class="space-y-3 text-[13px]">
          <div class="flex gap-3"><dt class="w-20 shrink-0 text-slate-400">商品</dt><dd class="text-slate-700">{{ detail.product_title }}</dd></div>
          <div class="flex gap-3"><dt class="w-20 shrink-0 text-slate-400">用户</dt><dd class="text-slate-700">{{ detail.nickname }}<span v-if="detail.is_anonymous" class="ml-1 text-xs text-slate-400">（匿名展示）</span></dd></div>
          <div class="flex gap-3">
            <dt class="w-20 shrink-0 text-slate-400">评分</dt>
            <dd class="flex items-center gap-0.5 text-[#ffb400]">
              <Star v-for="n in 5" :key="n" class="h-4 w-4" :fill="n <= detail.rating ? '#ffb400' : 'none'" :class="n <= detail.rating ? '' : 'text-slate-200'" />
            </dd>
          </div>
          <div class="flex gap-3"><dt class="w-20 shrink-0 text-slate-400">订单</dt><dd class="text-slate-700">#{{ detail.order_id }}</dd></div>
          <div class="flex gap-3"><dt class="w-20 shrink-0 text-slate-400">状态</dt><dd><span class="rounded px-2 py-0.5 text-xs" :class="REVIEW_STATUS_CLASS[detail.status]">{{ detail.status_label }}</span></dd></div>
          <div v-if="detail.reject_reason" class="flex gap-3"><dt class="w-20 shrink-0 text-slate-400">驳回原因</dt><dd class="text-red-500">{{ detail.reject_reason }}</dd></div>
          <div class="flex gap-3"><dt class="w-20 shrink-0 text-slate-400">内容</dt><dd class="whitespace-pre-wrap text-slate-700">{{ detail.content || '（无文字）' }}</dd></div>
          <div v-if="detail.images?.length" class="flex gap-3">
            <dt class="w-20 shrink-0 text-slate-400">图片</dt>
            <dd class="flex flex-wrap gap-2">
              <img v-for="img in detail.images" :key="img" :src="img" class="h-20 w-20 rounded-lg object-cover" alt="" />
            </dd>
          </div>
          <div v-if="detail.reply_content" class="flex gap-3">
            <dt class="w-20 shrink-0 text-slate-400">商家回复</dt>
            <dd class="text-slate-700">{{ detail.reply_content }}</dd>
          </div>
        </dl>

        <div class="mt-6 flex flex-wrap gap-2">
          <button v-if="detail.status === 'pending'" class="rounded-md bg-emerald-500 px-4 py-1.5 text-xs text-white hover:bg-emerald-600" @click="askApprove(detail)">通过</button>
          <button v-if="detail.status === 'pending'" class="rounded-md bg-red-500 px-4 py-1.5 text-xs text-white hover:bg-red-600" @click="askReject(detail)">驳回</button>
          <button class="rounded-md border border-[#1677ff] px-4 py-1.5 text-xs text-[#1677ff] hover:bg-[#f0f7ff]" @click="openReply(detail)">商家回复</button>
          <button class="rounded-md border border-red-200 px-4 py-1.5 text-xs text-red-500 hover:bg-red-50" @click="askDelete(detail)">删除</button>
        </div>
      </div>
    </div>

    <!-- 回复弹层 -->
    <div v-if="replyTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="replyTarget = null">
      <div class="w-[480px] rounded-lg bg-white p-6">
        <h3 class="mb-3 text-base font-semibold text-slate-800">商家回复</h3>
        <p class="mb-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
          原评价：{{ replyTarget.content || '（无文字）' }}
        </p>
        <textarea
          v-model="replyText"
          rows="4"
          maxlength="500"
          placeholder="输入回复内容，将展示在评价下方并通知买家"
          class="w-full resize-none rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
          data-testid="reply-text"
        ></textarea>
        <div class="mt-4 flex justify-end gap-2">
          <button class="rounded-md border border-slate-200 px-4 py-1.5 text-xs text-slate-500" @click="replyTarget = null">取消</button>
          <button
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-xs text-white hover:bg-[#4096ff] disabled:opacity-60"
            :disabled="processing"
            data-testid="reply-submit"
            @click="submitReply"
          >{{ processing ? '提交中…' : '提交回复' }}</button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title"
      :message="confirmState?.message"
      :danger="confirmState?.danger"
      :confirm-text="processing ? '处理中…' : '确认'"
      @confirm="doConfirm"
      @cancel="confirmState = null"
    />
  </div>
</template>
