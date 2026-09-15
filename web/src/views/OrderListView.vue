<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ClipboardList } from 'lucide-vue-next'
import { cancelOrder, getOrders, type OrderBrief, type OrderStatus } from '@/api/order'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 订单列表（Roadmap P4）：按状态筛选
 */
const auth = useAuthStore()

const statusTabs: Array<{ value: '' | OrderStatus; label: string }> = [
  { value: '', label: '全部' },
  { value: 'pending_payment', label: '待支付' },
  { value: 'paid', label: '已支付' },
  { value: 'shipped', label: '已发货' },
  { value: 'completed', label: '已完成' },
  { value: 'refunding', label: '退款中' },
  { value: 'refunded', label: '已退款' },
  { value: 'cancelled', label: '已取消' },
]

const loading = ref(true)
const tip = ref('')
const activeStatus = ref<'' | OrderStatus>('')
const page = ref(1)
const total = ref(0)
const totalPages = ref(1)
const orders = ref<OrderBrief[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await getOrders({
      status: activeStatus.value || undefined,
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
  if (auth.token) await load()
  else loading.value = false
})

function switchTab(status: '' | OrderStatus) {
  activeStatus.value = status
  page.value = 1
  load()
}

function changePage(delta: number) {
  const next = page.value + delta
  if (next < 1 || next > totalPages.value) return
  page.value = next
  load()
}

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

        <!-- 状态筛选 -->
        <div class="mb-4 flex flex-wrap gap-2">
          <button
            v-for="tab in statusTabs" :key="tab.value"
            class="rounded-full px-4 py-1.5 text-sm transition-colors"
            :class="activeStatus === tab.value ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-600 hover:text-[#1677ff] border border-slate-200'"
            @click="switchTab(tab.value)"
          >{{ tab.label }}</button>
        </div>

        <!-- 空态 -->
        <div v-if="!orders.length" class="flex flex-col items-center py-24 text-slate-400">
          <ClipboardList class="mb-3 h-10 w-10" />
          <p class="mb-4">暂无相关订单</p>
          <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push('/')">去逛逛</button>
        </div>

        <!-- 订单卡片 -->
        <div v-else class="space-y-4">
          <div v-for="order in orders" :key="order.id" class="rounded-xl border border-slate-100 bg-white">
            <div class="flex items-center justify-between border-b border-slate-50 px-5 py-3 text-xs">
              <span class="text-slate-500">订单号：{{ order.order_no }}</span>
              <span class="font-medium" :class="order.status === 'pending_payment' ? 'text-[#ff4d4f]' : 'text-[#1677ff]'">{{ order.status_label }}</span>
            </div>

            <div
              v-for="(item, idx) in order.items" :key="idx"
              class="flex items-center gap-4 px-5 py-3"
              @click="$router.push(`/orders/${order.id}`)"
            >
              <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-2xl">
                <img v-if="item.sku_image" :src="item.sku_image" class="h-full w-full object-cover" alt="" />
                <span v-else>📦</span>
              </div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-slate-700">{{ item.product_title }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ Object.values(item.sku_specs).join(' / ') || '默认规格' }}</p>
              </div>
              <div class="text-right text-sm">
                <p class="text-slate-500">¥{{ item.price }}</p>
                <p class="text-xs text-slate-400">× {{ item.quantity }}</p>
              </div>
            </div>

            <div class="flex items-center justify-between border-t border-slate-50 px-5 py-3">
              <span class="text-xs text-slate-400">{{ order.created_at }}</span>
              <div class="flex items-center gap-3">
                <span class="text-sm text-slate-600">
                  共 {{ order.item_count }} 件，实付 <b class="text-[#ff4d4f]">¥{{ order.pay_amount }}</b>
                </span>
                <button
                  v-if="order.status === 'pending_payment'"
                  class="rounded-full bg-[#1677ff] px-4 py-1.5 text-xs text-white hover:bg-[#4096ff]"
                  @click.stop="$router.push(`/orders/${order.id}/pay`)"
                >去支付</button>
                <button
                  v-if="order.status === 'pending_payment'"
                  class="rounded-full border border-slate-200 px-4 py-1.5 text-xs text-slate-500 hover:border-red-300 hover:text-red-500"
                  @click.stop="doCancel(order)"
                >取消订单</button>
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
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
