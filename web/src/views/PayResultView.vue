<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleCheckBig, CircleX, Clock3, QrCode, CreditCard } from 'lucide-vue-next'
import {
  getPaymentStatus,
  syncPayment,
  sandboxPay,
  type PayParams,
  type PaymentStatus,
} from '@/api/payment'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'
import QRCode from 'qrcode'

/**
 * 支付结果页（Roadmap P5）：四态渲染与轮询兜底。
 * 成功（绿）/ 待核账（蓝）/ 失败（红）/ 处理中（轮询，超 15s 触发主动查单）。
 * 充值场景复用：成功卡文案与按钮随 biz_type 切换。
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const paymentNo = String(route.params.payment_no)
const pp = ref<PayParams | null>(decodePp())

const status = ref<PaymentStatus>('pending')
const bizType = ref<'order' | 'recharge'>('order')
const orderId = ref<number | null>(null)
const amount = ref('')
const channel = ref('')
const reviewRemark = ref<string | null>(null)

const qrUrl = ref('')
const tip = ref('')
const syncing = ref(false)
let pollTimer: number | null = null
let elapsed = 0

const isSuccess = computed(() => status.value === 'success')
const isReviewing = computed(() => status.value === 'reviewing')
const isFailed = computed(() => status.value === 'failed' || status.value === 'closed')
const isPending = computed(() => status.value === 'pending')

function decodePp(): PayParams | null {
  const raw = route.query.pp
  if (typeof raw !== 'string' || !raw) return null
  try {
    return JSON.parse(decodeURIComponent(raw)) as PayParams
  } catch {
    return null
  }
}

async function renderPayParams() {
  if (pp.value?.type === 'qrcode' && pp.value.code_url) {
    try {
      qrUrl.value = await QRCode.toDataURL(pp.value.code_url, { width: 200, margin: 1 })
    } catch {
      qrUrl.value = ''
    }
  }
}

async function pollOnce() {
  try {
    const { data } = await getPaymentStatus(paymentNo)
    const d = data.data
    status.value = d.status
    bizType.value = d.biz_type
    orderId.value = d.order_id ?? null
    amount.value = d.amount
    channel.value = d.channel
    reviewRemark.value = d.review_remark ?? null

    if (!isPending.value) {
      stopPolling()
    }
  } catch {
    // 轮询失败忽略，下次重试
  }
}

function startPolling() {
  if (pollTimer) window.clearInterval(pollTimer)
  // 立即拉一次
  pollOnce()
  pollTimer = window.setInterval(async () => {
    elapsed += 2
    await pollOnce()
    if (elapsed >= 150 && isPending.value && pollTimer) {
      stopPolling()
      tip.value = '支付结果确认超时，请稍后在「我的订单」中查看'
    }
  }, 2000)
}

function stopPolling() {
  if (pollTimer) {
    window.clearInterval(pollTimer)
    pollTimer = null
  }
}

async function syncNow() {
  syncing.value = true
  tip.value = ''
  try {
    await syncPayment(paymentNo)
    await pollOnce()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '查单失败'
  } finally {
    syncing.value = false
  }
}

async function mockPay() {
  tip.value = ''
  try {
    await sandboxPay(paymentNo, 'success')
    await pollOnce()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '模拟支付失败'
  }
}

onMounted(async () => {
  if (!auth.token) {
    router.replace({ path: '/login', query: { redirect: route.fullPath } })
    return
  }
  await renderPayParams()
  if (pp.value?.type === 'direct') {
    // 余额支付已同步完成，直接拉一次状态即可
    await pollOnce()
  } else {
    startPolling()
  }
})

onBeforeUnmount(() => stopPolling())
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-2xl flex-1 px-6 py-12">
      <div class="mb-8 flex flex-col items-center text-center">
        <!-- 成功 -->
        <template v-if="isSuccess">
          <CircleCheckBig class="mb-3 h-12 w-12 text-[#52c41a]" />
          <h1 class="text-xl font-bold text-slate-800">
            {{ bizType === 'recharge' ? '充值成功' : '支付成功' }}
          </h1>
          <p class="mt-1 text-sm text-slate-500">
            {{ bizType === 'recharge' ? `余额已到账 ¥${amount}` : `订单 ${orderId ? '' : ''} 已完成支付` }}
          </p>
        </template>

        <!-- 待核账 -->
        <template v-else-if="isReviewing">
          <Clock3 class="mb-3 h-12 w-12 text-[#1677ff]" />
          <h1 class="text-xl font-bold text-slate-800">待核账</h1>
          <p class="mt-1 max-w-md text-sm text-slate-500">
            我们已收到您的线下转账凭证，预计 1 个工作日内完成核账。核账通过后余额/订单将自动更新。
          </p>
          <p v-if="reviewRemark" class="mt-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">
            核账备注：{{ reviewRemark }}
          </p>
        </template>

        <!-- 失败 / 关闭 -->
        <template v-else-if="isFailed">
          <CircleX class="mb-3 h-12 w-12 text-[#ff4d4f]" />
          <h1 class="text-xl font-bold text-slate-800">支付失败</h1>
          <p class="mt-1 text-sm text-slate-500">订单支付未完成，您可以重新发起支付</p>
        </template>

        <!-- 处理中 -->
        <template v-else>
          <CreditCard class="mb-3 h-10 w-10 animate-pulse text-[#1677ff]" />
          <h1 class="text-xl font-bold text-slate-800">支付处理中</h1>
          <p class="mt-1 text-sm text-slate-500">正在确认支付结果…</p>
        </template>
      </div>

      <section class="mb-8 rounded-xl border border-slate-100 bg-white p-6">
        <p v-if="tip" class="mb-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-600">{{ tip }}</p>

        <!-- 微信二维码 -->
        <div v-if="isPending && pp?.type === 'qrcode'" class="flex flex-col items-center gap-3 py-2">
          <img v-if="qrUrl" :src="qrUrl" class="h-48 w-48 rounded" alt="支付二维码" />
          <QrCode v-else class="h-24 w-24 text-slate-300" />
          <p class="text-xs text-slate-500">请使用微信扫码支付</p>
          <button
            class="rounded-full border border-slate-200 px-5 py-1.5 text-xs text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]"
            :disabled="syncing"
            @click="syncNow"
          >我已支付，刷新状态</button>
        </div>

        <!-- 模拟支付（沙箱） -->
        <div v-else-if="isPending && pp?.type === 'mock'" class="flex flex-col items-center gap-3 py-2">
          <p class="text-xs text-slate-400">沙箱环境：不会产生真实扣款</p>
          <button
            class="rounded-full bg-[#52c41a] px-5 py-2 text-sm font-medium text-white hover:bg-[#73d13d]"
            @click="mockPay"
          >模拟支付成功（沙箱）</button>
          <p class="flex items-center gap-1 text-xs text-slate-400">已发起支付，正在确认支付结果…</p>
        </div>

        <!-- 线下凭证回执 -->
        <div v-else-if="isReviewing && pp?.type === 'voucher' && pp.receipt" class="space-y-1 rounded-lg bg-slate-50 p-4 text-xs text-slate-600">
          <p class="font-medium text-slate-700">收款账户</p>
          <p v-if="pp.receipt.bank_name">开户行：{{ pp.receipt.bank_name }}</p>
          <p v-if="pp.receipt.account_name">户名：{{ pp.receipt.account_name }}</p>
          <p v-if="pp.receipt.account_no">账号：{{ pp.receipt.account_no }}</p>
          <img v-if="pp.receipt.qrcode_url" :src="pp.receipt.qrcode_url" class="mt-2 h-28 w-28 rounded" alt="收款码" />
        </div>

        <!-- 处理中通用提示 -->
        <div v-else-if="isPending" class="flex flex-col items-center gap-3 py-2">
          <LoadingSpinner />
          <p v-if="elapsed >= 15" class="text-xs text-slate-400">
            支付结果迟迟未更新？
            <button class="text-[#1677ff]" :disabled="syncing" @click="syncNow">主动查单</button>
          </p>
        </div>

        <!-- 操作按钮 -->
        <div class="mt-4 flex justify-center gap-4">
          <template v-if="isSuccess">
            <button v-if="bizType === 'order' && orderId" class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]" @click="router.push(`/orders/${orderId}`)">查看订单详情</button>
            <button v-else class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]" @click="router.push('/account')">查看余额</button>
            <button class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]" @click="router.push('/orders')">{{ bizType === 'recharge' ? '返回账户中心' : '我的订单' }}</button>
          </template>

          <template v-else-if="isFailed">
            <button v-if="orderId" class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]" @click="router.push(`/orders/${orderId}/pay`)">重新支付</button>
            <button class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]" @click="router.push('/')">返回首页</button>
          </template>

          <template v-else-if="isReviewing">
            <button class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]" @click="router.push('/orders')">查看我的订单</button>
          </template>
        </div>
      </section>
    </main>

    <ShopFooter />
  </div>
</template>
