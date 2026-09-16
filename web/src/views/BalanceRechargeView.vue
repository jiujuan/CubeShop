<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Copy, Gift, Wallet } from 'lucide-vue-next'
import { createRecharge, getBalance, type BalanceAccount } from '@/api/balance'
import {
  getChannels, uploadVoucher,
  type CashierChannel, type PayChannel, type PayParams, type RechargeConfig,
} from '@/api/payment'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 余额充值页（收银台方案 §6.5 / §9.4，Roadmap P6）
 *
 * 面额选择 + 自定义金额 + 赠送提示 + 渠道选择（不含余额支付）；
 * 提交后复用与订单支付完全一致的 PayParams 调起逻辑与结果页 /pay/result/:payment_no。
 */
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const paying = ref(false)
const tip = ref('')

const account = ref<BalanceAccount | null>(null)
const channels = ref<CashierChannel[]>([])
const config = ref<RechargeConfig | null>(null)
const selectedChannel = ref<PayChannel>('wechat')

const presetAmount = ref('')
const customAmount = ref('')
const useCustom = ref(false)

// 线下转账表单
const payerName = ref('')
const payerAccount = ref('')
const transferNo = ref('')
const transferredAt = ref('')
const voucherFile = ref<File | null>(null)
const voucherUrl = ref('')

// 支付宝表单跳转（生产环境）
const formHtml = ref('')

const selectedAmount = computed(() => (useCustom.value ? customAmount.value : presetAmount.value))
const selectedChannelMeta = computed(() => channels.value.find((c) => c.code === selectedChannel.value) ?? null)

/** 命中赠送规则（前端仅作提示，服务端为准） */
const giftAmount = computed(() => {
  const amount = Number(selectedAmount.value)
  if (!amount || !config.value) return '0.00'
  let gift = 0
  for (const rule of config.value.gift_rules ?? []) {
    if (amount >= Number(rule.amount) && Number(rule.gift) > gift) gift = Number(rule.gift)
  }
  return gift.toFixed(2)
})
const hasGift = computed(() => Number(giftAmount.value) > 0)

const rechargeEnabled = computed(() => config.value?.enabled !== false)

const canPay = computed(() => {
  const amount = Number(selectedAmount.value)
  if (!config.value || !rechargeEnabled.value || paying.value) return false
  if (!amount || amount < Number(config.value.min_amount) || amount > Number(config.value.max_single)) return false
  return true
})

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    tip.value = '已复制到剪贴板'
  } catch {
    tip.value = '复制失败，请手动选择'
  }
}

function pickPreset(amount: string) {
  useCustom.value = false
  presetAmount.value = amount
  tip.value = ''
}

function onVoucherChange(e: Event) {
  voucherFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
  voucherUrl.value = ''
}

async function submit() {
  if (!canPay.value) return
  tip.value = ''
  paying.value = true
  try {
    let extra: Record<string, unknown> | undefined
    if (selectedChannel.value === 'offline') {
      if (!payerName.value || !transferNo.value) {
        tip.value = '请填写付款人姓名与转账流水号'
        return
      }
      if (voucherFile.value && !voucherUrl.value) {
        const up = await uploadVoucher(voucherFile.value)
        voucherUrl.value = up.data.data.url
      }
      if (!voucherUrl.value) {
        tip.value = '请上传转账凭证'
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

    const { data } = await createRecharge(selectedAmount.value, selectedChannel.value, extra)
    const pp = data.data.pay_params as PayParams

    if (pp.type === 'form') {
      formHtml.value = pp.form_html
      nextTick(() => document.getElementById('alipay-pay-form')?.querySelector('form')?.submit())
      return
    }
    if (pp.type === 'redirect') {
      window.location.href = pp.pay_url
      return
    }

    // 其余类型（二维码 / 模拟 / 凭证 / 直接）交给结果页渲染与轮询
    router.push({
      name: 'pay-result',
      params: { payment_no: data.data.payment_no },
      query: { pp: encodeURIComponent(JSON.stringify(pp)) },
    })
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '发起充值失败'
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
    const [{ data: ch }, { data: bal }] = await Promise.all([getChannels('recharge'), getBalance()])
    channels.value = ch.data.channels
    config.value = ch.data.recharge ?? null
    account.value = bal.data

    presetAmount.value = config.value?.amounts?.[0] ?? ''
    const def = ch.data.default_channel
    selectedChannel.value = channels.value.some((c) => c.code === def)
      ? def
      : (channels.value[0]?.code ?? 'wechat')
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '加载充值页失败'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-2xl flex-1 px-4 py-8" data-testid="recharge-view">
      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <p class="mb-4">请先登录</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="router.push({ path: '/login', query: { redirect: '/balance/recharge' } })">去登录</button>
      </div>

      <template v-else>
        <!-- 顶部余额 -->
        <div class="mb-4 flex items-center justify-between rounded-xl border border-slate-100 bg-white p-5">
          <div class="flex items-center gap-2 text-sm text-slate-600">
            <Wallet class="h-4 w-4 text-[#1677ff]" /> 余额充值
          </div>
          <div class="text-right">
            <p class="text-xs text-slate-400">当前余额</p>
            <p class="text-lg font-bold text-[#ff4d4f]" data-testid="balance-display">¥{{ account?.balance ?? '0.00' }}</p>
          </div>
        </div>

        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="recharge-tip">{{ tip }}</p>

        <div v-if="!rechargeEnabled" class="rounded-xl border border-amber-100 bg-amber-50 p-5 text-center text-sm text-amber-600">
          余额充值功能暂未开放
        </div>

        <!-- 充值表单 -->
        <section v-else class="rounded-xl border border-slate-100 bg-white p-6">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">充值金额</h2>
          <div class="mb-3 grid grid-cols-4 gap-2">
            <button
              v-for="a in config?.amounts ?? []" :key="a"
              class="rounded-lg border-2 py-2.5 text-sm font-medium transition-colors"
              :class="(!useCustom && presetAmount === a) ? 'border-[#1677ff] bg-[#f0f7ff] text-[#1677ff]' : 'border-slate-200 text-slate-600'"
              :data-testid="`amount-${a}`"
              @click="pickPreset(a)"
            >¥{{ a }}</button>
          </div>

          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input
              type="radio" :checked="useCustom"
              class="accent-[#1677ff]"
              data-testid="amount-custom-radio"
              @change="useCustom = true"
            />
            自定义金额
            <input
              v-model="customAmount" :disabled="!useCustom" type="number" min="0" step="0.01"
              class="w-32 rounded-md border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-[#1677ff] disabled:bg-slate-50"
              placeholder="0.00"
              data-testid="custom-amount"
              @focus="useCustom = true"
            />
          </label>

          <p
            v-if="hasGift"
            class="mt-3 flex items-center gap-1 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-600"
            data-testid="gift-hint"
          >
            <Gift class="h-3.5 w-3.5" /> 充 ¥{{ selectedAmount }} 送 ¥{{ giftAmount }}
          </p>
          <p v-if="config" class="mt-2 text-xs text-slate-400">
            单笔限额 ¥{{ config.min_amount }} ~ ¥{{ config.max_single }}，单日累计不超过 ¥{{ config.max_daily }}；充值金额不可提现。
          </p>

          <!-- 支付方式 -->
          <h2 class="mb-3 mt-5 text-sm font-semibold text-slate-700">支付方式</h2>
          <div class="grid grid-cols-2 gap-3">
            <button
              v-for="c in channels" :key="c.code"
              class="flex items-center justify-between gap-2 rounded-lg border-2 px-3 py-3 text-sm font-medium transition-colors"
              :class="selectedChannel === c.code ? 'border-[#1677ff] bg-[#f0f7ff] text-[#1677ff]' : 'border-slate-200 text-slate-600'"
              :data-testid="`channel-${c.code}`"
              @click="selectedChannel = c.code; tip = ''"
            >
              <span>{{ c.name }}</span>
              <span v-if="c.sandbox" class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-500">沙箱</span>
            </button>
          </div>

          <!-- 线下转账：收款账户 + 凭证 -->
          <div v-if="selectedChannel === 'offline'" class="mt-4 space-y-3 rounded-lg bg-slate-50 p-4" data-testid="offline-block">
            <div v-if="selectedChannelMeta?.receipt" class="text-xs text-slate-600">
              <p class="mb-1 font-medium text-slate-700">收款账户</p>
              <p v-if="selectedChannelMeta.receipt.bank_name">开户行：{{ selectedChannelMeta.receipt.bank_name }}</p>
              <p v-if="selectedChannelMeta.receipt.account_name">户名：{{ selectedChannelMeta.receipt.account_name }}</p>
              <p v-if="selectedChannelMeta.receipt.account_no" class="flex items-center gap-1">
                账号：{{ selectedChannelMeta.receipt.account_no }}
                <Copy class="h-3.5 w-3.5 cursor-pointer text-slate-400" @click="copy(selectedChannelMeta.receipt.account_no!)" />
              </p>
              <img v-if="selectedChannelMeta.receipt.qrcode_url" :src="selectedChannelMeta.receipt.qrcode_url" class="mt-2 h-28 w-28 rounded" alt="收款码" />
            </div>
            <div class="grid grid-cols-2 gap-2">
              <input v-model="payerName" class="rounded border border-slate-200 px-2 py-1.5 text-xs" placeholder="付款人姓名" data-testid="payer-name" />
              <input v-model="payerAccount" class="rounded border border-slate-200 px-2 py-1.5 text-xs" placeholder="付款账号/银行" />
              <input v-model="transferNo" class="rounded border border-slate-200 px-2 py-1.5 text-xs" placeholder="银行流水号" data-testid="transfer-no" />
              <input v-model="transferredAt" class="rounded border border-slate-200 px-2 py-1.5 text-xs" placeholder="转账时间（可选）" />
            </div>
            <div class="flex items-center gap-2">
              <input type="file" accept="image/jpg,image/jpeg,image/png,image/webp" class="text-xs" data-testid="voucher-file" @change="onVoucherChange" />
              <span v-if="voucherFile" class="text-xs text-[#52c41a]">凭证已选择</span>
            </div>
          </div>

          <div v-if="formHtml" v-html="formHtml" id="alipay-pay-form" class="mt-4"></div>
          <p v-if="formHtml" class="mt-2 text-xs text-slate-400">正在跳转到支付宝收银台…</p>

          <button
            class="mt-5 w-full rounded-full bg-[#1677ff] py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="!canPay"
            data-testid="recharge-submit"
            @click="submit"
          >{{ paying ? '发起充值中…' : `立即充值 ¥${selectedAmount || '0.00'}` }}</button>

          <p v-if="selectedChannelMeta?.sandbox" class="mt-3 text-center text-xs text-amber-500">当前为沙箱环境，不会产生真实扣款</p>
        </section>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
