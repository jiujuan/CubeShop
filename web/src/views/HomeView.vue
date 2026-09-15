<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ChevronRight, Flame, Package, Sparkles } from 'lucide-vue-next'
import { getHot, type ProductBrief } from '@/api/shop'
import ProductCard from '@/components/ProductCard.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 首页（按原型：banner + 热销推荐 + 新品上架）
 */
const hot = ref<ProductBrief[]>([])
const newest = ref<ProductBrief[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    const { data } = await getHot(4)
    hot.value = data.data.hot
    newest.value = data.data.newest
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-4">
      <!-- Banner 轮播位 -->
      <section class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-[#dbeafe] via-[#e0f0ff] to-[#f0f9ff]">
        <div class="flex items-center justify-between px-12 py-14">
          <div>
            <h2 class="text-4xl font-bold leading-tight text-[#1668dc]">品质好物<br />限时特惠</h2>
            <p class="mt-3 text-slate-500">精选爆款低至 5 折</p>
            <button class="mt-5 flex items-center gap-1.5 rounded-full bg-[#1677ff] px-6 py-2.5 text-sm text-white transition-colors hover:bg-[#4096ff]">
              立即抢购 <ChevronRight class="h-4 w-4" />
            </button>
          </div>
          <div class="hidden select-none text-8xl opacity-80 md:block">🎒🧴☕</div>
        </div>
        <!-- 指示点 -->
        <div class="absolute bottom-4 left-1/2 flex -translate-x-1/2 gap-1.5">
          <span class="h-1.5 w-4 rounded-full bg-[#1677ff]"></span>
          <span class="h-1.5 w-1.5 rounded-full bg-white"></span>
          <span class="h-1.5 w-1.5 rounded-full bg-white"></span>
        </div>
      </section>

      <!-- 热销推荐 -->
      <section class="mt-8">
        <div class="mb-4 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#fff1f0]">
              <Flame class="h-4 w-4 text-[#ff4d4f]" />
            </span>
            <h3 class="text-lg font-bold text-slate-800">热销推荐</h3>
            <span class="text-xs text-slate-400">热门商品 · 超值好物</span>
          </div>
          <RouterLink to="/search?sort=sales_desc" class="flex items-center text-xs text-slate-400 hover:text-[#1677ff]">
            查看更多 <ChevronRight class="h-3.5 w-3.5" />
          </RouterLink>
        </div>
        <LoadingSpinner v-if="loading" />
        <template v-else>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
            <ProductCard v-for="p in hot" :key="p.id" :product="p" tag="hot" />
          </div>
          <div v-if="!hot.length" class="py-10 text-center text-sm text-slate-400">暂时无数据</div>
        </template>
      </section>

      <!-- 新品上架 -->
      <section class="mt-8 pb-10">
        <div class="mb-4 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#e6f4ff]">
              <Sparkles v-if="!hot.length" class="h-4 w-4 text-[#1677ff]" />
              <Package v-else class="h-4 w-4 text-[#1677ff]" />
            </span>
            <h3 class="text-lg font-bold text-slate-800">新品上架</h3>
            <span class="text-xs text-slate-400">最新上架 · 抢先体验</span>
          </div>
          <RouterLink to="/search" class="flex items-center text-xs text-slate-400 hover:text-[#1677ff]">
            查看更多 <ChevronRight class="h-3.5 w-3.5" />
          </RouterLink>
        </div>
        <LoadingSpinner v-if="loading" />
        <template v-else>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
            <ProductCard v-for="p in newest" :key="p.id" :product="p" tag="new" />
          </div>
          <div v-if="!newest.length" class="py-10 text-center text-sm text-slate-400">暂时无数据</div>
        </template>
      </section>
    </main>

    <ShopFooter />
  </div>
</template>
