<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MapPin, NotebookPen, RotateCcw, Star } from 'lucide-vue-next'
import {
  confirmOrder,
  getOrder,
  rebuyOrder,
  type OrderDetail,
  type OrderItemView,
} from '@/api/order'
import { getMyReviews, type ReviewItem } from '@/api/review'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import OrderTimeline from '@/components/OrderTimeline.vue'
import ReviewForm from '@/components/ReviewForm.vue'
import ShippingCard from '@/components/ShippingCard.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 订单详情（Roadmap P4）：快照展示 + 取消按钮
 * V1.1 E02-B（T-005）：状态时间轴 + 确认收货（二次确认）+ 再次购买
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const tip = ref('')
const successTip = ref('')
const order = ref<OrderDetail | null>(null)

// 确认收货二次确认
const showConfirmDialog = ref(false)
const confirming = ref(false)
const rebuying = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await getOrder(route.params.id as string)
    order.value = data.data
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (route.query.refund_ok) {
    successTip.value = '退款申请已提交，等待商家审核'
    router.replace({ query: { ...route.query, refund_ok: undefined } })
  } else if (route.query.cancel_ok) {
    successTip.value = '订单已取消'
    router.replace({ query: { ...route.query, cancel_ok: undefined } })
  }
  if (auth.token) await load()
  else loading.value = false
})

/** 后端下发的按钮可用性；老版本响应缺失时按状态兜底 */
const actions = computed(() => {
  const fallback = {
    can_pay: order.value?.status === 'pending_payment',
    can_cancel: order.value?.status === 'pending_payment',
    can_confirm: order.value?.status === 'shipped',
    can_refund: ['paid', 'pending_ship', 'shipped', 'completed'].includes(order.value?.status ?? ''),
    can_review: order.value?.status === 'completed',
    can_rebuy: true,
  }
  return { ...fallback, ...(order.value?.actions ?? {}) }
})

/** 退款类型 / 退货状态中文映射（与后端 Refund 常量对齐） */
const REFUND_TYPE_LABELS: Record<string, string> = {
  refund: '仅退款',
  return_refund: '退货退款',
}

/**
 * 金额明细中的优惠分项（优惠方式完整展示）：
 * - 优先取 amount_details 快照里的 coupon_discount / promotion_discount（口径唯一）；
 * - 历史订单无快照时，用顶层列回退：满减直接取 promotion_discount；券 = 优惠合计 − 满减。
 */
const promotionDiscount = computed(() => {
  const d = order.value?.amount_details
  if (d?.promotion_discount != null && d.promotion_discount !== '') return Number(d.promotion_discount)
  return Number(order.value?.promotion_discount ?? 0)
})
const couponDiscount = computed(() => {
  const d = order.value?.amount_details
  if (d?.coupon_discount != null && d.coupon_discount !== '') return Number(d.coupon_discount)
  const total = Number(order.value?.discount_amount ?? 0)
  const promo = Number(order.value?.promotion_discount ?? 0)
  return Math.max(0, total - promo)
})
/** G1：券/满减规则快照——纠纷时可还原「当时用的什么券/活动、优惠了多少」 */
const couponSnapshot = computed(() => order.value?.amount_details?.coupon_snapshot ?? null)
const promotionSnapshot = computed(() => order.value?.amount_details?.promotion_snapshot ?? null)
/** G2：运费计算明细（模板/地区/重量口径、包邮判定） */
const freightDetail = computed(() => order.value?.amount_details?.freight_detail ?? null)
const freightBreakdownText = computed(() => {
  const f = freightDetail.value
  if (!f) return ''
  if (f.free_shipping) return '已满包邮门槛，运费减免为 ¥0'
  const modeLabel: Record<string, string> = { fixed: '固定', weight: '按重量', region: '按地区' }
  const parts = (f.groups ?? []).map((g) => {
    const label = modeLabel[g.mode] ?? g.mode
    const who = g.template_id ? `模板${g.template_id}` : '默认'
    return `${who}(${label}) ¥${g.amount}`
  })
  const province = f.province ? `发往${f.province}；` : ''
  return `${province}${parts.join('，')}`
})
const REFUND_STATUS_LABELS: Record<string, string> = {
  pending: '待审核',
  approved: '已同意',
  rejected: '已拒绝',
  success: '退款成功',
  failed: '退款失败',
}
const RETURN_STATUS_LABELS: Record<string, string> = {
  waiting_return: '待退货',
  shipping: '退货中',
  received: '已收货',
  exception: '异常',
}

/** 退款记录单行中文说明（含类型与退货状态） */
function refundLineText(r: { type?: string; status: string; return_status?: string | null }) {
  const typeLabel = r.type && REFUND_TYPE_LABELS[r.type] ? `【${REFUND_TYPE_LABELS[r.type]}】` : ''
  const statusLabel = REFUND_STATUS_LABELS[r.status] ?? r.status
  let s = `状态：${statusLabel}`
  if (r.type === 'return_refund' && r.return_status && RETURN_STATUS_LABELS[r.return_status]) {
    s += `｜退货状态：${RETURN_STATUS_LABELS[r.return_status]}`
  }
  return typeLabel + s
}

/** 确认收货：先二次确认，成功后局部刷新详情与时间轴 */
async function doConfirm() {
  if (!order.value) return
  confirming.value = true
  tip.value = ''
  try {
    await confirmOrder(order.value.id)
    showConfirmDialog.value = false
    await load()
  } catch (e) {
    showConfirmDialog.value = false
    tip.value = e instanceof Error ? e.message : '确认收货失败'
    await load()
  } finally {
    confirming.value = false
  }
}

/** 再次购买弹层提示：有失效行时展示明细，替代原生 alert */
const rebuyNotice = ref<{ title: string; message: string; goCart: boolean } | null>(null)

function onRebuyNoticeConfirm() {
  const n = rebuyNotice.value
  rebuyNotice.value = null
  if (n?.goCart) router.push('/cart')
}

/** 再次购买：失效行以弹窗提示 */
async function doRebuy() {
  if (!order.value) return
  rebuying.value = true
  tip.value = ''
  try {
    const { data } = await rebuyOrder(order.value.id)
    const result = data.data
    if (result.skipped.length) {
      const lines = result.skipped.map((s) => `· ${s.title}：${s.reason}`).join('\n')
      rebuyNotice.value = {
        title: '再次购买',
        message: `已加入购物车 ${result.added} 件商品\n以下商品未能加入：\n${lines}`,
        goCart: result.added > 0,
      }
    } else if (result.added > 0) {
      router.push('/cart')
    }
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '再次购买失败'
  } finally {
    rebuying.value = false
  }
}

// ---------- 评价（V1.1 F01 / T-016） ----------

/** 当前展开评价表单的行项目 */
const reviewingItemId = ref<string | number | null>(null)
/** 修改模式下的评价对象（含 order_item_id，用于定位行项目） */
const editingReview = ref<ReviewItem | null>(null)
/** 评价操作的就近行内提示（定位到具体行项目，不再飘到页面顶部） */
const reviewTip = ref<{ itemId: string | number; message: string } | null>(null)

function openReview(item: OrderItemView) {
  editingReview.value = null
  reviewTip.value = null
  reviewingItemId.value = item.id ?? null
}

/** 修改评价：拉取我的评价中找到对应行项目（含可编辑字段） */
async function openEditReview(item: OrderItemView) {
  if (!item.id) return
  reviewingItemId.value = null
  tip.value = ''
  reviewTip.value = null
  try {
    const { data } = await getMyReviews({ page: 1, page_size: 50 })
    // P2-11：order_item_id 与 item.id 均为 public_id 字符串
    const found = data.data.list.find((r) => r.order_item_id === item.id) ?? null
    if (found) {
      editingReview.value = found
    } else {
      reviewTip.value = { itemId: item.id, message: '未找到该评价，可能已超过修改期限' }
    }
  } catch (e) {
    reviewTip.value = { itemId: item.id, message: e instanceof Error ? e.message : '加载评价失败' }
  }
}

async function onReviewSubmitted() {
  reviewingItemId.value = null
  editingReview.value = null
  reviewTip.value = null
  await load()
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
        <p v-if="successTip" class="mb-3 rounded-md bg-green-50 px-3 py-2 text-xs text-green-600" data-testid="refund-success-tip">{{ successTip }}</p>

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

        <!-- 订单进度时间轴（V1.1 T-005）+ 物流卡片插槽（V1.1 T-046：已发货/已完成时渲染） -->
        <OrderTimeline :logs="order.logs ?? []" :status="order.status">
          <template #shipping>
            <ShippingCard v-if="['shipped', 'completed'].includes(order.status)" :order-id="order.id" />
          </template>
        </OrderTimeline>

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
          <template v-for="(item, idx) in order.items" :key="item.id ?? idx">
            <div class="flex items-center gap-4 border-b border-slate-50 py-3 last:border-0">
              <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-2xl">
                <img v-if="item.sku_image" :src="item.sku_image" class="h-full w-full object-cover" alt="" />
                <span v-else>📦</span>
              </div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-slate-700">{{ item.product_title }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ Object.values(item.sku_specs).join(' / ') || '默认规格' }}</p>

                <!-- 已评价展示（V1.1 T-016；P2-11 补：展示评价内容与图片） -->
                <div v-if="item.review" class="mt-2 text-xs" data-testid="item-reviewed">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="flex items-center gap-0.5 text-[#ffb400]">
                      <Star v-for="n in 5" :key="n" class="h-3 w-3" :fill="n <= item.review.rating ? '#ffb400' : 'none'" :class="n <= item.review.rating ? '' : 'text-slate-200'" />
                    </span>
                    <span class="text-slate-400">
                      {{ item.review.status === 'pending' ? '评价审核中' : item.review.status === 'rejected' ? '评价未通过' : '已评价' }}
                    </span>
                    <button
                      v-if="item.review.can_edit && item.id"
                      class="text-[#1677ff] hover:underline"
                      :data-testid="`edit-review-${item.id}`"
                      @click="openEditReview(item)"
                    >修改评价</button>
                  </div>
                  <p
                    v-if="item.review.content"
                    class="mt-1 whitespace-pre-wrap break-words text-slate-600"
                    :data-testid="`item-review-content-${item.id}`"
                  >{{ item.review.content }}</p>
                  <div
                    v-if="item.review.images?.length"
                    class="mt-2 flex flex-wrap gap-2"
                    :data-testid="`item-review-images-${item.id}`"
                  >
                    <img
                      v-for="(url, i) in item.review.images"
                      :key="`rv-img-${i}`"
                      :src="url"
                      class="h-14 w-14 rounded border border-slate-200 object-cover"
                      alt="评价图"
                    />
                  </div>
                  <!-- 就近行内提示（如「修改评价」未找到对应评价） -->
                  <p
                    v-if="reviewTip && reviewTip.itemId === item.id"
                    class="mt-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500"
                    :data-testid="`review-inline-tip-${item.id}`"
                  >{{ reviewTip.message }}</p>
                </div>
              </div>
              <div class="text-right text-sm">
                <p class="text-slate-500">¥{{ item.price }} × {{ item.quantity }}</p>
                <p class="font-medium text-slate-700">¥{{ item.total_amount }}</p>
              </div>

              <!-- 评价入口（仅已完成且未评价） -->
              <button
                v-if="order.status === 'completed' && !item.review && item.id"
                class="shrink-0 rounded-full border border-[#1677ff] px-4 py-1.5 text-xs text-[#1677ff] hover:bg-[#f0f7ff]"
                :data-testid="`review-item-${item.id}`"
                @click="openReview(item)"
              >评价</button>
            </div>

            <!-- 内联评价表单 -->
            <div v-if="reviewingItemId === item.id || (editingReview && editingReview.order_item_id === item.id)" class="pb-4" :data-testid="`review-form-wrap-${item.id}`">
              <ReviewForm
                :order-id="order.id"
                :item-id="item.id"
                :initial="editingReview && editingReview.order_item_id === item.id ? editingReview : null"
                :product-title="item.product_title"
                @submitted="onReviewSubmitted"
                @cancel="editingReview = null"
              />
            </div>
          </template>
        </section>

        <!-- 金额明细 / 备注 -->
        <section class="mb-6 grid gap-6 rounded-xl border border-slate-100 bg-white p-5 md:grid-cols-2">
          <div class="space-y-2 text-sm">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">金额明细</h2>
            <div class="flex justify-between text-slate-600"><span>商品合计</span><span>¥{{ order.total_amount }}</span></div>
            <!-- 满减优惠（其它优惠方式，优惠额 > 0 才展示，避免无优惠时多一行） -->
            <div v-if="promotionDiscount > 0" class="flex justify-between text-[#ff7a45]" data-testid="order-promo-row">
              <span>
                满减优惠
                <template v-if="promotionSnapshot?.name">（{{ promotionSnapshot.name }}<template v-if="promotionSnapshot.hit_tier">：满{{ promotionSnapshot.hit_tier.min }}减{{ promotionSnapshot.hit_tier.discount }}</template>）</template>
              </span>
              <span data-testid="order-promo-amount">−¥{{ promotionDiscount.toFixed(2) }}</span>
            </div>
            <!-- 优惠券（优惠额 > 0 才展示） -->
            <div v-if="couponDiscount > 0" class="flex justify-between text-[#ff4d4f]" data-testid="order-coupon-row">
              <span>
                优惠券
                <template v-if="couponSnapshot?.name">（{{ couponSnapshot.name }}）</template>
              </span>
              <span data-testid="order-coupon-amount">−¥{{ couponDiscount.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between text-slate-600">
              <span>运费</span><span>{{ Number(order.freight_amount) === 0 ? '包邮' : `¥${order.freight_amount}` }}</span>
            </div>
            <p v-if="freightBreakdownText" class="mt-1 text-right text-[11px] text-slate-400" data-testid="order-freight-detail">
              {{ freightBreakdownText }}
            </p>
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
              <span class="text-slate-600">
                {{ r.refund_no }}
                <span v-if="r.type && REFUND_TYPE_LABELS[r.type]" class="ml-1 rounded bg-[#f0f7ff] px-1.5 py-0.5 text-[10px] text-[#1677ff]">{{ REFUND_TYPE_LABELS[r.type] }}</span>
              </span>
              <span class="font-medium text-[#ff4d4f]">¥{{ r.amount }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-400">
              {{ refundLineText(r) }}
              <template v-if="r.reason">｜原因：{{ r.reason }}</template>
              <template v-if="r.admin_remark">｜商家备注：{{ r.admin_remark }}</template>
              ｜申请时间：{{ r.created_at }}
            </p>
            <div v-if="r.images?.length" class="mt-2 flex flex-wrap gap-2">
              <a
                v-for="(url, i) in r.images"
                :key="`r-img-${i}`"
                :href="url"
                target="_blank"
                class="block h-14 w-14 overflow-hidden rounded border border-slate-200"
              >
                <img :src="url" class="h-full w-full object-cover" alt="凭证图" />
              </a>
            </div>
          </div>
        </section>

        <!-- 操作 -->
        <div class="flex justify-end gap-3">
          <button
            v-if="actions.can_pay"
            class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]"
            @click="$router.push(`/orders/${order.id}/pay`)"
          >去支付</button>
          <button
            v-if="actions.can_cancel"
            class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:border-red-300 hover:text-red-500"
            data-testid="apply-cancel"
            @click="$router.push(`/orders/${order.id}/cancel`)"
          >取消订单</button>
          <button
            v-if="actions.can_confirm"
            class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]"
            data-testid="confirm-receipt"
            @click="showConfirmDialog = true"
          >确认收货</button>
          <button
            v-if="actions.can_rebuy"
            class="rounded-full border border-[#1677ff] px-6 py-2 text-sm text-[#1677ff] hover:bg-[#f0f7ff] disabled:opacity-60"
            data-testid="rebuy"
            :disabled="rebuying"
            @click="doRebuy"
          >{{ rebuying ? '处理中…' : '再次购买' }}</button>
          <button
            v-if="actions.can_refund"
            class="rounded-full border border-orange-200 px-6 py-2 text-sm text-orange-500 hover:bg-orange-50"
            data-testid="apply-refund"
            @click="$router.push(`/orders/${order.id}/refund`)"
          >申请退款</button>
          <button
            class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff]"
            data-testid="contact-service"
            @click="$router.push({ path: '/service-center/tickets/new', query: { order_id: order.id } })"
          >联系客服</button>
        </div>

        <!-- 确认收货二次确认 -->
        <ConfirmDialog
          v-model="showConfirmDialog"
          title="确认收货"
          content="请确认已收到商品。确认后订单将完成，该操作不可撤销。"
          confirm-text="确认收货"
          :loading="confirming"
          @confirm="doConfirm"
        />

        <!-- 再次购买失效明细弹层 -->
        <ConfirmDialog
          :model-value="rebuyNotice !== null"
          :title="rebuyNotice?.title"
          :content="rebuyNotice?.message"
          :confirm-text="rebuyNotice?.goCart ? '去购物车' : '知道了'"
          cancel-text="留在此页"
          @update:model-value="(v: boolean) => { if (!v) rebuyNotice = null }"
          @confirm="onRebuyNoticeConfirm"
        />
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
