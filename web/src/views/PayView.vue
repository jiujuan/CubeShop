<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { CircleCheckBig, CreditCard, Clock3, QrCode } from 'lucide-vue-next'
import { getOrder, type OrderDetail } from '@/api/order'
import { createPayment, getPaymentStatus, sandboxPay, type PayChannel, type PaymentStatus } from '@/api/payment'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 支付页（Roadmap P5）：调起支付、轮询支付结果
 * 沙箱模式：「模拟支付成功」按钮模拟渠道回调；生产环境由渠道 SDK 拉起支付后回跳/轮询
 */
const route = useRoute()
const auth = useAuthStore()

const loading = ref(true)
const tip = ref('')
const order = ref<OrderDetail | null>(null)
const channel = ref<PayChannel>('wechat')
const paying = ref(false)
const paymentNo = ref('')
const paymentStatus = ref<PaymentStatus>('pending')
let pollTimer: number | null = null

const statusDone = () => paymentStatus.value === 'success'

async function load() {
  const { data } = await getOrder(Number(route.params.id))
  order.value = data.data
  if (order.value.status === 'paid' || order.value.status === 'shipped' || order.value.status === 'completed') {
    paymentStatus.value = 'success'
  }
}

onMounted(async () => {
  if (!auth.token) {
    loading.value = false
    return
  }
  try {
    await load()
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  if (pollTimer) window.clearInterval(pollTimer)
})

async function pay() {
  if (!order.value || paying.value) return
  tip.value = ''
  paying.value = true
  try {
    const { data } = await createPayment(order.value.order_no, channel.value)
    paymentNo.value = data.data.payment_no
    startPolling()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '发起支付失败'
  } finally {
    paying.value = false
  }
}

/** 每 2 秒轮询支付状态，直到成功或超时（150 秒） */
function startPolling() {
  if (pollTimer) window.clearInterval(pollTimer)
  let elapsed = 0
  pollTimer = window.setInterval(async () => {
    elapsed += 2
    try {
      const { data } = await getPaymentStatus(paymentNo.value)
      paymentStatus.value = data.data.status
      if (data.data.status !== 'pending') {
        window.clearInterval(pollTimer!)
        pollTimer = null
        await load()
      }
    } catch {
      // 轮询失败忽略，下次重试
    }
    if (elapsed >= 150 && pollTimer) {
      window.clearInterval(pollTimer)
      pollTimer = null
      tip.value = '支付结果确认超时，请稍后在订单中查看'
    }
  }, 2000)
}

/** 沙箱：模拟渠道回调支付成功 */
async function mockPay() {
  if (!paymentNo.value) return
  tip.value = ''
  try {
    await sandboxPay(paymentNo.value, 'success')
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '模拟支付失败'
  }
}
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-2xl flex-1 px-6 py-12">
      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <p class="mb-4">请先登录</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push({ path: '/login', query: { redirect: route.fullPath } })">去登录</button>
      </div>

      <template v-else-if="order">
        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

        <!-- 支付成功 -->
        <template v-if="statusDone">
          <div class="mb-8 flex flex-col items-center text-center">
            <CircleCheckBig class="mb-3 h-12 w-12 text-[#52c41a]" />
            <h1 class="text-xl font-bold text-slate-800">支付成功</h1>
            <p class="mt-1 text-sm text-slate-500">订单 {{ order.order_no }} 已完成支付</p>
          </div>
          <div class="flex justify-center gap-4">
            <button class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]" @click="$router.push('/orders')">我的订单</button>
            <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]" @click="$router.push(`/orders/${order.id}`)">查看订单详情</button>
          </div>
        </template>

        <!-- 待支付 / 收银台 -->
        <template v-else>
          <div class="mb-8 flex flex-col items-center text-center">
            <CreditCard class="mb-3 h-10 w-10 text-[#1677ff]" />
            <h1 class="text-xl font-bold text-slate-800">收银台</h1>
            <p class="mt-1 text-sm text-slate-500">订单号：{{ order.order_no }}</p>
          </div>

          <section class="mb-8 rounded-xl border border-slate-100 bg-white p-6">
            <div class="mb-4 flex justify-between text-sm">
              <span class="text-slate-600">应付金额</span>
              <span class="text-2xl font-bold text-[#ff4d4f]">¥{{ order.pay_amount }}</span>
            </div>

            <!-- 选择支付渠道 -->
            <div class="mb-4 grid grid-cols-2 gap-3">
              <button
                class="flex items-center justify-center gap-2 rounded-lg border-2 py-3 text-sm font-medium transition-colors"
                :class="channel === 'wechat' ? 'border-[#1677ff] bg-[#f0f7ff] text-[#1677ff]' : 'border-slate-200 text-slate-600'"
                @click="channel = 'wechat'"
              >
                <span class="flex h-5 w-5 items-center justify-center rounded bg-[#52c41a] text-[10px] text-white">微</span>
                微信支付
              </button>
              <button
                class="flex items-center justify-center gap-2 rounded-lg border-2 py-3 text-sm font-medium transition-colors"
                :class="channel === 'alipay' ? 'border-[#1677ff] bg-[#f0f7ff] text-[#1677ff]' : 'border-slate-200 text-slate-600'"
                @click="channel = 'alipay'"
              >
                <span class="flex h-5 w-5 items-center justify-center rounded bg-[#1677ff] text-[10px] text-white">支</span>
                支付宝
              </button>
            </div>

            <!-- 沙箱二维码占位 -->
            <div v-if="paymentNo" class="mb-4 flex flex-col items-center gap-2 rounded-lg bg-slate-50 py-6">
              <QrCode class="h-24 w-24 text-slate-300" />
              <p class="text-xs text-slate-400">沙箱环境：二维码占位（生产环境为渠道真实二维码）</p>
              <button class="rounded-full bg-[#52c41a] px-5 py-2 text-sm font-medium text-white hover:bg-[#73d13d]" @click="mockPay">模拟支付成功（沙箱）</button>
              <p class="flex items-center gap-1 text-xs text-slate-400"><Clock3 class="h-3.5 w-3.5" /> 已发起支付，正在确认支付结果…</p>
            </div>

            <button
              v-if="!paymentNo"
              class="w-full rounded-full bg-[#1677ff] py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-50"
              :disabled="paying"
              @click="pay"
            >{{ paying ? '发起支付中…' : `立即支付 ¥${order.pay_amount}` }}</button>
          </section>
        </template>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
