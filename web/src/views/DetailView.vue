<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Minus, Plus, ShoppingCart, Truck } from 'lucide-vue-next'
import { getProduct, type ProductDetail } from '@/api/shop'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

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

const activePrice = computed(() => matchedSku.value?.price ?? product.value?.price ?? '0.00')
const activeStock = computed(() => matchedSku.value?.stock ?? product.value?.total_stock ?? 0)
const gallery = computed(() => {
  const imgs = product.value?.images?.length ? product.value.images : []
  return product.value?.main_image ? [product.value.main_image, ...imgs] : imgs
})

onMounted(load)

async function load() {
  loading.value = true
  errorMsg.value = ''
  try {
    const { data } = await getProduct(route.params.id as string)
    product.value = data.data
    currentImage.value = data.data.main_image ?? data.data.images[0] ?? ''
    // 默认选中每个维度第一个值（若存在）
    const firstSku = data.data.skus[0]
    if (firstSku?.specs) selectedSpecs.value = { ...firstSku.specs }
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '商品不存在或已下架'
  } finally {
    loading.value = false
  }
}

function pickSpec(dim: string, value: string) {
  selectedSpecs.value = { ...selectedSpecs.value, [dim]: value }
}

function addToCart() {
  // P3（购物车）尚未实现，此处占位
  alert('购物车功能将在 P3 阶段（库存、购物车与地址）上线')
}

const emojiByIndex = ['👕', '🎧', '🥤', '⌨️', '👟', '🧴', '💻', '📦']
</script>

<template>
  <div>
    <ShopHeader />

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
              <span v-else>{{ emojiByIndex[product.id % emojiByIndex.length] }}</span>
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
            </div>

            <!-- 规格选择 -->
            <div v-for="dim in specDims" :key="dim.name" class="mt-5 flex items-center gap-3 text-sm">
              <span class="w-16 text-slate-500">{{ dim.name }}</span>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="value in dim.values" :key="value"
                  class="rounded-lg border px-4 py-1.5 transition-colors"
                  :class="selectedSpecs[dim.name] === value
                    ? 'border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]'
                    : 'border-slate-200 text-slate-600 hover:border-[#1677ff]'"
                  @click="pickSpec(dim.name, value)"
                >{{ value }}</button>
              </div>
            </div>

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

            <!-- 操作 -->
            <div class="mt-8 flex items-center gap-3">
              <button
                class="flex items-center gap-2 rounded-full border-2 border-[#1677ff] px-8 py-3 font-medium text-[#1677ff] transition-colors hover:bg-[#e6f4ff] disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="activeStock <= 0"
                @click="addToCart"
              ><ShoppingCart class="h-5 w-5" /> 加入购物车</button>
              <button
                class="rounded-full bg-[#1677ff] px-8 py-3 font-medium text-white transition-colors hover:bg-[#4096ff] disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="activeStock <= 0"
                @click="addToCart"
              >立即购买</button>
            </div>

            <div class="mt-6 flex items-center gap-2 text-xs text-slate-400">
              <Truck class="h-4 w-4" /> 全场满 99 元包邮 · 7 天无理由退换
            </div>
          </div>
        </div>

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
