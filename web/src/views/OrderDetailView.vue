<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MapPin, NotebookPen, RotateCcw } from 'lucide-vue-next'
import { applyRefund, cancelOrder, getOrder, type OrderDetail } from '@/api/order'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 订单详情（Roadmap P4）：快照展示 + 取消按钮
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const tip = ref('')
const order = ref<OrderDetail | null>(null)

async function load() {
  loading.value = true
  try {
    const { data } = await getOrder(Number(route.params.id))
    order.value = data.data
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (auth.token) await load()
  else loading.value = false
})

async function doCancel() {
  if (!order.value) return
  const reason = prompt('请输入取消原因（可留空）：')
  if (reason === null) return
  tip.value = ''
  try {
    const { data } = await cancelOrder(order.value.id, reason.trim() || undefined)
    order.value = data.data
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '取消失败'
    await load()
  }
}

async function doRefund() {
  if (!order.value) return
  const reason = prompt('请输入退款原因（可留空）：')
  if (reason === null) return
  tip.value = ''
  try {
    await applyRefund(order.value.id, reason.trim() || undefined)
    alert('退款申请已提交，等待商家审核')
    await load()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '申请退款失败'
  }
}
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-4xl flex-1 px-6 py-8">
      <div class="mb-6 flex items-center gap-3">
        <button class="text-sm text-slate-400 hover:text-[#1677ff]" @click="router.back()">← 返回</button>
        <h1 class="text-xl font-bold text-slate-800">订单详情</h1>
      </div>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <p class="mb-4">请先登录</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push({ path: '/login', query: { redirect: route.fullPath } })">去登录</button>
      </div>

      <template v-else-if="order">
        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

        <!-- 状态卡 -->
        <section class="mb-6 flex items-center justify-between rounded-xl bg-gradient-to-r from-[#e6f4ff] to-white p-5">
          <div>
            <p class="text-lg font-bold text-[#1677ff]">{{ order.status_label }}</p>
            <p class="mt-1 text-xs text-slate-500">订单号：{{ order.order_no }}　下单时间：{{ order.created_at }}</p>
            <p v-if="order.cancel_reason" class="mt-1 text-xs text-orange-500">取消原因：{{ order.cancel_reason }}</p>
          </div>
          <div class="text-right">
            <p class="text-xs text-slate-500">应付总额</p>
            <p class="text-2xl font-bold text-[#ff4d4f]">¥{{ order.pay_amount }}</p>
          </div>
        </section>

        <!-- 收货信息 -->
        <section v-if="order.address_snapshot" class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
          <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <MapPin class="h-4 w-4 text-[#1677ff]" /> 收货信息
          </h2>
          <p class="text-sm text-slate-700">
            {{ order.address_snapshot.contact_name }}　{{ order.address_snapshot.contact_phone }}
          </p>
          <p class="mt-1 text-sm text-slate-500">{{ order.address_snapshot.full_address }}</p>
        </section>

        <!-- 商品快照 -->
        <section class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">商品清单</h2>
          <div v-for="(item, idx) in order.items" :key="idx" class="flex items-center gap-4 border-b border-slate-50 py-3 last:border-0">
            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-2xl">
              <img v-if="item.sku_image" :src="item.sku_image" class="h-full w-full object-cover" alt="" />
              <span v-else>📦</span>
            </div>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm text-slate-700">{{ item.product_title }}</p>
              <p class="mt-1 text-xs text-slate-400">{{ Object.values(item.sku_specs).join(' / ') || '默认规格' }}</p>
            </div>
            <div class="text-right text-sm">
              <p class="text-slate-500">¥{{ item.price }} × {{ item.quantity }}</p>
              <p class="font-medium text-slate-700">¥{{ item.total_amount }}</p>
            </div>
          </div>
        </section>

        <!-- 金额明细 / 备注 -->
        <section class="mb-6 grid gap-6 rounded-xl border border-slate-100 bg-white p-5 md:grid-cols-2">
          <div class="space-y-2 text-sm">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">金额明细</h2>
            <div class="flex justify-between text-slate-600"><span>商品合计</span><span>¥{{ order.total_amount }}</span></div>
            <div class="flex justify-between text-slate-600">
              <span>运费</span><span>{{ Number(order.freight_amount) === 0 ? '包邮' : `¥${order.freight_amount}` }}</span>
            </div>
            <div class="flex justify-between border-t border-slate-100 pt-2 font-medium">
              <span>实付款</span><span class="text-[#ff4d4f]">¥{{ order.pay_amount }}</span>
            </div>
          </div>
          <div>
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700">
              <NotebookPen class="h-4 w-4 text-[#1677ff]" /> 订单备注
            </h2>
            <p class="text-sm text-slate-500">{{ order.remark || '无' }}</p>
          </div>
        </section>

        <!-- 退款记录 -->
        <section v-if="order.refunds.length" class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
          <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <RotateCcw class="h-4 w-4 text-[#1677ff]" /> 退款记录
          </h2>
          <div v-for="r in order.refunds" :key="r.refund_no" class="border-b border-slate-50 py-3 text-sm last:border-0">
            <div class="flex items-center justify-between">
              <span class="text-slate-600">{{ r.refund_no }}</span>
              <span class="font-medium text-[#ff4d4f]">¥{{ r.amount }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-400">
              状态：{{ { pending: '待审核', approved: '已同意', rejected: '已拒绝', success: '退款成功', failed: '退款失败' }[r.status] }}
              <template v-if="r.reason">｜原因：{{ r.reason }}</template>
              <template v-if="r.admin_remark">｜商家备注：{{ r.admin_remark }}</template>
              ｜申请时间：{{ r.created_at }}
            </p>
          </div>
        </section>

        <!-- 操作 -->
        <div class="flex justify-end gap-3">
          <button
            v-if="order.status === 'pending_payment'"
            class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]"
            @click="$router.push(`/orders/${order.id}/pay`)"
          >去支付</button>
          <button
            v-if="order.status === 'pending_payment'"
            class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:border-red-300 hover:text-red-500"
            @click="doCancel"
          >取消订单</button>
          <button
            v-if="['paid', 'shipped', 'completed'].includes(order.status)"
            class="rounded-full border border-orange-200 px-6 py-2 text-sm text-orange-500 hover:bg-orange-50"
            @click="doRefund"
          >申请退款</button>
        </div>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
