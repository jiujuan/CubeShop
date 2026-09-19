<script setup lang="ts">
import { computed, onMounted, ref, watchEffect } from 'vue'
import { useRouter } from 'vue-router'
import { Minus, Plus, ShoppingCart, Trash2, Truck } from 'lucide-vue-next'
import { clearCart, getAddresses, getCart, removeCartItem, updateCartItem, type CartSummary } from '@/api/user'
import { estimateFreight, type FreightPreview } from '@/api/order'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

/**
 * 购物车页（数量修改 / 删除 / 勾选结算 / 失效提示 / 合计 / 运费预估）
 *
 * 2026-09-19 修复三点：
 * 1. 增删改**不再整页重载**（原来每次都 `load()` → loading 闪烁、运费重算、选中态丢失），
 *    改为「接口成功后就地更新本地 items」；金额由 items 派生 computed 自动重算。
 * 2. 角标同步：所有变更后 `cartStore.refresh()`，顶栏数量随之更新。
 * 3. 勾选结算：默认全选有效项，合计/已选按勾选项统计；去结算把 `cart_item_ids`
 *    带进结算页（后端 `createFromCart` 已支持子集，仅结算并移除所选行）。
 */
const auth = useAuthStore()
const cartStore = useCartStore()
const router = useRouter()

const cart = ref<CartSummary | null>(null)
const loading = ref(true)
const tip = ref('')

/** 勾选结算：仅「有效」项可勾选，默认全选 */
const selectedIds = ref<number[]>([])

const validItems = computed(() => (cart.value?.items ?? []).filter((i) => i.valid))
const selectedItems = computed(() => validItems.value.filter((i) => selectedIds.value.includes(i.id)))
const selectedQuantity = computed(() => selectedItems.value.reduce((sum, i) => sum + i.quantity, 0))
const allSelected = computed(() => validItems.value.length > 0 && selectedIds.value.length === validItems.value.length)
const someSelected = computed(() => selectedIds.value.length > 0 && !allSelected.value)

/** 金额一律按「分」累加，避免浮点误差（后端用 bcmath，口径一致） */
function toCents(value: string | number) {
  return Math.round(Number(value) * 100)
}
function centsToYuan(cents: number) {
  return (cents / 100).toFixed(2)
}

/** 合计金额/件数按勾选项实时派生 */
const selectedAmount = computed(() => centsToYuan(selectedItems.value.reduce((sum, i) => sum + toCents(i.price) * i.quantity, 0)))

const selectAllEl = ref<HTMLInputElement | null>(null)
watchEffect(() => {
  if (selectAllEl.value) selectAllEl.value.indeterminate = someSelected.value
})

function toggleItem(id: number) {
  selectedIds.value = selectedIds.value.includes(id)
    ? selectedIds.value.filter((x) => x !== id)
    : [...selectedIds.value, id]
}

function toggleAll() {
  selectedIds.value = allSelected.value ? [] : validItems.value.map((i) => i.id)
}

async function load() {
  loading.value = true
  try {
    const { data } = await getCart()
    cart.value = data.data
    const validIds = data.data.items.filter((i) => i.valid).map((i) => i.id)
    // 首次进入默认全选；重新加载时保留仍有效的既有选择
    selectedIds.value = selectedIds.value.length
      ? selectedIds.value.filter((id) => validIds.includes(id))
      : validIds
    void cartStore.refresh()
  } finally {
    loading.value = false
  }
}

/**
 * 运费预估（T-053 Stage 3）：按勾选的有效项 + 默认收货地址调公开预估接口，与下单同一套引擎。
 * 失败/无地址静默隐藏，不误导金额；精确运费在结算页按所选地址实时计算。
 */
const freightEstimate = ref<FreightPreview | null>(null)

const freightText = computed(() => {
  const est = freightEstimate.value
  if (!est) return ''
  if (est.not_support) return '含暂不可配送商品'
  if (est.free_shipping) return '已满包邮门槛'
  return `约 ¥${est.freight_amount}`
})

async function loadFreightEstimate() {
  freightEstimate.value = null
  const items = selectedItems.value
  if (!items.length) return
  try {
    // 默认地址用于 region 模板按省匹配；无地址走通用预估
    let addressId: number | undefined
    try {
      const { data: addrRes } = await getAddresses()
      addressId = addrRes.data.find((a) => a.is_default)?.id
    } catch { /* 地址拉取失败不影响预估 */ }
    const { data } = await estimateFreight({
      items: items.map((i) => ({ sku_id: i.sku_id, quantity: i.quantity })),
      address_id: addressId,
    })
    freightEstimate.value = data.data
  } catch {
    freightEstimate.value = null
  }
}

// 勾选变化 / 购物车载入后 → 运费预估按勾选项重算（watchEffect 已跟踪 selectedItems，
// 故 load() 内无需重复调用，避免重复请求）
watchEffect(() => {
  void loadFreightEstimate()
})

onMounted(() => {
  if (auth.token) load()
  else loading.value = false
})

/** 修改数量：接口成功后就地改本地行，不整页重载 */
async function changeQty(id: number, quantity: number) {
  if (quantity < 1) return
  tip.value = ''
  try {
    await updateCartItem(id, quantity)
    const item = cart.value?.items.find((i) => i.id === id)
    if (item) {
      item.quantity = quantity
      item.subtotal = centsToYuan(toCents(item.price) * quantity)
    }
    void cartStore.refresh()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '更新失败'
  }
}

/** 删除：接口成功后就地移除本地行 */
async function remove(id: number) {
  tip.value = ''
  try {
    await removeCartItem(id)
    const items = cart.value?.items
    if (items) {
      const index = items.findIndex((i) => i.id === id)
      if (index >= 0) items.splice(index, 1)
    }
    selectedIds.value = selectedIds.value.filter((x) => x !== id)
    void cartStore.refresh()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '删除失败'
  }
}

// 清空购物车二次确认（弹层）
const showClearDialog = ref(false)

async function doClear() {
  showClearDialog.value = false
  tip.value = ''
  try {
    await clearCart()
    if (cart.value) cart.value.items = []
    selectedIds.value = []
    void cartStore.refresh()
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '清空失败'
  }
}

/** 去结算：把勾选行 id 带进结算页，仅结算所选商品 */
function goCheckout() {
  if (!selectedIds.value.length) return
  router.push({ path: '/checkout', query: { cart_item_ids: selectedIds.value.join(',') } })
}
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-8">
      <h1 class="mb-6 text-xl font-bold text-slate-800">购物车</h1>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <ShoppingCart class="mb-3 h-10 w-10" />
        <p class="mb-4">登录后查看购物车</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push({ path: '/login', query: { redirect: '/cart' } })">去登录</button>
      </div>

      <template v-else-if="cart">
        <!-- 空车 -->
        <div v-if="!cart.items.length" class="flex flex-col items-center py-24 text-slate-400">
          <ShoppingCart class="mb-3 h-10 w-10" />
          <p class="mb-4">购物车还是空的</p>
          <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push('/')">去逛逛</button>
        </div>

        <template v-else>
          <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

          <!-- 表头 -->
          <div class="hidden grid-cols-[1fr_140px_100px_80px_60px] gap-4 border-b border-slate-200 px-4 pb-3 text-xs text-slate-400 md:grid">
            <span>商品信息</span><span class="text-center">单价</span><span class="text-center">数量</span><span class="text-center">小计</span><span class="text-center">操作</span>
          </div>

          <!-- 列表 -->
          <div
            v-for="item in cart.items" :key="item.id"
            class="grid grid-cols-1 gap-4 border-b border-slate-100 px-4 py-4 md:grid-cols-[1fr_140px_100px_80px_60px]"
            :class="item.valid ? '' : 'bg-slate-50/80'"
            :data-testid="`cart-item-${item.id}`"
          >
            <!-- 商品信息（勾选框 + 图 + 文案） -->
            <div class="flex items-center gap-4">
              <input
                v-if="item.valid"
                type="checkbox"
                class="h-4 w-4 shrink-0 cursor-pointer accent-[#1677ff]"
                :checked="selectedIds.includes(item.id)"
                :data-testid="`cart-check-${item.id}`"
                @change="toggleItem(item.id)"
              />
              <span v-else class="h-4 w-4 shrink-0" />
              <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-3xl">
                <img v-if="item.image" :src="item.image" class="h-full w-full rounded-lg object-cover" alt="" />
                <span v-else>📦</span>
              </div>
              <div class="min-w-0">
                <p class="truncate text-sm font-medium" :class="item.valid ? 'text-slate-700' : 'text-slate-400 line-through'">{{ item.title }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ Object.values(item.specs).join(' / ') || '默认规格' }}</p>
                <p v-if="!item.valid" class="mt-1 inline-block rounded bg-orange-100 px-1.5 py-0.5 text-[11px] text-orange-500">
                  {{ item.invalid_reason || '已失效' }}
                </p>
                <p v-if="item.valid && item.stock <= item.quantity" class="mt-1 text-[11px] text-orange-500">仅剩 {{ item.stock }} 件</p>
              </div>
            </div>

            <!-- 单价 -->
            <div class="flex items-center justify-center text-sm text-slate-600 md:justify-center">¥{{ item.price }}</div>

            <!-- 数量 -->
            <div class="flex items-center justify-center">
              <div v-if="item.valid" class="flex items-center rounded-lg border border-slate-200">
                <button class="px-2 py-1.5 text-slate-500 hover:text-[#1677ff] disabled:opacity-30" :disabled="item.quantity <= 1" @click="changeQty(item.id, item.quantity - 1)">
                  <Minus class="h-3.5 w-3.5" />
                </button>
                <span class="w-8 text-center text-sm">{{ item.quantity }}</span>
                <button class="px-2 py-1.5 text-slate-500 hover:text-[#1677ff] disabled:opacity-30" :disabled="item.quantity >= item.stock" @click="changeQty(item.id, item.quantity + 1)">
                  <Plus class="h-3.5 w-3.5" />
                </button>
              </div>
              <span v-else class="text-sm text-slate-300">{{ item.quantity }}</span>
            </div>

            <!-- 小计 -->
            <div class="flex items-center justify-center text-sm font-medium" :class="item.valid ? 'text-[#ff4d4f]' : 'text-slate-300'" :data-testid="`cart-subtotal-${item.id}`">
              ¥{{ item.subtotal }}
            </div>

            <!-- 操作 -->
            <div class="flex items-center justify-center">
              <button class="text-slate-400 hover:text-red-500" :data-testid="`cart-remove-${item.id}`" @click="remove(item.id)"><Trash2 class="h-4 w-4" /></button>
            </div>
          </div>

          <!-- 合计栏 -->
          <div class="sticky bottom-0 mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-100 bg-white px-6 py-4 shadow-lg">
            <div class="flex items-center gap-4 text-sm text-slate-500">
              <label class="flex cursor-pointer items-center gap-1.5 text-slate-600">
                <input
                  ref="selectAllEl"
                  type="checkbox"
                  class="h-4 w-4 cursor-pointer accent-[#1677ff]"
                  :checked="allSelected"
                  data-testid="cart-select-all"
                  @change="toggleAll"
                />
                全选
              </label>
              <button class="rounded-full border border-slate-200 bg-slate-100 px-4 py-1.5 text-xs text-slate-600 transition-colors hover:bg-slate-200 hover:text-red-500" @click="showClearDialog = true">清空购物车</button>
              <span>已选 <b class="text-[#1677ff]" data-testid="cart-selected-quantity">{{ selectedQuantity }}</b> 件</span>
            </div>
            <div class="flex items-center gap-6">
              <div class="text-sm">
                合计：<span class="text-xl font-bold text-[#ff4d4f]" data-testid="cart-total-amount">¥{{ selectedAmount }}</span>
                <span class="ml-1 text-xs text-slate-400">（失效商品不计入）</span>
              </div>
              <div
                v-if="freightText"
                class="flex items-center gap-1.5 text-xs text-slate-500"
                data-testid="cart-freight-estimate"
              >
                <Truck class="h-3.5 w-3.5" />
                <span :class="freightEstimate?.not_support ? 'text-orange-500' : ''">
                  运费预估<template v-if="freightEstimate && !freightEstimate.free_shipping && !freightEstimate.not_support">（不含在合计内）</template>：{{ freightText }}
                </span>
              </div>
              <button
                class="rounded-full bg-[#1677ff] px-8 py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#4096ff] disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="selectedQuantity <= 0"
                data-testid="cart-checkout"
                @click="goCheckout"
              >去结算</button>
            </div>
          </div>
        </template>
      </template>
    </main>

    <!-- 清空购物车二次确认弹层 -->
    <ConfirmDialog
      v-model="showClearDialog"
      title="清空购物车"
      content="确定清空购物车吗？清空后已加入的商品将全部移除。"
      confirm-text="确认清空"
      @confirm="doClear"
    />

    <ShopFooter />
  </div>
</template>
