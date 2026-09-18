<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Heart, Minus, Plus, ShoppingCart, Truck } from 'lucide-vue-next'
import { getProduct, type ProductDetail } from '@/api/shop'
import { addToCart } from '@/api/user'
import { favoriteProduct, trackProduct, unfavoriteProduct } from '@/api/favorite'
import { getCouponCenter, type ReceivableCoupon } from '@/api/coupon'
import { couponConditionText, couponValueText } from '@/utils/coupon'
import { hashIndex } from '@/utils/id'
import { useAuthStore } from '@/stores/auth'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ReviewSection from '@/components/ReviewSection.vue'

/**
 * 商品详情页（图集 + 规格/SKU 选择 + 价格库存 + 加购）
 */
const route = useRoute()
const router = useRouter()

const product = ref<ProductDetail | null>(null)
const loading = ref(true)
const errorMsg = ref('')
const currentImage = ref('')
const quantity = ref(1)

/** 规格维度 → 值列表（从 SKU 提取） */
const specDims = computed(() => {
  const dims: Record<string, Set<string>> = {}
  for (const sku of product.value?.skus ?? []) {
    for (const [k, v] of Object.entries(sku.specs ?? {})) {
      (dims[k] ??= new Set()).add(v)
    }
  }
  return Object.entries(dims).map(([name, values]) => ({ name, values: [...values] }))
})

const selectedSpecs = ref<Record<string, string>>({})

/** 当前选中规格匹配的 SKU */
const matchedSku = computed(() => {
  const specs = selectedSpecs.value
  if (!Object.keys(specs).length) return null
  return (product.value?.skus ?? []).find((sku) =>
    Object.entries(specs).every(([k, v]) => sku.specs?.[k] === v),
  ) ?? null
})

/**
 * 某维度值是否可选（V1.1 T-013）
 *
 * 在「其他维度已选值固定」的前提下，若不存在库存 > 0 的 SKU 组合，则该值置灰。
 */
function isValueAvailable(dim: string, value: string): boolean {
  const others = Object.entries(selectedSpecs.value).filter(([k]) => k !== dim)
  return (product.value?.skus ?? []).some(
    (sku) =>
      (sku.stock ?? 0) > 0 &&
      sku.specs?.[dim] === value &&
      others.every(([k, v]) => sku.specs?.[k] === v),
  )
}

/** 当前组合已选中但无库存 */
const comboOutOfStock = computed(() => {
  if (!matchedSku.value) return false
  return (matchedSku.value.stock ?? 0) <= 0
})

const activePrice = computed(() => matchedSku.value?.price ?? product.value?.price ?? '0.00')
const activeStock = computed(() => matchedSku.value?.stock ?? product.value?.total_stock ?? 0)
const gallery = computed(() => {
  const imgs = product.value?.images?.length ? product.value.images : []
  return product.value?.main_image ? [product.value.main_image, ...imgs] : imgs
})

/** 商品参数表（V1.1 T-013）：品牌 + 参数类属性 */
const paramRows = computed(() => {
  const rows: Array<{ label: string; value: string }> = []
  if (product.value?.brand?.name) rows.push({ label: '品牌', value: product.value.brand.name })
  for (const item of product.value?.attributes ?? []) {
    rows.push({ label: item.name, value: item.value })
  }
  if (product.value?.weight) rows.push({ label: '重量', value: `${product.value.weight} g` })
  return rows
})

onMounted(load)

async function load() {
  loading.value = true
  errorMsg.value = ''
  try {
    const { data } = await getProduct(route.params.id as string)
    product.value = data.data
    currentImage.value = data.data.main_image ?? data.data.images[0] ?? ''
    isFavorited.value = !!data.data.is_favorited
    // 默认选中第一个有库存的组合，避免用户看到「未选规格」或一进来就是缺货
    const skus = data.data.skus ?? []
    const preferred = skus.find((s) => (s.stock ?? 0) > 0) ?? skus[0]
    if (preferred?.specs) selectedSpecs.value = { ...preferred.specs }
    // 浏览足迹上报（V1.1 F05 / T-024）：登录用户才记录，失败静默
    if (auth.token) {
      trackProduct(data.data.id).catch(() => {})
    }
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '商品不存在或已下架'
  } finally {
    loading.value = false
  }
  loadApplicableCoupons()
}

/** 详情页「领券」小标（V1.1 二期 T-038）：命中本商品的在领券列表即展示，失败静默 */
async function loadApplicableCoupons() {
  const productId = product.value?.id
  if (!productId) return
  try {
    const { data } = await getCouponCenter()
    const list = data.data.list ?? []
    applicableCoupons.value = list.filter((c) => {
      if (c.remaining <= 0 || !c.can_receive) return false
      if (c.scope === 'all') return true
      if (c.scope === 'product') return c.scope_refs.includes(productId)
      if (c.scope === 'category') return !!product.value?.category && c.scope_refs.includes(product.value.category.id)
      return false
    }).slice(0, 3)
  } catch {
    applicableCoupons.value = []
  }
}

/** 收藏状态（V1.1 F05 / T-024） */
const isFavorited = ref(false)

/** 命中本商品的在领券列表（V1.1 二期 T-038，最多展示 3 张） */
const applicableCoupons = ref<ReceivableCoupon[]>([])
const favBusy = ref(false)

async function toggleFavorite() {
  if (!auth.token) {
    router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }
  if (favBusy.value || !product.value) return
  favBusy.value = true
  const next = !isFavorited.value
  try {
    if (next) await favoriteProduct(product.value.id)
    else await unfavoriteProduct(product.value.id)
    isFavorited.value = next
    // 同步卡片/足迹列表
    ;(headerRef.value as { refreshFavCount?: () => void } | null)?.refreshFavCount?.()
  } catch (e) {
    cartTipType.value = 'err'
    cartTip.value = e instanceof Error ? e.message : '操作失败'
  } finally {
    favBusy.value = false
  }
}

/** 切换规格：同步数量上限，避免残留超量 */
function pickSpec(dim: string, value: string) {
  if (!isValueAvailable(dim, value)) return
  selectedSpecs.value = { ...selectedSpecs.value, [dim]: value }
  if (quantity.value > activeStock.value && activeStock.value > 0) {
    quantity.value = activeStock.value
  }
}

const auth = useAuthStore()

/** 加入购物车：未登录跳登录（带回跳）；库存不足/规格未选给出明确提示 */
const cartTip = ref('')
const cartTipType = ref<'ok' | 'err'>('ok')
const adding = ref(false)

async function handleAddToCart() {
  cartTip.value = ''
  if (!auth.token) {
    router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }
  const sku = matchedSku.value
  if (!sku) {
    cartTipType.value = 'err'
    cartTip.value = '请先选择完整的商品规格'
    return
  }
  if (quantity.value > sku.stock) {
    cartTipType.value = 'err'
    cartTip.value = '库存不足'
    return
  }

  adding.value = true
  try {
    await addToCart(sku.id, quantity.value)
    cartTipType.value = 'ok'
    cartTip.value = '已加入购物车'
    // 刷新顶栏角标
    ;(headerRef.value as { refreshCartCount: () => void } | null)?.refreshCartCount()
  } catch (e) {
    cartTipType.value = 'err'
    cartTip.value = e instanceof Error ? e.message : '加购失败'
  } finally {
    adding.value = false
  }
}

function buyNow() {
  handleAddToCart().then(() => {
    if (cartTipType.value === 'ok') router.push('/cart')
  })
}

const headerRef = ref<InstanceType<typeof ShopHeader> | null>(null)

const emojiByIndex = ['👕', '🎧', '🥤', '⌨️', '👟', '🧴', '💻', '📦']
</script>

<template>
  <div>
    <ShopHeader ref="headerRef" />

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-8">
      <!-- 加载/错误 -->
      <div v-if="loading" class="py-24"><LoadingSpinner /></div>
      <div v-else-if="errorMsg || !product" class="flex flex-col items-center py-24 text-slate-400">
        <p class="mb-4">{{ errorMsg || '商品不存在' }}</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="router.push('/')">回到首页</button>
      </div>

      <template v-else>
        <div class="flex flex-wrap gap-10">
          <!-- 左：图集 -->
          <div class="w-full max-w-md">
            <div class="flex h-96 items-center justify-center rounded-2xl bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-8xl">
              <img v-if="currentImage" :src="currentImage" class="h-full w-full rounded-2xl object-cover" alt="" />
              <span v-else>{{ emojiByIndex[hashIndex(product.id, emojiByIndex.length)] }}</span>
            </div>
            <div class="mt-3 flex gap-2">
              <button
                v-for="(img, i) in gallery" :key="i"
                class="h-16 w-16 overflow-hidden rounded-lg border-2"
                :class="currentImage === img ? 'border-[#1677ff]' : 'border-transparent opacity-70 hover:opacity-100'"
                @click="currentImage = img"
              >
                <img :src="img" class="h-full w-full object-cover" alt="" />
              </button>
            </div>
          </div>

          <!-- 右：信息 -->
          <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold text-slate-800">{{ product.title }}</h1>
            <p class="mt-1 text-sm text-slate-400">{{ product.subtitle || '品质好物 · CubeShop 精选' }}</p>

            <!-- 价格 -->
            <div class="mt-5 rounded-xl bg-gradient-to-r from-[#fff1f0] to-[#fff7f0] px-5 py-4">
              <div class="flex items-baseline gap-3">
                <span class="text-sm text-[#ff4d4f]">价 格</span>
                <span class="text-3xl font-bold text-[#ff4d4f]">¥{{ activePrice }}</span>
                <span class="ml-auto text-xs text-slate-400">已售 {{ product.sales_count }} 件</span>
              </div>

              <!-- 领券小标（V1.1 二期 T-038）：命中本商品的在领券 -->
              <div v-if="applicableCoupons.length" class="mt-2 flex flex-wrap items-center gap-1.5" data-testid="detail-coupons">
                <span class="text-xs text-slate-400">领券</span>
                <button
                  v-for="c in applicableCoupons" :key="c.id"
                  class="rounded border border-[#ff4d4f]/40 bg-white/70 px-1.5 py-0.5 text-[11px] text-[#ff4d4f] transition-colors hover:bg-[#ff4d4f] hover:text-white"
                  :data-testid="`detail-coupon-${c.id}`"
                  @click="router.push('/coupons/center')"
                >{{ couponValueText(c) }} · {{ couponConditionText(c) }}</button>
                <button class="text-[11px] text-[#1677ff] hover:underline" data-testid="detail-coupon-more" @click="router.push('/coupons/center')">更多 ›</button>
              </div>
            </div>

            <!-- 规格选择（V1.1 T-013：不可选值置灰） -->
            <div v-for="dim in specDims" :key="dim.name" class="mt-5 flex items-center gap-3 text-sm" :data-testid="`spec-dim-${dim.name}`">
              <span class="w-16 text-slate-500">{{ dim.name }}</span>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="value in dim.values" :key="value"
                  class="rounded-lg border px-4 py-1.5 transition-colors"
                  :class="[
                    selectedSpecs[dim.name] === value
                      ? 'border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]'
                      : 'border-slate-200 text-slate-600 hover:border-[#1677ff]',
                    !isValueAvailable(dim.name, value) ? 'cursor-not-allowed opacity-40 line-through hover:border-slate-200' : '',
                  ]"
                  :disabled="!isValueAvailable(dim.name, value)"
                  :data-testid="`spec-value-${dim.name}-${value}`"
                  :data-available="isValueAvailable(dim.name, value) ? '1' : '0'"
                  @click="pickSpec(dim.name, value)"
                >{{ value }}</button>
              </div>
            </div>

            <!-- 缺货提示 -->
            <p
              v-if="comboOutOfStock"
              class="mt-3 text-xs text-[#ff4d4f]"
              data-testid="spec-out-of-stock"
            >该规格暂时缺货，请选择其他规格</p>

            <!-- 库存 + 数量 -->
            <div class="mt-5 flex items-center gap-6 text-sm">
              <div class="flex items-center gap-2">
                <span class="w-16 text-slate-500">库存</span>
                <span class="font-medium" :class="activeStock > 0 ? 'text-slate-700' : 'text-red-500'">
                  {{ activeStock > 0 ? `${activeStock} 件` : '暂时缺货' }}
                </span>
              </div>
              <div class="flex items-center gap-2">
                <span class="w-16 text-slate-500">数量</span>
                <div class="flex items-center rounded-lg border border-slate-200">
                  <button class="px-2.5 py-1.5 text-slate-500 hover:text-[#1677ff]" :disabled="quantity <= 1" @click="quantity--">
                    <Minus class="h-4 w-4" />
                  </button>
                  <span class="w-10 text-center font-medium">{{ quantity }}</span>
                  <button class="px-2.5 py-1.5 text-slate-500 hover:text-[#1677ff]" :disabled="quantity >= activeStock" @click="quantity++">
                    <Plus class="h-4 w-4" />
                  </button>
                </div>
              </div>
            </div>

            <!-- 加购反馈 -->
            <p
              v-if="cartTip"
              class="mt-3 rounded-md px-3 py-2 text-xs"
              :class="cartTipType === 'ok' ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-red-50 text-red-500'"
            >{{ cartTip }}</p>

            <!-- 操作 -->
            <div class="mt-4 flex items-center gap-3">
              <button
                class="flex items-center gap-2 rounded-full border-2 border-[#1677ff] px-8 py-3 font-medium text-[#1677ff] transition-colors hover:bg-[#e6f4ff] disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="activeStock <= 0 || adding"
                @click="handleAddToCart"
              ><ShoppingCart class="h-5 w-5" /> 加入购物车</button>
              <button
                class="rounded-full bg-[#1677ff] px-8 py-3 font-medium text-white transition-colors hover:bg-[#4096ff] disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="activeStock <= 0 || adding"
                @click="buyNow"
              >立即购买</button>
              <button
                class="flex items-center gap-2 rounded-full border px-6 py-3 text-sm transition-colors disabled:opacity-50"
                :class="isFavorited
                  ? 'border-[#ff4d4f] bg-[#fff1f0] text-[#ff4d4f]'
                  : 'border-slate-200 text-slate-500 hover:border-[#ff4d4f] hover:text-[#ff4d4f]'"
                :disabled="favBusy"
                data-testid="favorite-btn"
                @click="toggleFavorite"
              >
                <Heart class="h-5 w-5" :fill="isFavorited ? 'currentColor' : 'none'" />
                {{ isFavorited ? '已收藏' : '收藏' }}
              </button>
            </div>

            <div class="mt-6 flex items-center gap-2 text-xs text-slate-400">
              <Truck class="h-4 w-4" /> 全场满 99 元包邮 · 7 天无理由退换
            </div>
          </div>
        </div>

        <!-- 用户评价（V1.1 F01 / T-016） -->
        <ReviewSection :product-id="product.id" />

        <!-- 商品参数（V1.1 T-013） -->
        <section v-if="paramRows.length" class="mt-12" data-testid="product-params">
          <h3 class="mb-4 text-lg font-bold text-slate-800">商品参数</h3>
          <div class="overflow-hidden rounded-xl border border-slate-100">
            <div
              v-for="row in paramRows" :key="row.label"
              class="flex border-b border-slate-50 text-sm last:border-0"
            >
              <span class="w-40 shrink-0 bg-slate-50/70 px-4 py-3 text-slate-500">{{ row.label }}</span>
              <span class="px-4 py-3 text-slate-700">{{ row.value }}</span>
            </div>
          </div>
        </section>

        <!-- 详情 -->
        <section class="mt-12 border-t border-slate-100 pt-8">
          <h3 class="mb-4 text-lg font-bold text-slate-800">商品详情</h3>
          <!-- 下架商品不渲染购买入口之外的内容区警示 -->
          <div v-if="product.status !== 1" class="mb-4 rounded-lg bg-orange-50 px-4 py-3 text-sm text-orange-500">
            该商品已下架，暂不可购买
          </div>
          <div class="prose prose-slate max-w-none text-sm leading-7 text-slate-600" v-html="product.description || '暂无详情'"></div>
        </section>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>
