<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { MapPin, NotebookPen, Plus } from 'lucide-vue-next'
import { getAddresses, getCart, type CartItemView, type Address } from '@/api/user'
import { createOrder, type CreateOrderResult } from '@/api/order'
import AddressForm from '@/components/AddressForm.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 结算页（Roadmap P4）：选地址、备注、金额明细、提交订单
 * V1.1 E04 / T-029：地址选择弹层支持内联新增，保存后自动选中且不丢失备注
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
const payAmount = computed(() =>
  (Number(goodsAmount.value) + Number(freightAmount.value)).toFixed(2),
)

async function load() {
  loading.value = true
  try {
    const [addrRes, cartRes] = await Promise.all([getAddresses(), getCart()])
    addresses.value = addrRes.data.data
    validItems.value = cartRes.data.data.items.filter((item) => item.valid)

    const preferred =
      addresses.value.find((addr) => addr.is_default) ?? addresses.value[0] ?? null
    selectedAddressId.value = preferred?.id ?? null
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (auth.token) await load()
  else loading.value = false
})

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
  try {
    const { data } = await createOrder({
      address_id: selectedAddressId.value,
      remark: remark.value.trim() || undefined,
    })
    const result: CreateOrderResult = data.data
    router.replace(`/orders/${result.order_id}/pay`)
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '下单失败，请稍后重试'
  } finally {
    submitting.value = false
  }
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
            <div class="flex justify-between text-slate-600">
              <span>运费</span>
              <span>{{ freightAmount === '0.00' ? '包邮' : `¥${freightAmount}` }}</span>
            </div>
            <div class="flex justify-between border-t border-slate-100 pt-3">
              <span class="text-slate-700">应付总额</span>
              <span class="text-xl font-bold text-[#ff4d4f]">¥{{ payAmount }}</span>
            </div>
            <button
              class="mt-2 w-full rounded-full bg-[#1677ff] py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4096ff] disabled:cursor-not-allowed disabled:opacity-50"
              data-testid="checkout-submit"
              :disabled="submitting || !validItems.length || !selectedAddressId"
              @click="submit"
            >{{ submitting ? '提交中…' : '提交订单' }}</button>
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
    </Teleport>

    <ShopFooter />
  </div>
</template>
