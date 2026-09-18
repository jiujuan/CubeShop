<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { CircleCheckBig, CreditCard, Clock3, Copy } from 'lucide-vue-next'
import { getOrder, type OrderDetail } from '@/api/order'
import {
  getChannels,
  createPayment,
  uploadVoucher,
  type CashierChannel,
  type PayChannel,
  type PayParams,
} from '@/api/payment'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 收银台（Roadmap P5）：四渠道渲染，按 GET /payments/channels 驱动。
 * 微信 Native 二维码 / 支付宝表单跳转 / 余额直接扣款 / 线下转账凭证，
 * 调起后委托结果页 /pay/result/:payment_no 渲染与轮询。
 */
const route = useRoute()
const auth = useAuthStore()

const ORDER_TIMEOUT_MINUTES = 30

const loading = ref(true)
const tip = ref('')
const order = ref<OrderDetail | null>(null)
const channels = ref<CashierChannel[]>([])
const selectedChannel = ref<PayChannel>('wechat')
const paying = ref(false)

// 线下转账表单
const payerName = ref('')
const payerAccount = ref('')
const transferNo = ref('')
const transferredAt = ref('')
const voucherFile = ref<File | null>(null)
const voucherUrl = ref('')

// 支付宝表单跳转（生产环境）
const formHtml = ref('')

// 倒计时
const countdown = ref('')
let timer: number | null = null

const statusDone = computed(
  () => order.value && ['paid', 'pending_ship', 'shipped', 'completed'].includes(order.value.status),
)
const expired = ref(false)

const selectedChannelMeta = computed(
  () => channels.value.find((c) => c.code === selectedChannel.value) ?? null,
)
const balanceInsufficient = computed(() => {
  if (selectedChannel.value !== 'balance' || !selectedChannelMeta.value?.balance) return false
  return Number(selectedChannelMeta.value.balance) < Number(order.value?.pay_amount ?? 0)
})
const canPay = computed(
  () =>
    !!order.value &&
    order.value.status === 'pending_payment' &&
    !expired.value &&
    !balanceInsufficient.value &&
    !paying.value,
)

async function load() {
  const { data } = await getOrder(route.params.id as string)
  order.value = data.data
  if (order.value.status === 'pending_payment') {
    startCountdown()
  }
}

function startCountdown() {
  if (!order.value?.created_at) return
  const deadline = new Date(order.value.created_at).getTime() + ORDER_TIMEOUT_MINUTES * 60 * 1000
  const tick = () => {
    const left = Math.max(0, deadline - Date.now())
    const m = Math.floor(left / 60000)
    const s = Math.floor((left % 60000) / 1000)
    countdown.value = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
    if (left <= 0) {
      expired.value = true
      if (timer) window.clearInterval(timer)
    }
  }
  tick()
  timer = window.setInterval(tick, 1000)
}

function selectChannel(code: PayChannel) {
  selectedChannel.value = code
  tip.value = ''
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    tip.value = '已复制到剪贴板'
  } catch {
    tip.value = '复制失败，请手动选择'
  }
}

async function onVoucherChange(e: Event) {
  const input = e.target as HTMLInputElement
  voucherFile.value = input.files?.[0] ?? null
  voucherUrl.value = ''
}

async function pay() {
  if (!order.value || !canPay.value) return
  tip.value = ''
  paying.value = true
  try {
    // 线下转账：先上传凭证（若有文件）再带 extra 发起
    let extra: Record<string, unknown> | undefined
    if (selectedChannel.value === 'balance' && balanceInsufficient.value) {
      tip.value = '余额不足，请更换支付方式'
      return
    }
    if (selectedChannel.value === 'offline') {
      if (!payerName.value || !transferNo.value || !voucherUrl.value) {
        tip.value = '请填写付款人、转账流水号并上传凭证'
        return
      }
      extra = {
        payer_name: payerName.value,
        payer_account: payerAccount.value,
        transfer_no: transferNo.value,
        transferred_at: transferredAt.value || undefined,
        voucher_url: voucherUrl.value,
      }
    }

    if (voucherFile.value && selectedChannel.value === 'offline' && !voucherUrl.value) {
      const up = await uploadVoucher(voucherFile.value)
      voucherUrl.value = up.data.data.url
      extra = { ...(extra ?? {}), voucher_url: voucherUrl.value }
    }

    const { data } = await createPayment(order.value.order_no, selectedChannel.value, extra)

    const pp = data.data.pay_params as PayParams
    // 支付宝生产环境：渲染表单自动提交 / 直接跳转
    if (pp.type === 'form') {
      formHtml.value = pp.form_html
      nextTick(() => {
        const el = document.getElementById('alipay-pay-form')
        el?.querySelector('form')?.submit()
      })
      return
    }
    if (pp.type === 'redirect') {
      window.location.href = pp.pay_url
      return
    }

    // 其余类型（二维码 / 模拟 / 凭证 / 直接）交给结果页渲染与轮询
    const query = { pp: encodeURIComponent(JSON.stringify(pp)) }
    window.location.href = `/pay/result/${data.data.payment_no}?pp=${query.pp}`
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '发起支付失败'
  } finally {
    paying.value = false
  }
}

onMounted(async () => {
  if (!auth.token) {
    loading.value = false
    return
  }
  try {
    const [{ data: ch }] = await Promise.all([getChannels('order')])
    channels.value = ch.data.channels
    const def = ch.data.default_channel
    selectedChannel.value =
      channels.value.some((c) => c.code === def) ? def : (channels.value[0]?.code ?? 'wechat')
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '加载收银台失败'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  if (timer) window.clearInterval(timer)
})
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

        <!-- 支付成功（订单已支付） -->
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

        <!-- 收银台 -->
        <template v-else>
          <div class="mb-8 flex flex-col items-center text-center">
            <CreditCard class="mb-3 h-10 w-10 text-[#1677ff]" />
            <h1 class="text-xl font-bold text-slate-800">收银台</h1>
            <p class="mt-1 text-sm text-slate-500">订单号：{{ order.order_no }}</p>
            <p v-if="order.status === 'pending_payment'" class="mt-1 flex items-center gap-1 text-xs text-slate-400">
              <Clock3 class="h-3.5 w-3.5" /> 剩余支付时间 {{ countdown }}
            </p>
          </div>

          <section class="mb-8 rounded-xl border border-slate-100 bg-white p-6">
            <div class="mb-4 flex justify-between text-sm">
              <span class="text-slate-600">应付金额</span>
              <span class="text-2xl font-bold text-[#ff4d4f]">¥{{ order.pay_amount }}</span>
            </div>

            <!-- 渠道列表（由后端配置驱动） -->
            <div class="mb-4 grid grid-cols-2 gap-3">
              <button
                v-for="c in channels"
                :key="c.code"
                class="flex items-center justify-between gap-2 rounded-lg border-2 px-3 py-3 text-sm font-medium transition-colors"
                :class="[
                  selectedChannel === c.code ? 'border-[#1677ff] bg-[#f0f7ff] text-[#1677ff]' : 'border-slate-200 text-slate-600',
                  (c.code === 'balance' && balanceInsufficient) ? 'cursor-not-allowed opacity-60' : '',
                ]"
                :disabled="c.code === 'balance' && balanceInsufficient"
                @click="selectChannel(c.code)"
              >
                <span class="flex items-center gap-2">
                  <span
                    class="flex h-5 w-5 items-center justify-center rounded text-[10px] text-white"
                    :class="c.code === 'wechat' ? 'bg-[#52c41a]' : c.code === 'alipay' ? 'bg-[#1677ff]' : c.code === 'balance' ? 'bg-[#fa8c16]' : c.code === 'offline' ? 'bg-[#722ed1]' : 'bg-slate-400'"
                  >{{ c.code === 'wechat' ? '微' : c.code === 'alipay' ? '支' : c.code === 'balance' ? '余' : c.code === 'offline' ? '账' : '测' }}</span>
                  {{ c.name }}
                </span>
                <span v-if="c.code === 'balance'" class="text-xs text-slate-400">¥{{ c.balance }}</span>
                <span v-else-if="c.sandbox" class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-500">沙箱</span>
              </button>
            </div>

            <p v-if="balanceInsufficient" class="mb-3 text-xs text-[#ff4d4f]">
              余额不足，<button class="text-[#1677ff] underline" data-testid="go-recharge" @click="$router.push('/balance/recharge')">去充值</button> 或更换支付方式
            </p>

            <!-- 线下转账：收款账户 + 凭证表单 -->
            <div v-if="selectedChannel === 'offline'" class="mb-4 space-y-3 rounded-lg bg-slate-50 p-4">
              <div v-if="selectedChannelMeta?.receipt" class="text-xs text-slate-600">
                <p class="mb-1 font-medium text-slate-700">收款账户</p>
                <p v-if="selectedChannelMeta.receipt.bank_name">开户行：{{ selectedChannelMeta.receipt.bank_name }}</p>
                <p v-if="selectedChannelMeta.receipt.account_name">户名：{{ selectedChannelMeta.receipt.account_name }}</p>
                <p v-if="selectedChannelMeta.receipt.account_no" class="flex items-center gap-1">
                  账号：{{ selectedChannelMeta.receipt.account_no }}
                  <Copy class="h-3.5 w-3.5 cursor-pointer text-slate-400" @click="copy(selectedChannelMeta.receipt.account_no)" />
                </p>
                <img v-if="selectedChannelMeta.receipt.qrcode_url" :src="selectedChannelMeta.receipt.qrcode_url" class="mt-2 h-28 w-28 rounded" alt="收款码" />
              </div>
              <div class="grid grid-cols-2 gap-2">
                <input v-model="payerName" class="rounded border border-slate-200 px-2 py-1.5 text-xs" placeholder="付款人姓名" />
                <input v-model="payerAccount" class="rounded border border-slate-200 px-2 py-1.5 text-xs" placeholder="付款账号/银行" />
                <input v-model="transferNo" class="rounded border border-slate-200 px-2 py-1.5 text-xs" placeholder="银行流水号" />
                <input v-model="transferredAt" class="rounded border border-slate-200 px-2 py-1.5 text-xs" placeholder="转账时间（可选）" />
              </div>
              <div class="flex items-center gap-2">
                <input type="file" accept="image/jpg,image/jpeg,image/png,image/webp" class="text-xs" @change="onVoucherChange" />
                <span v-if="voucherUrl" class="text-xs text-[#52c41a]">凭证已就绪</span>
              </div>
            </div>

            <!-- 支付宝表单跳转容器（生产环境） -->
            <div v-if="formHtml" v-html="formHtml" id="alipay-pay-form" class="mb-4"></div>
            <p v-if="formHtml" class="mb-4 flex items-center gap-1 text-xs text-slate-400"><Clock3 class="h-3.5 w-3.5" /> 正在跳转到支付宝收银台…</p>

            <button
              class="w-full rounded-full bg-[#1677ff] py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-50"
              :disabled="!canPay"
              @click="pay"
            >{{ paying ? '发起支付中…' : `立即支付 ¥${order.pay_amount}` }}</button>

            <p v-if="expired" class="mt-3 text-center text-xs text-[#ff4d4f]">订单已超时，请返回重新下单</p>
            <p v-if="selectedChannelMeta?.sandbox" class="mt-3 text-center text-xs text-amber-500">当前为沙箱环境，不会产生真实扣款</p>
          </section>
        </template>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
