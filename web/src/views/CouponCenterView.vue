<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Ticket } from 'lucide-vue-next'
import {
  getCouponCenter, receiveCoupon,
  type ReceivableCoupon,
} from '@/api/coupon'
import { couponConditionText, couponValueText } from '@/utils/coupon'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 领券中心（V1.1 二期 F06 / T-038）
 *
 * 券卡片三态：
 *  - 可领（remaining>0 且 can_receive）：「立即领取」按钮
 *  - 已领取（received_by_me>0 且不能继续领）：「已领取」禁用
 *  - 已抢光（remaining<=0）：「已抢光」禁用
 * 领取成功后刷新列表以同步状态（can_receive/remaining/received_by_me）。
 */
const router = useRouter()
const auth = useAuthStore()

const list = ref<ReceivableCoupon[]>([])
const loading = ref(true)
const tip = ref('')
const flash = ref<{ type: 'ok' | 'err'; text: string } | null>(null)
let flashTimer: ReturnType<typeof setTimeout> | undefined

const receivingId = ref<number | null>(null)

const hasAuth = computed(() => !!auth.token)

function showToast(type: 'ok' | 'err', text: string) {
  flash.value = { type, text }
  clearTimeout(flashTimer)
  flashTimer = setTimeout(() => (flash.value = null), 1800)
}

/** 卡片状态：receivable / received / sold_out */
function cardState(c: ReceivableCoupon): 'receivable' | 'received' | 'sold_out' {
  if (c.remaining <= 0) return 'sold_out'
  if (c.received_by_me > 0 && !c.can_receive) return 'received'
  return 'receivable'
}

function validText(c: ReceivableCoupon): string {
  if (c.valid_type === 'relative') {
    return `领取后 ${c.valid_days ?? 0} 天有效`
  }
  return c.valid_to ? `有效期至 ${c.valid_to}` : '长期有效'
}

/** 已领进度百分比（0~100） */
function progressPct(c: ReceivableCoupon): number {
  if (c.total_count <= 0) return 0
  return Math.min(100, Math.round(((c.total_count - c.remaining) / c.total_count) * 100))
}

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getCouponCenter()
    list.value = data.data.list
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

async function doReceive(c: ReceivableCoupon) {
  if (receivingId.value) return
  if (!hasAuth.value) {
    router.push({ path: '/login', query: { redirect: '/coupons/center' } })
    return
  }
  tip.value = ''
  receivingId.value = c.id
  try {
    await receiveCoupon(c.id)
    showToast('ok', '领取成功')
    await load() // 同步 can_receive / remaining / received_by_me
  } catch (e) {
    showToast('err', e instanceof Error ? e.message : '领取失败')
  } finally {
    receivingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-6 py-8">
      <h1 class="mb-1 flex items-center gap-2 text-xl font-bold text-slate-800">
        <Ticket class="h-5 w-5 text-[#1677ff]" /> 领券中心
      </h1>
      <p class="mb-5 text-xs text-slate-400">好券天天领，下单更优惠</p>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <template v-else>
        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

        <div v-if="!hasAuth" class="mb-4 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-600" data-testid="center-login-tip">
          登录后可领取优惠券
        </div>

        <!-- 空态 -->
        <div v-if="!list.length" class="flex flex-col items-center rounded-xl bg-white py-20 text-slate-400">
          <Ticket class="mb-3 h-10 w-10 text-slate-200" />
          <p class="text-sm" data-testid="center-empty">暂无可领优惠券</p>
        </div>

        <!-- 券卡片列表 -->
        <div v-else class="space-y-3">
          <div
            v-for="c in list" :key="c.id"
            class="flex items-stretch overflow-hidden rounded-xl bg-white shadow-sm"
            :data-testid="`coupon-card-${c.id}`"
          >
            <!-- 左侧面额（券形：虚线分界 + 上下缺口） -->
            <div
              class="relative flex w-28 shrink-0 flex-col items-center justify-center border-r border-dashed border-white/50 bg-gradient-to-br from-[#ff7a45] to-[#ff4d4f] text-white"
              :data-testid="`coupon-value-${c.id}`"
            >
              <span class="absolute -right-2 -top-2 h-4 w-4 rounded-full bg-slate-50"></span>
              <span class="absolute -bottom-2 -right-2 h-4 w-4 rounded-full bg-slate-50"></span>
              <span class="text-2xl font-bold leading-none">{{ couponValueText(c) }}</span>
              <span class="mt-1 text-[11px] opacity-90">{{ couponConditionText(c) }}</span>
            </div>

            <!-- 右侧信息 -->
            <div class="flex min-w-0 flex-1 items-center justify-between gap-3 px-4 py-3">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-800">{{ c.name }}</p>
                <p class="mt-0.5 truncate text-xs text-slate-400">
                  {{ c.scope_label }} · {{ validText(c) }}
                </p>
                <!-- 剩余量进度 -->
                <div class="mt-1.5 flex items-center gap-1.5">
                  <div class="h-1 w-20 overflow-hidden rounded-full bg-slate-100" :data-testid="`coupon-progress-${c.id}`">
                    <div
                      class="h-full rounded-full bg-gradient-to-r from-[#ff7a45] to-[#ff4d4f]"
                      :style="{ width: `${progressPct(c)}%` }"
                    ></div>
                  </div>
                  <span class="text-[11px] text-slate-400">已领 {{ c.total_count - c.remaining }}/{{ c.total_count }}</span>
                </div>
              </div>

              <!-- 领取按钮（三态） -->
              <button
                v-if="cardState(c) === 'receivable'"
                class="shrink-0 rounded-full bg-[#1677ff] px-4 py-1.5 text-xs text-white transition-colors hover:bg-[#4096ff] disabled:opacity-60"
                :disabled="receivingId === c.id"
                :data-testid="`coupon-receive-${c.id}`"
                @click="doReceive(c)"
              >{{ receivingId === c.id ? '领取中…' : '立即领取' }}</button>

              <span
                v-else-if="cardState(c) === 'received'"
                class="shrink-0 rounded-full border border-slate-200 px-4 py-1.5 text-xs text-slate-400"
                :data-testid="`coupon-received-${c.id}`"
              >已领取</span>

              <span
                v-else
                class="shrink-0 rounded-full border border-slate-200 px-4 py-1.5 text-xs text-slate-400"
                :data-testid="`coupon-soldout-${c.id}`"
              >已抢光</span>
            </div>
          </div>
        </div>
      </template>
    </main>

    <ShopFooter />

    <!-- 领取轻提示 -->
    <transition
      enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-300" leave-to-class="opacity-0"
    >
      <div
        v-if="flash"
        class="fixed bottom-10 left-1/2 z-50 -translate-x-1/2 rounded-full px-4 py-2 text-sm text-white shadow-lg"
        :class="flash.type === 'ok' ? 'bg-slate-800/90' : 'bg-[#ff4d4f]/95'"
        data-testid="center-flash"
      >{{ flash.text }}</div>
    </transition>
  </div>
</template>
