<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MapPin, XCircle } from 'lucide-vue-next'
import { cancelOrder, getOrder, type OrderDetail } from '@/api/order'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 取消订单页（与 RefundApplyView 同构）
 * 取消原因下拉 + 「其它」自定义输入，提交后跳回订单详情。
 */

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const submitting = ref(false)
const tip = ref('')
const order = ref<OrderDetail | null>(null)

const orderId = computed(() => Number(route.params.id))

/** 常见取消原因（末项「其它」触发自定义输入框） */
const REASONS = [
  '不想要了 / 多拍误拍',
  '拍错了商品 / 规格',
  '忘记使用优惠券 / 买贵了',
  '商品缺货 / 发货太慢',
  '支付遇到问题',
  '收货地址填错了',
  '其它',
] as const
const OTHER = '其它'

const selectedReason = ref<string>('')
const otherReason = ref<string>('')

const isOther = computed(() => selectedReason.value === OTHER)
/** 最终提交的原因：其它时取输入框内容 */
const finalReason = computed(() => (isOther.value ? otherReason.value.trim() : selectedReason.value))
const canSubmit = computed(() => !submitting.value && (selectedReason.value !== '') && (!isOther.value || otherReason.value.trim().length > 0))

async function load() {
  loading.value = true
  try {
    const { data } = await getOrder(orderId.value)
    order.value = data.data
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (auth.token) await load()
  else loading.value = false
})

async function doSubmit() {
  if (!canSubmit.value || !order.value) return
  submitting.value = true
  tip.value = ''
  try {
    await cancelOrder(order.value.id, finalReason.value || undefined)
    router.push({ path: `/orders/${order.value.id}`, query: { cancel_ok: '1' } })
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '取消订单失败，请稍后重试'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-2xl flex-1 px-6 py-8">
      <div class="mb-6 flex items-center gap-3">
        <button class="text-sm text-slate-400 hover:text-[#1677ff]" @click="router.back()">← 返回</button>
        <h1 class="flex items-center gap-2 text-xl font-bold text-slate-800">
          <XCircle class="h-5 w-5 text-[#1677ff]" /> 取消订单
        </h1>
      </div>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <p class="mb-4">请先登录</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push({ path: '/login', query: { redirect: route.fullPath } })">去登录</button>
      </div>

      <template v-else-if="order">
        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

        <!-- 订单概要 -->
        <section class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
          <div class="flex items-center justify-between text-sm">
            <span class="text-slate-500">订单号：{{ order.order_no }}</span>
            <span class="font-medium text-[#ff4d4f]">实付 ¥{{ order.pay_amount }}</span>
          </div>
          <div class="mt-3 space-y-2">
            <div v-for="(item, idx) in order.items" :key="item.id ?? idx" class="flex items-center gap-3">
              <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-xl">
                <img v-if="item.sku_image" :src="item.sku_image" class="h-full w-full object-cover" alt="" />
                <span v-else>📦</span>
              </div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-slate-700">{{ item.product_title }}</p>
                <p class="text-xs text-slate-400">{{ Object.values(item.sku_specs).join(' / ') || '默认规格' }} × {{ item.quantity }}</p>
              </div>
              <p class="text-sm text-slate-600">¥{{ item.total_amount }}</p>
            </div>
          </div>
        </section>

        <!-- 取消表单 -->
        <section class="rounded-xl border border-slate-100 bg-white p-5">
          <!-- 取消原因下拉框 -->
          <div class="mb-5">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="cancel-reason">取消原因 <span class="text-red-500">*</span></label>
            <select
              id="cancel-reason"
              v-model="selectedReason"
              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-[#1677ff]"
            >
              <option value="" disabled>请选择取消原因</option>
              <option v-for="r in REASONS" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>

          <!-- 其它原因输入框 -->
          <div v-if="isOther" class="mb-5">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="cancel-other">其它原因 <span class="text-red-500">*</span></label>
            <textarea
              id="cancel-other"
              v-model="otherReason"
              rows="3"
              maxlength="200"
              placeholder="请输入具体取消原因（200 字以内）"
              class="w-full resize-none rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-[#1677ff]"
            ></textarea>
            <p class="mt-1 text-right text-xs text-slate-400">{{ otherReason.length }}/200</p>
          </div>

          <!-- 说明 -->
          <div class="mb-6 flex items-start gap-2 rounded-lg bg-[#f0f7ff] px-3 py-2.5 text-xs text-slate-500">
            <MapPin class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#1677ff]" />
            <p>取消后订单不可恢复；已支付的款项将原路退回，优惠券等权益随之返还。</p>
          </div>

          <button
            class="w-full rounded-full bg-[#1677ff] py-3 text-sm font-medium text-white transition-colors hover:bg-[#4096ff] disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!canSubmit"
            data-testid="cancel-submit"
            @click="doSubmit"
          >{{ submitting ? '提交中…' : '确认取消' }}</button>
        </section>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
