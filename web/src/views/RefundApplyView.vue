<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ImagePlus, MapPin, RotateCcw } from 'lucide-vue-next'
import { applyRefund, getOrder, type OrderDetail } from '@/api/order'
import { uploadImage } from '@/api/user'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 售后申请页（退款 / 退货退款）
 * - 仅退款：原流程，审核通过即退款。
 * - 退货退款：需勾选退货商品与数量、填写寄回物流单号；商家确认收货后退款（先收货后退款）。
 * 字段对齐后端 RefundService::apply（type / return_details / return_tracking_no / return_express_company）。
 */

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const submitting = ref(false)
const tip = ref('')
const order = ref<OrderDetail | null>(null)

const orderId = computed(() => route.params.id as string)

/** 服务类型：仅退款 / 退货退款（退货退款为 WMS 退货闭环打基础） */
const SERVICE_TYPES = [
  { value: 'refund', label: '仅退款' },
  { value: 'return_refund', label: '退货退款' },
] as const
const serviceType = ref<string>('refund')

/** 常见退款原因（末项「其它」触发自定义输入框） */
const REASONS = [
  '不想要了 / 多拍误拍',
  '商品与描述不符',
  '商品质量问题',
  '商品破损 / 漏发',
  '发错货 / 少发',
  '物流太慢 / 长时间未送达',
  '价格问题（买贵了）',
  '其它',
] as const
const OTHER = '其它'

const selectedReason = ref<string>('')
const otherReason = ref<string>('')

const isOther = computed(() => selectedReason.value === OTHER)
/** 最终提交的原因：其它时取输入框内容 */
const finalReason = computed(() => (isOther.value ? otherReason.value.trim() : selectedReason.value))

/** 退货退款：逐行退货明细（由订单商品初始化，quantity=0 表示不退货） */
const returnItems = ref<Array<{
  sku_id: string | number | null
  product_title: string
  sku_specs: Record<string, string>
  sku_image: string | null
  max: number
  quantity: number
}>>([])

/** 寄回物流 */
const returnTrackingNo = ref<string>('')
const returnExpressCompany = ref<string>('')

/** 凭证图片（商品实拍等，≤9 张） */
const MAX_IMAGES = 9
const images = ref<string[]>([])
const uploading = ref(false)

async function onPickImage(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (images.value.length >= MAX_IMAGES) {
    tip.value = `最多上传 ${MAX_IMAGES} 张图片`
    return
  }
  uploading.value = true
  tip.value = ''
  try {
    const { data } = await uploadImage(file)
    images.value.push(data.data.url)
  } catch (err) {
    tip.value = err instanceof Error ? err.message : '图片上传失败'
  } finally {
    uploading.value = false
  }
}

function removeImage(i: number) {
  images.value.splice(i, 1)
}

const isReturn = computed(() => serviceType.value === 'return_refund')

/** 组装后端需要的 return_details（仅退货退款、且数量>0 的行） */
const returnDetails = computed(() =>
  returnItems.value
    .filter((it) => it.quantity > 0)
    .map((it) => ({
      sku_id: it.sku_id,
      quantity: it.quantity,
      product_title: it.product_title,
      sku_specs: it.sku_specs,
    })),
)

const canSubmit = computed(() => {
  if (submitting.value) return false
  if (selectedReason.value === '') return false
  if (isOther.value && otherReason.value.trim().length === 0) return false
  if (isReturn.value) {
    if (returnDetails.value.length === 0) return false
    if (returnTrackingNo.value.trim().length === 0) return false
  }
  return true
})

/** 切到退货退款时初始化明细，切回仅退款清空 */
watch(isReturn, (on) => {
  if (on && order.value) {
    returnItems.value = order.value.items.map((it) => ({
      sku_id: it.sku_id ?? null,
      product_title: it.product_title,
      sku_specs: it.sku_specs,
      sku_image: it.sku_image,
      max: it.quantity,
      quantity: it.quantity, // 默认整件退，用户可改
    }))
  } else {
    returnItems.value = []
    returnTrackingNo.value = ''
    returnExpressCompany.value = ''
  }
})

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
    const payload: Record<string, unknown> = {
      reason: finalReason.value || undefined,
      type: serviceType.value,
    }
    if (isReturn.value) {
      payload.return_details = returnDetails.value
      payload.return_tracking_no = returnTrackingNo.value.trim() || undefined
      payload.return_express_company = returnExpressCompany.value.trim() || undefined
    }
    if (images.value.length) {
      payload.images = images.value
    }
    await applyRefund(order.value.id, payload)
    router.push({ path: `/orders/${order.value.id}`, query: { refund_ok: '1' } })
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '申请失败，请稍后重试'
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
          <RotateCcw class="h-5 w-5 text-[#1677ff]" /> 申请售后
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

        <!-- 申请表单 -->
        <section class="rounded-xl border border-slate-100 bg-white p-5">
          <!-- 服务类型 -->
          <div class="mb-5">
            <label class="mb-2 block text-sm font-medium text-slate-700">服务类型</label>
            <div class="flex gap-2">
              <button
                v-for="t in SERVICE_TYPES"
                :key="t.value"
                class="rounded-full border px-4 py-1.5 text-sm transition-colors"
                :class="serviceType === t.value ? 'border-[#1677ff] bg-[#f0f7ff] text-[#1677ff]' : 'border-slate-200 text-slate-500 hover:border-[#1677ff]'"
                @click="serviceType = t.value"
              >{{ t.label }}</button>
            </div>
            <p v-if="isReturn" class="mt-2 rounded-md bg-[#fff7e6] px-3 py-2 text-xs text-[#d46b08]">
              退货退款需先将商品寄回并填写物流单号；商家确认收货后，退款将原路退回。
            </p>
          </div>

          <!-- 退货商品明细（仅退货退款） -->
          <div v-if="isReturn" class="mb-5">
            <label class="mb-2 block text-sm font-medium text-slate-700">退货商品</label>
            <div class="space-y-2">
              <div
                v-for="(it, i) in returnItems"
                :key="it.sku_id ?? i"
                class="flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2"
              >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-lg">
                  <img v-if="it.sku_image" :src="it.sku_image" class="h-full w-full object-cover" alt="" />
                  <span v-else>📦</span>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm text-slate-700">{{ it.product_title }}</p>
                  <p class="text-xs text-slate-400">{{ Object.values(it.sku_specs).join(' / ') || '默认规格' }}</p>
                </div>
                <div class="flex items-center gap-1 text-sm">
                  <span class="text-slate-400">退</span>
                  <input
                    v-model.number="it.quantity"
                    type="number"
                    min="0"
                    :max="it.max"
                    class="w-16 rounded-md border border-slate-300 px-2 py-1 text-center text-sm outline-none focus:border-[#1677ff]"
                    :data-testid="`return-qty-${i}`"
                  />
                  <span class="text-slate-400">/ {{ it.max }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- 退货物流（仅退货退款） -->
          <div v-if="isReturn" class="mb-5 grid gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="return-company">快递公司</label>
              <input
                id="return-company"
                v-model="returnExpressCompany"
                type="text"
                maxlength="64"
                placeholder="如：顺丰速运"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-[#1677ff]"
              />
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="return-tracking">物流单号 <span class="text-red-500">*</span></label>
              <input
                id="return-tracking"
                v-model="returnTrackingNo"
                type="text"
                maxlength="64"
                placeholder="寄回包裹的运单号"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-[#1677ff]"
                data-testid="return-tracking-no"
              />
            </div>
          </div>

          <!-- 退款原因下拉框 -->
          <div class="mb-5">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="refund-reason">退款原因 <span class="text-red-500">*</span></label>
            <select
              id="refund-reason"
              v-model="selectedReason"
              data-testid="refund-reason"
              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-[#1677ff]"
            >
              <option value="" disabled>请选择退款原因</option>
              <option v-for="r in REASONS" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>

          <!-- 其它原因输入框 -->
          <div v-if="isOther" class="mb-5">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="refund-other">其它原因 <span class="text-red-500">*</span></label>
            <textarea
              id="refund-other"
              v-model="otherReason"
              rows="3"
              maxlength="200"
              placeholder="请输入具体原因（200 字以内）"
              class="w-full resize-none rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-[#1677ff]"
            ></textarea>
            <p class="mt-1 text-right text-xs text-slate-400">{{ otherReason.length }}/200</p>
          </div>

          <!-- 凭证图片 -->
          <div class="mb-5">
            <label class="mb-2 block text-sm font-medium text-slate-700">凭证图片（可选，最多 {{ MAX_IMAGES }} 张）</label>
            <div class="flex flex-wrap items-center gap-2">
              <div
                v-for="(url, i) in images"
                :key="`img-${i}`"
                class="relative h-20 w-20 overflow-hidden rounded-lg border border-slate-200"
              >
                <a :href="url" target="_blank">
                  <img :src="url" class="h-full w-full object-cover" alt="凭证图" />
                </a>
                <button
                  type="button"
                  class="absolute right-0 top-0 rounded-bl bg-black/50 px-1 text-[10px] text-white"
                  :data-testid="`remove-image-${i}`"
                  @click="removeImage(i)"
                >×</button>
              </div>
              <label
                v-if="images.length < MAX_IMAGES"
                class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300 text-xs text-slate-400 hover:border-[#1677ff] hover:text-[#1677ff]"
              >
                <ImagePlus class="h-5 w-5" />
                {{ uploading ? '上传中' : '上传' }}
                <input type="file" accept="image/*" class="hidden" :disabled="uploading" data-testid="refund-image-input" @change="onPickImage" />
              </label>
            </div>
          </div>

          <!-- 说明 -->
          <div class="mb-6 flex items-start gap-2 rounded-lg bg-[#f0f7ff] px-3 py-2.5 text-xs text-slate-500">
            <MapPin class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#1677ff]" />
            <p>
              <template v-if="isReturn">提交后请尽快寄回商品；退款金额以商家审核结果为准，确认收货后原路退回。</template>
              <template v-else>提交后商家将尽快审核；退款金额以商家审核结果为准，退款将原路退回。</template>
            </p>
          </div>

          <button
            class="w-full rounded-full bg-[#1677ff] py-3 text-sm font-medium text-white transition-colors hover:bg-[#4096ff] disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!canSubmit"
            data-testid="refund-submit"
            @click="doSubmit"
          >{{ submitting ? '提交中…' : '提交申请' }}</button>
        </section>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
