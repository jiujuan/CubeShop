<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Ticket } from 'lucide-vue-next'
import { getMyCoupons, type UserCouponItem, type UserCouponStatus } from '@/api/coupon'
import { couponConditionText, couponValueText } from '@/utils/coupon'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import Pagination from '@/components/Pagination.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 我的券（V1.1 二期 F06 / T-038）
 *
 * 全部 / 未使用 / 已使用 / 已过期 Tab + 分页。
 * 未使用且临近过期（≤3 天）标红提示；已使用展示关联订单入口。
 */
const router = useRouter()
const auth = useAuthStore()

const list = ref<UserCouponItem[]>([])
const pagination = ref({ page: 1, page_size: 10, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')
const tab = ref<UserCouponStatus | 'all'>('all')

const tabs: Array<{ key: UserCouponStatus | 'all'; label: string }> = [
  { key: 'all', label: '全部' },
  { key: 'unused', label: '未使用' },
  { key: 'used', label: '已使用' },
  { key: 'expired', label: '已过期' },
]

const statusParam = computed<UserCouponStatus | null>(() => (tab.value === 'all' ? null : tab.value))

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getMyCoupons({
      status: statusParam.value,
      page: pagination.value.page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (auth.token) await load()
  else loading.value = false
})

function switchTab(k: UserCouponStatus | 'all') {
  if (tab.value === k) return
  tab.value = k
  pagination.value.page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > pagination.value.total_pages || p === pagination.value.page) return
  pagination.value.page = p
  load()
}

/** 券是否「生效中」（未使用且未过期）——决定面额底色 */
function isActive(c: UserCouponItem): boolean {
  return c.status === 'unused'
}

function gotoOrder(c: UserCouponItem) {
  if (c.used_order_id) router.push(`/orders/${c.used_order_id}`)
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-6 py-8">
      <h1 class="mb-5 flex items-center gap-2 text-xl font-bold text-slate-800">
        <Ticket class="h-5 w-5 text-[#1677ff]" /> 我的优惠券
      </h1>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <p class="mb-4">请先登录</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="router.push('/login')">去登录</button>
      </div>

      <template v-else>
        <!-- Tab -->
        <div class="mb-4 flex gap-2" data-testid="mycoupon-tabs">
          <button
            v-for="t in tabs" :key="t.key"
            class="rounded-full px-4 py-1.5 text-sm transition-colors"
            :class="tab === t.key ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-500 hover:text-[#1677ff]'"
            :data-testid="`mycoupon-tab-${t.key}`"
            @click="switchTab(t.key)"
          >{{ t.label }}</button>
        </div>

        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

        <!-- 空态 -->
        <div v-if="!list.length" class="flex flex-col items-center rounded-xl bg-white py-20 text-slate-400">
          <Ticket class="mb-3 h-10 w-10 text-slate-200" />
          <p class="text-sm" data-testid="mycoupon-empty">暂无优惠券</p>
        </div>

        <!-- 券列表 -->
        <div v-else class="space-y-3">
          <div
            v-for="c in list" :key="c.id"
            class="flex items-stretch overflow-hidden rounded-xl bg-white shadow-sm"
            :data-testid="`mycoupon-card-${c.id}`"
          >
            <!-- 左侧面额（券形：虚线分界 + 上下缺口；失效券置灰） -->
            <div
              class="relative flex w-28 shrink-0 flex-col items-center justify-center border-r border-dashed border-white/50 text-white"
              :class="isActive(c) ? 'bg-gradient-to-br from-[#1677ff] to-[#4096ff]' : 'bg-slate-300'"
            >
              <span class="absolute -right-2 -top-2 h-4 w-4 rounded-full bg-slate-50"></span>
              <span class="absolute -bottom-2 -right-2 h-4 w-4 rounded-full bg-slate-50"></span>
              <span class="text-2xl font-bold leading-none">{{ couponValueText(c) }}</span>
              <span class="mt-1 text-[11px] opacity-90">{{ couponConditionText(c) }}</span>
            </div>

            <!-- 右侧信息 -->
            <div class="flex min-w-0 flex-1 items-center justify-between gap-3 px-4 py-3">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold" :class="isActive(c) ? 'text-slate-800' : 'text-slate-400'">{{ c.name }}</p>
                <p class="mt-0.5 truncate text-xs text-slate-400">{{ c.scope_label }}</p>
                <p
                  class="mt-1 text-[11px]"
                  :class="c.near_expiry && isActive(c) ? 'font-medium text-[#ff4d4f]' : 'text-slate-400'"
                  :data-testid="`mycoupon-expire-${c.id}`"
                >
                  <template v-if="c.near_expiry && isActive(c)">即将过期 · {{ c.expire_at }}</template>
                  <template v-else>
                    {{ isActive(c) ? `有效期至 ${c.expire_at}` : c.expire_at }}
                  </template>
                </p>
              </div>

              <div class="flex shrink-0 flex-col items-end gap-1.5">
                <span class="text-xs" :class="isActive(c) ? 'text-[#1677ff]' : 'text-slate-400'" :data-testid="`mycoupon-status-${c.id}`">{{ c.status_label }}</span>
                <button
                  v-if="c.status === 'used' && c.used_order_id"
                  class="rounded border border-slate-200 px-2 py-0.5 text-[11px] text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff]"
                  :data-testid="`mycoupon-order-${c.id}`"
                  @click="gotoOrder(c)"
                >查看订单</button>
                <button
                  v-else-if="isActive(c)"
                  class="rounded-full bg-[#1677ff] px-3 py-0.5 text-[11px] text-white hover:bg-[#4096ff]"
                  :data-testid="`mycoupon-use-${c.id}`"
                  @click="router.push('/')"
                >去使用</button>
              </div>
            </div>
          </div>
        </div>

        <!-- 分页（统一分页条，风格与后台一致） -->
        <Pagination v-if="pagination.total_pages > 1" :pagination="pagination" @change="goPage" />
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
