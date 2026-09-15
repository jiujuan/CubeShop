<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ClipboardList, Search } from 'lucide-vue-next'
import {
  cancelOrder,
  confirmOrder,
  getOrders,
  ORDER_TABS,
  rebuyOrder,
  type OrderBrief,
  type OrderTab,
} from '@/api/order'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 订单中心（V1.1 E02-C/D / T-006）
 *
 * - 状态分组 Tab（同步 URL query，可分享/返回保持）
 * - 订单号 / 收货人检索 + 下单时间区间
 * - 待付款倒计时（后端 order.timeout_minutes，默认 30 分钟）
 * - 操作按钮由后端 actions 字段驱动，前端不硬编码状态
 * - 再次购买（失效行弹窗提示）
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

/** 待付款自动取消时长（分钟），与后端 system_configs 的 order.timeout_minutes 对齐 */
const AUTO_CANCEL_MINUTES = 30

const loading = ref(true)
const tip = ref('')
const activeTab = ref<OrderTab>('all')
const keyword = ref('')
const startDate = ref('')
const endDate = ref('')
const page = ref(1)
const total = ref(0)
const totalPages = ref(1)
const orders = ref<OrderBrief[]>([])

// 倒计时基准时间（每秒刷新）
const nowTs = ref(Date.now())
let timer: ReturnType<typeof setInterval> | null = null

// 确认收货二次确认
const confirmTarget = ref<OrderBrief | null>(null)
const confirming = ref(false)

const activeTabMeta = computed(
  () => ORDER_TABS.find((t) => t.value === activeTab.value) ?? ORDER_TABS[0],
)

async function load() {
  loading.value = true
  try {
    const { data } = await getOrders({
      tab: activeTab.value,
      keyword: keyword.value.trim() || undefined,
      start: startDate.value || undefined,
      end: endDate.value || undefined,
      page: page.value,
      page_size: 10,
    })
    orders.value = data.data.list
    total.value = data.data.pagination.total
    totalPages.value = Math.max(1, data.data.pagination.total_pages)
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  // URL query → 本地状态（返回/分享保持筛选）
  const q = route.query
  if (typeof q.tab === 'string' && ORDER_TABS.some((t) => t.value === q.tab)) {
    activeTab.value = q.tab as OrderTab
  }
  if (typeof q.keyword === 'string') keyword.value = q.keyword

  if (auth.token) await load()
  else loading.value = false

  timer = setInterval(() => {
    nowTs.value = Date.now()
  }, 1000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})

/** 同步筛选到 URL（不触发重新挂载） */
function syncQuery() {
  router.replace({
    query: {
      ...(activeTab.value !== 'all' ? { tab: activeTab.value } : {}),
      ...(keyword.value.trim() ? { keyword: keyword.value.trim() } : {}),
    },
  })
}

function switchTab(tab: OrderTab) {
  activeTab.value = tab
  page.value = 1
  syncQuery()
  load()
}

function applyFilter() {
  page.value = 1
  syncQuery()
  load()
}

function resetFilter() {
  keyword.value = ''
  startDate.value = ''
  endDate.value = ''
  page.value = 1
  syncQuery()
  load()
}

function changePage(delta: number) {
  const next = page.value + delta
  if (next < 1 || next > totalPages.value) return
  page.value = next
  load()
}

/** 待付款剩余秒数；非待付款返回 null */
function remainingSeconds(order: OrderBrief): number | null {
  if (order.status !== 'pending_payment') return null
  const created = new Date(order.created_at.replace(/-/g, '/')).getTime()
  if (Number.isNaN(created)) return null
  const deadline = created + AUTO_CANCEL_MINUTES * 60 * 1000
  return Math.max(0, Math.floor((deadline - nowTs.value) / 1000))
}

function countdownText(order: OrderBrief): string {
  const secs = remainingSeconds(order)
  if (secs === null) return ''
  if (secs <= 0) return '已超时'
  const m = Math.floor(secs / 60)
  const s = secs % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

// 倒计时归零 → 自动刷新列表状态
watch(nowTs, async () => {
  if (loading.value) return
  const expired = orders.value.some(
    (o) => o.status === 'pending_payment' && remainingSeconds(o) === 0,
  )
  if (expired) await load()
})

async function doCancel(order: OrderBrief) {
  if (!confirm(`确定取消订单 ${order.order_no} 吗？`)) return
  tip.value = ''
  try {
    await cancelOrder(order.id)
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '取消失败'
  }
}

async function doConfirm() {
  if (!confirmTarget.value) return
  confirming.value = true
  tip.value = ''
  try {
    await confirmOrder(confirmTarget.value.id)
    confirmTarget.value = null
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '确认收货失败'
    confirmTarget.value = null
  } finally {
    confirming.value = false
  }
}

/** 再次购买：成功跳购物车；有失效行则弹窗明细 */
async function doRebuy(order: OrderBrief) {
  tip.value = ''
  try {
    const { data } = await rebuyOrder(order.id)
    const result = data.data
    if (result.skipped.length) {
      const lines = result.skipped.map((s) => `· ${s.title}：${s.reason}`).join('\n')
      alert(`已加入购物车 ${result.added} 件商品\n以下商品未能加入：\n${lines}`)
    }
    if (result.added > 0) router.push('/cart')
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '再次购买失败'
  }
}

function goReview(order: OrderBrief) {
  router.push({ path: `/orders/${order.id}`, query: { review: '1' } })
}
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-8">
      <h1 class="mb-6 text-xl font-bold text-slate-800">我的订单</h1>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <ClipboardList class="mb-3 h-10 w-10" />
        <p class="mb-4">登录后查看订单</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push({ path: '/login', query: { redirect: '/orders' } })">去登录</button>
      </div>

      <template v-else>
        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

        <!-- 状态分组 Tab -->
        <div class="mb-4 flex flex-wrap gap-2" data-testid="order-tabs">
          <button
            v-for="tab in ORDER_TABS" :key="tab.value"
            class="rounded-full px-4 py-1.5 text-sm transition-colors"
            :class="activeTab === tab.value ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-600 hover:text-[#1677ff] border border-slate-200'"
            :data-testid="`order-tab-${tab.value}`"
            @click="switchTab(tab.value)"
          >{{ tab.label }}</button>
        </div>

        <!-- 检索区 -->
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-slate-100 bg-white px-4 py-3" data-testid="order-filter">
          <div class="flex items-center gap-2">
            <Search class="h-4 w-4 text-slate-400" />
            <input
              v-model="keyword"
              class="w-56 rounded-lg border border-slate-200 px-3 py-1.5 text-sm outline-none focus:border-[#1677ff]"
              placeholder="订单号 / 收货人姓名 / 手机号"
              data-testid="order-keyword"
              @keyup.enter="applyFilter"
            />
          </div>
          <input
            v-model="startDate"
            type="date"
            class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 outline-none focus:border-[#1677ff]"
            data-testid="order-start"
          />
          <span class="text-slate-300">~</span>
          <input
            v-model="endDate"
            type="date"
            class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 outline-none focus:border-[#1677ff]"
            data-testid="order-end"
          />
          <button
            class="rounded-full bg-[#1677ff] px-5 py-1.5 text-sm text-white hover:bg-[#4096ff]"
            data-testid="order-search-btn"
            @click="applyFilter"
          >搜索</button>
          <button
            class="rounded-full border border-slate-200 px-5 py-1.5 text-sm text-slate-500 hover:bg-slate-50"
            data-testid="order-reset-btn"
            @click="resetFilter"
          >重置</button>
        </div>

        <!-- 空态（按 Tab 区分文案） -->
        <div v-if="!orders.length" class="flex flex-col items-center py-24 text-slate-400" data-testid="order-empty">
          <ClipboardList class="mb-3 h-10 w-10" />
          <p class="mb-4">{{ activeTabMeta.empty }}</p>
          <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push('/')">去逛逛</button>
        </div>

        <!-- 订单卡片 -->
        <div v-else class="space-y-4">
          <div v-for="order in orders" :key="order.id" class="rounded-xl border border-slate-100 bg-white" :data-testid="`order-card-${order.id}`">
            <div class="flex items-center justify-between border-b border-slate-50 px-5 py-3 text-xs">
              <span class="text-slate-500">订单号：{{ order.order_no }}</span>
              <span class="font-medium" :class="order.status === 'pending_payment' ? 'text-[#ff4d4f]' : 'text-[#1677ff]'">{{ order.status_label }}</span>
            </div>

            <div
              class="flex items-center gap-4 px-5 py-3"
              @click="$router.push(`/orders/${order.id}`)"
            >
              <!-- 缩略图组：前 3 + 更多 -->
              <div class="flex shrink-0 gap-2">
                <div
                  v-for="(item, idx) in order.items_preview.slice(0, 3)" :key="idx"
                  class="flex h-14 w-14 items-center justify-center overflow-hidden rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-xl"
                >
                  <img v-if="item.sku_image" :src="item.sku_image" class="h-full w-full object-cover" alt="" />
                  <span v-else>📦</span>
                </div>
                <div
                  v-if="order.items.length > 3"
                  class="flex h-14 w-14 items-center justify-center rounded-lg bg-slate-50 text-xs text-slate-400"
                >+{{ order.items.length - 3 }}</div>
              </div>

              <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-slate-700">{{ order.items_preview[0]?.product_title || '订单商品' }}</p>
                <p class="mt-1 text-xs text-slate-400">
                  共 {{ order.item_count }} 件｜{{ order.created_at }}
                  <span
                    v-if="order.status === 'pending_payment'"
                    class="ml-2 text-[#ff4d4f]"
                    data-testid="order-countdown"
                  >剩余 {{ countdownText(order) }}</span>
                </p>
              </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-50 px-5 py-3">
              <span class="text-sm text-slate-600">
                实付 <b class="text-[#ff4d4f]">¥{{ order.pay_amount }}</b>
              </span>
              <div class="flex items-center gap-3">
                <button
                  v-if="order.actions.can_pay"
                  class="rounded-full bg-[#1677ff] px-4 py-1.5 text-xs text-white hover:bg-[#4096ff]"
                  @click.stop="$router.push(`/orders/${order.id}/pay`)"
                >去支付</button>
                <button
                  v-if="order.actions.can_cancel"
                  class="rounded-full border border-slate-200 px-4 py-1.5 text-xs text-slate-500 hover:border-red-300 hover:text-red-500"
                  @click.stop="doCancel(order)"
                >取消订单</button>
                <button
                  v-if="order.actions.can_confirm"
                  class="rounded-full bg-[#1677ff] px-4 py-1.5 text-xs text-white hover:bg-[#4096ff]"
                  :data-testid="`order-confirm-${order.id}`"
                  @click.stop="confirmTarget = order"
                >确认收货</button>
                <button
                  v-if="order.actions.can_review"
                  class="rounded-full border border-[#1677ff] px-4 py-1.5 text-xs text-[#1677ff] hover:bg-[#f0f7ff]"
                  :data-testid="`order-review-${order.id}`"
                  @click.stop="goReview(order)"
                >评价</button>
                <button
                  v-if="order.actions.can_rebuy"
                  class="rounded-full border border-slate-200 px-4 py-1.5 text-xs text-slate-500 hover:bg-slate-50"
                  :data-testid="`order-rebuy-${order.id}`"
                  @click.stop="doRebuy(order)"
                >再次购买</button>
              </div>
            </div>
          </div>

          <!-- 分页 -->
          <div class="flex items-center justify-center gap-4 pt-2 text-sm text-slate-500">
            <button class="rounded-lg border border-slate-200 px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="changePage(-1)">上一页</button>
            <span>第 {{ page }} / {{ totalPages }} 页（共 {{ total }} 单）</span>
            <button class="rounded-lg border border-slate-200 px-3 py-1.5 disabled:opacity-40" :disabled="page >= totalPages" @click="changePage(1)">下一页</button>
          </div>
        </div>

        <ConfirmDialog
          :model-value="confirmTarget !== null"
          title="确认收货"
          content="请确认已收到商品。确认后订单将完成，该操作不可撤销。"
          confirm-text="确认收货"
          :loading="confirming"
          @update:model-value="(v: boolean) => { if (!v) confirmTarget = null }"
          @confirm="doConfirm"
        />
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
