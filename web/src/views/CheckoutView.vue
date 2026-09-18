<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { MapPin, NotebookPen, Plus, Ticket } from 'lucide-vue-next'
import { getAddresses, getCart, type CartItemView, type Address } from '@/api/user'
import { createOrder, type CreateOrderResult } from '@/api/order'
import {
  getAvailableCoupons, getPromotionPreview,
  type AvailableCoupon, type UnavailableCoupon, type PromotionDisplay,
} from '@/api/coupon'
import { couponConditionText, couponValueText } from '@/utils/coupon'
import AddressForm from '@/components/AddressForm.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 结算页（Roadmap P4）：选地址、备注、金额明细、提交订单
 * V1.1 E04 / T-029：地址选择弹层支持内联新增，保存后自动选中且不丢失备注
 * V1.1 二期 F06 / T-039：用券（默认最优 + 弹层分组选券）与金额明细实时预览
 *  - 预览口径与后端同源（可用券接口返回的 discount 即 couponDiscount 预览值）；
 *  - 提交后与服务端 pay_amount 核对，不一致时以服务端为准并弹窗确认；
 *  - 券在下单瞬间失效（过期/占用/停发）时给出「不使用优惠券继续下单」降级入口。
 */
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const submitting = ref(false)
const tip = ref('')

const addresses = ref<Address[]>([])
const validItems = ref<CartItemView[]>([])
const selectedAddressId = ref<number | null>(null)
const remark = ref('')

/** 内联新增地址弹层 */
const addressDialogOpen = ref(false)

/** 券与满减（V1.1 T-039） */
const usableCoupons = ref<AvailableCoupon[]>([])
const unusableCoupons = ref<UnavailableCoupon[]>([])
const promotion = ref<PromotionDisplay | null>(null)
const selectedCouponId = ref<number | null>(null)
const couponDialogOpen = ref(false)
/** 券失效降级：提交被拒（券已过期/占用/停发）时的提示与降级入口 */
const couponFailedTip = ref('')
/** 服务端金额与前端预览不一致时的待确认订单 */
const mismatch = ref<{ preview: string; server: string; orderId: string; orderNo: string } | null>(null)

const selectedCoupon = computed(() =>
  usableCoupons.value.find((c) => c.user_coupon_id === selectedCouponId.value) ?? null,
)

async function onAddressSaved(addr: Address) {
  // 不重载整页，仅追加并选中新地址，保留已填备注
  addresses.value = [addr, ...addresses.value.filter((a) => a.id !== addr.id)]
  selectedAddressId.value = addr.id
  addressDialogOpen.value = false
  tip.value = ''
}

const goodsAmount = computed(() =>
  validItems.value.reduce((sum, item) => sum + Number(item.subtotal), 0).toFixed(2),
)
/** 运费展示：与后端规则一致（满 99 免运费，否则默认 10 元，阈值来自后端配置，前端仅展示） */
const freightAmount = computed(() =>
  Number(goodsAmount.value) >= 99 || Number(goodsAmount.value) === 0 ? '0.00' : '10.00',
)
/** 满减优惠（后端自动匹配最优活动，预览值来自 displayFor） */
const promoDiscount = computed(() => promotion.value?.discount ?? 0)
/** 优惠券优惠（可用券接口返回的 discount，与下单同口径） */
const couponDiscount = computed(() => selectedCoupon.value?.discount ?? 0)
/** 应付预览：max(0, 商品 − 满减 − 券) + 运费（优惠项均受后端封顶，不会为负） */
const payAmount = computed(() =>
  (Math.max(0, Number(goodsAmount.value) - promoDiscount.value - couponDiscount.value) + Number(freightAmount.value)).toFixed(2),
)

/** 结算可用券与满减预览（失败静默降级为无优惠，不阻塞结算） */
async function loadCouponsAndPromo() {
  const items = validItems.value.map((i) => ({
    product_id: i.product_id,
    price: Number(i.price),
    quantity: i.quantity,
  }))
  if (!items.length) return
  const amount = validItems.value.reduce((s, i) => s + Number(i.subtotal), 0)
  const [couponRes, promoRes] = await Promise.all([
    getAvailableCoupons({ amount, items }).catch(() => null),
    getPromotionPreview({ items }).catch(() => null),
  ])
  usableCoupons.value = couponRes?.data.data.usable ?? []
  unusableCoupons.value = couponRes?.data.data.unusable ?? []
  promotion.value = promoRes?.data.data.promotion ?? null
  // 默认选中最优券（优惠额最大；并列取先返回的，即临期优先——接口按 expire_at 升序）
  if (usableCoupons.value.length) {
    const best = usableCoupons.value.reduce((b, c) => (c.discount > b.discount ? c : b))
    selectedCouponId.value = best.user_coupon_id
  }
}

async function load() {
  loading.value = true
  try {
    const [addrRes, cartRes] = await Promise.all([getAddresses(), getCart()])
    addresses.value = addrRes.data.data
    validItems.value = cartRes.data.data.items.filter((item) => item.valid)

    const preferred =
      addresses.value.find((addr) => addr.is_default) ?? addresses.value[0] ?? null
    selectedAddressId.value = preferred?.id ?? null
    await loadCouponsAndPromo()
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (auth.token) await load()
  else loading.value = false
})

function pickCoupon(userCouponId: number | null) {
  selectedCouponId.value = userCouponId
  couponDialogOpen.value = false
}

/** 提交下单；userCouponId 用于降级重试时不带券 */
async function submit() {
  if (tip.value) return
  if (!selectedAddressId.value) {
    tip.value = '请先选择收货地址'
    return
  }
  if (!validItems.value.length) {
    tip.value = '没有可结算的商品'
    return
  }

  submitting.value = true
  tip.value = ''
  couponFailedTip.value = ''
  try {
    const { data } = await createOrder({
      address_id: selectedAddressId.value,
      remark: remark.value.trim() || undefined,
      user_coupon_id: selectedCouponId.value ?? undefined,
    })
    const result: CreateOrderResult = data.data
    // 与服务端核算金额核对（任务书：不一致时以服务端为准并提示）
    if (Math.abs(Number(result.pay_amount) - Number(payAmount.value)) > 0.01) {
      mismatch.value = {
        preview: payAmount.value,
        server: result.pay_amount,
        orderId: result.order_id,
        orderNo: result.order_no,
      }
      return
    }
    await router.replace(`/orders/${result.order_id}/pay`)
  } catch (e) {
    if (selectedCouponId.value) {
      // 券在下单瞬间失效（过期/被占用/停发等）→ 提供降级入口
      couponFailedTip.value = e instanceof Error ? e.message : '下单失败，请稍后重试'
    } else {
      tip.value = e instanceof Error ? e.message : '下单失败，请稍后重试'
    }
  } finally {
    submitting.value = false
  }
}

/** 券失效降级：放弃用券重新提交 */
async function submitWithoutCoupon() {
  selectedCouponId.value = null
  couponFailedTip.value = ''
  await submit()
}

/** 金额不一致确认：以服务端为准继续去支付 */
async function confirmMismatch() {
  if (!mismatch.value) return
  const { orderId } = mismatch.value
  mismatch.value = null
  await router.replace(`/orders/${orderId}/pay`)
}
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-8">
      <h1 class="mb-6 text-xl font-bold text-slate-800">确认订单</h1>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <p class="mb-4">请先登录</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push({ path: '/login', query: { redirect: '/checkout' } })">去登录</button>
      </div>

      <template v-else>
        <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

        <!-- 收货地址 -->
        <section class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
          <div class="mb-4 flex items-center justify-between">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-700">
              <MapPin class="h-4 w-4 text-[#1677ff]" /> 收货地址
            </h2>
            <button
              class="flex items-center gap-1 rounded-full border border-[#1677ff] px-3 py-1 text-xs text-[#1677ff] hover:bg-[#e6f4ff]"
              data-testid="checkout-add-address"
              @click="addressDialogOpen = true"
            ><Plus class="h-3.5 w-3.5" /> 新增地址</button>
          </div>

          <div v-if="!addresses.length" class="flex flex-col items-center gap-3 py-6 text-sm text-slate-400" data-testid="checkout-address-empty">
            还没有收货地址，请先新增后再下单
            <button class="rounded-full bg-[#1677ff] px-5 py-1.5 text-xs text-white" @click="addressDialogOpen = true">新增地址</button>
          </div>

          <div v-else class="grid gap-3 md:grid-cols-2">
            <label
              v-for="addr in addresses" :key="addr.id"
              class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors"
              :data-testid="`checkout-address-${addr.id}`"
              :class="selectedAddressId === addr.id ? 'border-[#1677ff] bg-[#f0f7ff]' : 'border-slate-200 hover:border-[#91caff]'"
            >
              <input v-model="selectedAddressId" type="radio" :value="addr.id" class="mt-1 accent-[#1677ff]" />
              <span class="min-w-0 text-sm">
                <span v-if="addr.label" class="mr-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">{{ addr.label }}</span>
                <span class="font-medium text-slate-700">{{ addr.contact_name }}</span>
                <span class="ml-2 text-slate-500">{{ addr.contact_phone }}</span>
                <span v-if="addr.is_default" class="ml-2 rounded bg-[#1677ff] px-1 py-0.5 text-[10px] text-white">默认</span>
                <span class="mt-1 block truncate text-xs text-slate-500">
                  {{ addr.province }}{{ addr.city }}{{ addr.district }}{{ addr.detail_address }}
                </span>
              </span>
            </label>
          </div>
        </section>

        <!-- 商品清单 -->
        <section class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
          <h2 class="mb-4 text-sm font-semibold text-slate-700">商品清单</h2>
          <div v-for="item in validItems" :key="item.id" class="flex items-center gap-4 border-b border-slate-50 py-3 last:border-0">
            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-2xl">
              <img v-if="item.image" :src="item.image" class="h-full w-full object-cover" alt="" />
              <span v-else>📦</span>
            </div>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm text-slate-700">{{ item.title }}</p>
              <p class="mt-1 text-xs text-slate-400">{{ Object.values(item.specs).join(' / ') || '默认规格' }}</p>
            </div>
            <div class="text-right text-sm">
              <p class="text-slate-600">¥{{ item.price }} × {{ item.quantity }}</p>
              <p class="font-medium text-[#ff4d4f]">¥{{ item.subtotal }}</p>
            </div>
          </div>
        </section>

        <!-- 备注与金额明细 -->
        <section class="mb-6 grid gap-6 rounded-xl border border-slate-100 bg-white p-5 md:grid-cols-[1fr_320px]">
          <div>
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700">
              <NotebookPen class="h-4 w-4 text-[#1677ff]" /> 订单备注
            </h2>
            <textarea
              v-model="remark" rows="3" maxlength="200" placeholder="选填，给卖家留言（200 字以内）"
              class="w-full resize-none rounded-lg border border-slate-200 p-3 text-sm outline-none focus:border-[#1677ff]"
            ></textarea>
          </div>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between text-slate-600"><span>商品合计</span><span>¥{{ goodsAmount }}</span></div>
            <!-- 满减优惠（后端自动匹配最优活动；无命中隐藏，保持 V1.0 视觉） -->
            <div v-if="promoDiscount > 0" class="flex justify-between text-[#ff7a45]" data-testid="checkout-promo-row">
              <span>满减优惠<template v-if="promotion">（{{ promotion.name }}）</template></span>
              <span data-testid="checkout-promo-amount">−¥{{ promoDiscount.toFixed(2) }}</span>
            </div>
            <!-- 优惠券（不选券时隐藏，保持 V1.0 视觉） -->
            <div v-if="selectedCoupon" class="flex justify-between text-[#ff4d4f]" data-testid="checkout-coupon-row">
              <span>优惠券（{{ selectedCoupon.name }}）</span>
              <span data-testid="checkout-coupon-amount">−¥{{ couponDiscount.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between text-slate-600"><span>运费</span><span>{{ freightAmount === '0.00' ? '包邮' : `¥${freightAmount}` }}</span></div>
            <div class="flex justify-between border-t border-slate-100 pt-3">
              <span class="text-slate-700">应付总额</span>
              <span class="text-xl font-bold text-[#ff4d4f]" data-testid="checkout-pay-amount">¥{{ payAmount }}</span>
            </div>

            <!-- 用券入口（V1.1 T-039） -->
            <button
              class="flex w-full items-center justify-between rounded-lg border border-dashed border-slate-200 px-3 py-2 text-[13px] text-slate-600 transition-colors hover:border-[#1677ff] hover:text-[#1677ff]"
              data-testid="checkout-coupon-entry"
              @click="couponDialogOpen = true"
            >
              <span class="flex items-center gap-1.5"><Ticket class="h-4 w-4" /> 优惠券</span>
              <span>
                <template v-if="selectedCoupon">已选 −¥{{ couponDiscount.toFixed(2) }}（{{ usableCoupons.length }} 张可用）</template>
                <template v-else-if="usableCoupons.length">{{ usableCoupons.length }} 张可用，去选择</template>
                <template v-else>暂无可用券</template>
                <span class="ml-1 text-slate-400">›</span>
              </span>
            </button>

            <button
              class="mt-2 w-full rounded-full bg-[#1677ff] py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4096ff] disabled:cursor-not-allowed disabled:opacity-50"
              data-testid="checkout-submit"
              :disabled="submitting || !validItems.length || !selectedAddressId"
              @click="submit"
            >{{ submitting ? '提交中…' : '提交订单' }}</button>

            <!-- 券失效降级（V1.1 T-039 步骤 6） -->
            <div v-if="couponFailedTip" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-600" data-testid="checkout-coupon-failed">
              <p>{{ couponFailedTip }}</p>
              <button
                class="mt-1.5 font-medium text-[#1677ff] hover:underline"
                data-testid="checkout-continue-without-coupon"
                @click="submitWithoutCoupon"
              >不使用优惠券继续下单</button>
            </div>
          </div>
        </section>
      </template>
    </main>

    <!-- 内联新增地址弹层（V1.1 T-029） -->
    <Teleport to="body">
      <div v-if="addressDialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="addressDialogOpen = false">
        <div class="max-h-[88vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6 shadow-xl" data-testid="checkout-address-dialog">
          <h3 class="mb-4 text-base font-semibold text-slate-800">新增收货地址</h3>
          <AddressForm @saved="onAddressSaved" @cancel="addressDialogOpen = false" />
        </div>
      </div>

      <!-- 券选择弹层（V1.1 T-039：可用/不可用分组，不可用展示原因） -->
      <div v-if="couponDialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="couponDialogOpen = false">
        <div class="max-h-[80vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6 shadow-xl" data-testid="checkout-coupon-dialog">
          <h3 class="mb-4 text-base font-semibold text-slate-800">选择优惠券</h3>

          <!-- 不使用优惠券 -->
          <button
            class="mb-2 flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-sm transition-colors"
            :class="selectedCouponId === null ? 'border-[#1677ff] bg-[#f0f7ff]' : 'border-slate-200 hover:border-[#91caff]'"
            data-testid="coupon-option-none"
            @click="pickCoupon(null)"
          >
            <span class="text-slate-600">不使用优惠券</span>
            <span v-if="selectedCouponId === null" class="text-xs text-[#1677ff]">✓</span>
          </button>

          <!-- 可用券 -->
          <button
            v-for="c in usableCoupons" :key="c.user_coupon_id"
            class="mb-2 flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-left text-sm transition-colors"
            :class="selectedCouponId === c.user_coupon_id ? 'border-[#1677ff] bg-[#f0f7ff]' : 'border-slate-200 hover:border-[#91caff]'"
            :data-testid="`coupon-option-${c.user_coupon_id}`"
            @click="pickCoupon(c.user_coupon_id)"
          >
            <span class="min-w-0">
              <span class="block truncate font-medium text-slate-700">{{ c.name }}</span>
              <span class="mt-0.5 block text-xs text-slate-400">
                {{ couponValueText(c) }} · {{ couponConditionText(c) }}
                <template v-if="c.near_expiry"> · <span class="text-[#ff4d4f]">即将过期</span></template>
              </span>
            </span>
            <span class="shrink-0 text-sm font-medium text-[#ff4d4f]">−¥{{ c.discount.toFixed(2) }}</span>
          </button>

          <!-- 不可用券（附原因） -->
          <p v-if="unusableCoupons.length" class="mb-1.5 mt-3 text-xs text-slate-400">不可用（{{ unusableCoupons.length }}）</p>
          <div
            v-for="c in unusableCoupons" :key="`un-${c.user_coupon_id}`"
            class="mb-2 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-400"
            :data-testid="`coupon-unusable-${c.user_coupon_id}`"
          >
            <span class="min-w-0">
              <span class="block truncate">{{ c.name }}</span>
              <span class="mt-0.5 block text-xs">{{ c.reason }}</span>
            </span>
          </div>

          <button
            class="mt-2 w-full rounded-full border border-slate-200 py-2 text-sm text-slate-500 hover:border-slate-300"
            data-testid="coupon-dialog-close"
            @click="couponDialogOpen = false"
          >取消</button>
        </div>
      </div>
    </Teleport>

    <!-- 服务端金额与预览不一致（V1.1 T-039：以服务端为准并提示） -->
    <ConfirmDialog
      :model-value="!!mismatch"
      title="优惠金额已按服务端核算"
      :content="mismatch ? `前端预览应付 ¥${mismatch.preview}，服务端核算应付 ¥${mismatch.server}，将以服务端金额为准继续支付。` : ''"
      confirm-text="继续支付"
      cancel-text="返回修改"
      @confirm="confirmMismatch"
      @cancel="mismatch = null"
    />

    <ShopFooter />
  </div>
</template>
