<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  getOrder,
  getOrderTimeline,
  ORDER_PROGRESS_FLOW,
  ORDER_STATUS_CLASS,
  ORDER_STATUS_LABELS,
  TRACE_STATUS_CLASS,
  TRACE_STATUS_LABELS,
  type AdminOrder,
  type OrderItemView,
  type OrderLogRow,
} from '@/api/order'
import { ArrowLeft, MapPin, Package, Ticket, Truck } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 后台订单详情页（/orders/:id）
 *
 * 相比列表弹窗，完整展示：订单进度条、收货地址、商品行优惠分摊、金额明细、
 * 优惠券、物流轨迹时间线、订单流水。
 */
const route = useRoute()
const router = useRouter()

const loading = ref(true)
const errorMsg = ref('')
const order = ref<AdminOrder | null>(null)
const logs = ref<OrderLogRow[]>([])

/** 两位小数金额（后端金额为字符串，避免浮点误差展示） */
function money(v?: string | null): string {
  return Number(v ?? 0).toFixed(2)
}

/** 行实付 = 小计 − 满减分摊 − 券分摊 */
function linePayable(item: OrderItemView): string {
  return (Number(item.total_amount) - Number(item.coupon_share ?? 0) - Number(item.promotion_share ?? 0)).toFixed(2)
}

/** 主流程进度下标（-1 表示不在主流程上） */
const progressIndex = computed(() => {
  const st = order.value?.status
  if (!st) return -1
  const i = ORDER_PROGRESS_FLOW.findIndex((s) => s.status === st)
  if (i >= 0) return i
  // 取消 / 退款是终态分支：取消至少提交过订单，退款至少已支付
  if (st === 'cancelled') return 0
  if (st === 'refunding' || st === 'refunded') return 1
  return -1
})

/** 终态分支（取消 / 退款），进度条走到一半即终止 */
const isAbnormalEnd = computed(() => ['cancelled', 'refunding', 'refunded'].includes(order.value?.status ?? ''))

/** 金额明细：优先 amount_details（T-035），老单回退订单级字段 */
const amounts = computed(() => {
  const o = order.value
  const d = o?.amount_details
  return {
    goods: money(d?.goods_amount ?? o?.total_amount),
    promotion: money(d?.promotion_discount ?? o?.promotion_discount),
    coupon: money(d?.coupon_discount),
    freight: money(d?.freight_amount ?? o?.freight_amount),
    pay: money(d?.pay_amount ?? o?.pay_amount),
  }
})

/** 是否有任何优惠 */
const hasDiscount = computed(() => Number(amounts.value.promotion) > 0 || Number(amounts.value.coupon) > 0)

/** 优惠券文案 */
const couponText = computed(() => {
  const c = order.value?.coupon
  if (!c) return ''
  return c.type === 'fixed' ? `立减 ¥${money(c.amount)}` : `${c.percent ?? 0}% 折扣`
})

/** 物流（一个订单通常一条运单） */
const shippingInfo = computed(() => order.value?.shipping?.[0] ?? null)

/** 收货地址全文（优先快照 full_address，缺失时按省市区拼接） */
const fullAddress = computed(() => {
  const a = order.value?.address_snapshot
  if (!a) return ''
  if (a.full_address) return a.full_address
  return [a.province, a.city, a.district, a.detail_address].filter(Boolean).join('')
})

onMounted(async () => {
  const id = Number(route.params.id)
  if (!id) {
    errorMsg.value = '订单 ID 无效'
    loading.value = false
    return
  }

  try {
    const { data } = await getOrder(id)
    order.value = data.data
  } catch {
    errorMsg.value = '订单不存在或无权查看'
    loading.value = false
    return
  }

  // 流水需要 order.log 权限，失败不阻断详情页
  try {
    const { data } = await getOrderTimeline(id)
    logs.value = data.data?.list ?? []
  } catch {
    logs.value = []
  }

  loading.value = false
})
</script>

<template>
  <div>
    <!-- 顶部：返回 + 订单号 + 状态 -->
    <div class="mb-4 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <Button variant="outline" size="sm" data-testid="back" @click="router.back()">
          <ArrowLeft class="mr-1 h-4 w-4" /> 返回
        </Button>
        <h2 class="text-lg font-semibold text-slate-800">订单详情</h2>
        <span
          v-if="order"
          class="rounded px-2 py-0.5 text-xs"
          :class="ORDER_STATUS_CLASS[order.status]"
          data-testid="status-badge"
        >{{ ORDER_STATUS_LABELS[order.status] ?? order.status_label }}</span>
        <span v-if="order?.auto_completed" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">自动完成</span>
      </div>
      <span v-if="order" class="font-mono text-[13px] text-slate-500">{{ order.order_no }}</span>
    </div>

    <LoadingSpinner v-if="loading" />
    <div v-else-if="errorMsg" class="rounded-lg bg-white p-10 text-center text-slate-400 shadow-sm">{{ errorMsg }}</div>

    <div v-else-if="order" class="space-y-4">
      <!-- 订单进度条 -->
      <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="progress-card">
        <h3 class="mb-4 text-sm font-medium text-slate-700">订单进度</h3>
        <div class="flex items-start">
          <template v-for="(step, i) in ORDER_PROGRESS_FLOW" :key="step.status">
            <div class="flex w-20 flex-col items-center">
              <div
                class="flex h-7 w-7 items-center justify-center rounded-full text-[12px]"
                :class="i <= progressIndex ? 'bg-[#1677ff] text-white' : 'bg-slate-200 text-slate-500'"
              >{{ i + 1 }}</div>
              <span
                class="mt-1.5 whitespace-nowrap text-[12px]"
                :class="i <= progressIndex ? 'text-[#1677ff]' : 'text-slate-400'"
              >{{ step.label }}</span>
            </div>
            <div
              v-if="i < ORDER_PROGRESS_FLOW.length - 1"
              class="mt-3.5 h-0.5 flex-1"
              :class="i < progressIndex ? 'bg-[#1677ff]' : 'bg-slate-200'"
            />
          </template>
        </div>
        <p v-if="isAbnormalEnd" class="mt-3 text-[13px] text-slate-500" data-testid="abnormal-end">
          订单已进入终态分支：<b>{{ ORDER_STATUS_LABELS[order.status] }}</b><template v-if="order.cancel_reason">（{{ order.cancel_reason }}）</template>，主流程在此终止。
        </p>
      </div>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- 基本信息 -->
        <div class="rounded-lg bg-white p-5 shadow-sm">
          <h3 class="mb-3 text-sm font-medium text-slate-700">基本信息</h3>
          <dl class="grid grid-cols-2 gap-y-2 text-[13px]">
            <dt class="text-slate-400">订单号</dt>
            <dd class="font-mono">{{ order.order_no }}</dd>
            <dt class="text-slate-400">买家 ID</dt>
            <dd>{{ order.user_id }}</dd>
            <dt class="text-slate-400">下单时间</dt>
            <dd>{{ order.created_at }}</dd>
            <dt class="text-slate-400">支付时间</dt>
            <dd>{{ order.paid_at || '—' }}</dd>
            <dt class="text-slate-400">发货时间</dt>
            <dd>{{ order.shipped_at || '—' }}</dd>
            <dt class="text-slate-400">完成时间</dt>
            <dd>{{ order.completed_at || '—' }}</dd>
            <dt class="text-slate-400">取消时间</dt>
            <dd>{{ order.cancelled_at || '—' }}</dd>
            <dt class="text-slate-400">买家备注</dt>
            <dd>{{ order.remark || '—' }}</dd>
          </dl>
        </div>

        <!-- 收货地址 -->
        <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="address-card">
          <h3 class="mb-3 flex items-center gap-1.5 text-sm font-medium text-slate-700">
            <MapPin class="h-4 w-4 text-slate-400" /> 收货地址
          </h3>
          <template v-if="order.address_snapshot">
            <p class="text-[13px] font-medium text-slate-700">
              {{ order.address_snapshot.contact_name }}　{{ order.address_snapshot.contact_phone }}
            </p>
            <p class="mt-1 text-[13px] text-slate-500">{{ fullAddress }}</p>
          </template>
          <p v-else class="text-[13px] text-slate-400">暂无收货地址</p>
        </div>
      </div>

      <!-- 商品明细（含优惠分摊） -->
      <div class="rounded-lg bg-white p-5 shadow-sm">
        <h3 class="mb-3 flex items-center gap-1.5 text-sm font-medium text-slate-700">
          <Package class="h-4 w-4 text-slate-400" /> 商品明细
        </h3>
        <table class="w-full text-[13px]">
          <thead>
            <tr class="border-b border-slate-200 text-left text-slate-500">
              <th class="px-3 py-1.5">商品</th>
              <th class="px-3 py-1.5">规格</th>
              <th class="px-3 py-1.5">单价</th>
              <th class="px-3 py-1.5">数量</th>
              <th class="px-3 py-1.5">小计</th>
              <th class="px-3 py-1.5">满减分摊</th>
              <th class="px-3 py-1.5">券分摊</th>
              <th class="px-3 py-1.5">实付</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, idx) in order.items" :key="idx" class="border-b border-slate-50">
              <td class="px-3 py-1.5">{{ item.product_title }}</td>
              <td class="px-3 py-1.5 text-slate-500">
                {{ Object.values(item.sku_specs ?? {}).join(' / ') || '—' }}
              </td>
              <td class="px-3 py-1.5">¥{{ money(item.price) }}</td>
              <td class="px-3 py-1.5">{{ item.quantity }}</td>
              <td class="px-3 py-1.5">¥{{ money(item.total_amount) }}</td>
              <td class="px-3 py-1.5 text-slate-500">-¥{{ money(item.promotion_share) }}</td>
              <td class="px-3 py-1.5 text-slate-500">-¥{{ money(item.coupon_share) }}</td>
              <td class="px-3 py-1.5 font-medium">¥{{ linePayable(item) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 金额明细 + 优惠券 -->
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="amount-card">
          <h3 class="mb-3 text-sm font-medium text-slate-700">金额明细</h3>
          <dl class="space-y-2 text-[13px]">
            <div class="flex justify-between">
              <dt class="text-slate-500">商品总额</dt>
              <dd>¥{{ amounts.goods }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-slate-500">满减优惠</dt>
              <dd class="text-[#2e9e57]">-¥{{ amounts.promotion }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-slate-500">优惠券优惠</dt>
              <dd class="text-[#2e9e57]">-¥{{ amounts.coupon }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-slate-500">运费</dt>
              <dd>¥{{ amounts.freight }}</dd>
            </div>
            <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-semibold">
              <dt>实付款</dt>
              <dd class="text-[#ff4d4f]">¥{{ amounts.pay }}</dd>
            </div>
          </dl>
          <p v-if="!hasDiscount" class="mt-2 text-xs text-slate-400">该订单未使用优惠</p>
        </div>

        <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="coupon-card">
          <h3 class="mb-3 flex items-center gap-1.5 text-sm font-medium text-slate-700">
            <Ticket class="h-4 w-4 text-slate-400" /> 优惠券
          </h3>
          <template v-if="order.coupon">
            <p class="text-[13px] font-medium text-slate-700">{{ order.coupon.name }}</p>
            <dl class="mt-2 space-y-1 text-[13px]">
              <div class="flex justify-between">
                <dt class="text-slate-500">优惠内容</dt>
                <dd>{{ couponText }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-slate-500">使用门槛</dt>
                <dd>{{ Number(order.coupon.min_spend) > 0 ? `满 ¥${money(order.coupon.min_spend)}` : '无门槛' }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-slate-500">本单抵扣</dt>
                <dd class="text-[#2e9e57]">-¥{{ amounts.coupon }}</dd>
              </div>
            </dl>
          </template>
          <p v-else class="text-[13px] text-slate-400">该订单未使用优惠券</p>
        </div>
      </div>

      <!-- 物流信息与轨迹时间线 -->
      <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="shipping-card">
        <h3 class="mb-3 flex items-center gap-1.5 text-sm font-medium text-slate-700">
          <Truck class="h-4 w-4 text-slate-400" /> 物流信息
        </h3>
        <template v-if="shippingInfo">
          <div class="mb-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-[13px]">
            <span><span class="text-slate-400">快递公司：</span>{{ shippingInfo.company_name }}</span>
            <span><span class="text-slate-400">运单号：</span><span class="font-mono">{{ shippingInfo.tracking_no }}</span></span>
            <span>
              <span class="text-slate-400">状态：</span>
              <span class="rounded px-1.5 py-0.5 text-xs" :class="TRACE_STATUS_CLASS[shippingInfo.trace_status] ?? 'bg-slate-100 text-slate-500'">
                {{ TRACE_STATUS_LABELS[shippingInfo.trace_status] ?? shippingInfo.trace_status }}
              </span>
            </span>
            <span><span class="text-slate-400">发货时间：</span>{{ shippingInfo.shipped_at || '—' }}</span>
            <span><span class="text-slate-400">签收时间：</span>{{ shippingInfo.delivered_at || '—' }}</span>
          </div>

          <div v-if="shippingInfo.traces.length" class="ml-1 border-l border-slate-200 pl-4" data-testid="trace-timeline">
            <div v-for="(t, i) in shippingInfo.traces" :key="i" class="relative pb-3">
              <span
                class="absolute -left-[21px] top-1 h-2 w-2 rounded-full"
                :class="i === 0 ? 'bg-[#1677ff]' : 'bg-slate-300'"
              />
              <p class="text-[13px] text-slate-700">{{ t.context }}</p>
              <p class="mt-0.5 text-xs text-slate-400">{{ t.occurred_at || '—' }}</p>
            </div>
          </div>
          <p v-else class="text-[13px] text-slate-400">暂无物流轨迹</p>
        </template>
        <p v-else class="text-[13px] text-slate-400">该订单尚未发货，暂无物流信息</p>
      </div>

      <!-- 订单流水 -->
      <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="logs-card">
        <h3 class="mb-3 text-sm font-medium text-slate-700">订单流水</h3>
        <div v-if="logs.length" class="ml-1 border-l border-slate-200 pl-4">
          <div v-for="log in logs" :key="log.id" class="relative pb-3">
            <span class="absolute -left-[21px] top-1 h-2 w-2 rounded-full bg-slate-300" />
            <p class="text-[13px] text-slate-700">
              {{ log.from_status_label ? `${log.from_status_label} → ` : '' }}{{ log.to_status_label }}
              <span class="ml-1 text-xs text-slate-400">{{ log.operator_type_label }}</span>
            </p>
            <p class="mt-0.5 text-xs text-slate-400">
              {{ log.created_at || '—' }}<template v-if="log.remark">　{{ log.remark }}</template>
            </p>
          </div>
        </div>
        <p v-else class="text-[13px] text-slate-400">暂无流水记录（可能缺少 order.log 权限）</p>
      </div>
    </div>
  </div>
</template>
