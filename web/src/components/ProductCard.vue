<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ShoppingCart } from 'lucide-vue-next'
import { getProduct } from '@/api/shop'
import { addToCart } from '@/api/user'
import { useAuthStore } from '@/stores/auth'
import type { ProductBrief } from '@/api/types'

/**
 * 商品卡片（两种布局）
 * - horizontal：横向紧凑卡（首页/列表视图）
 * - vertical：竖版卡片（分类页网格，按原型：左上角标签 + 方图 + 标题/卖点 + 价格/已售 + 加购按钮）
 * 整卡可点进详情；加购按钮未登录跳登录，已登录默认加第一个 SKU ×1。
 */
const props = withDefaults(
  defineProps<{
    product: ProductBrief
    tag?: 'hot' | 'new' | null
    layout?: 'horizontal' | 'vertical'
  }>(),
  { tag: null, layout: 'horizontal' },
)

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const adding = ref(false)
const flash = ref<{ type: 'ok' | 'err'; text: string } | null>(null)
let flashTimer: ReturnType<typeof setTimeout> | undefined

function fmtSales(n: number) {
  return n >= 10000 ? `${(n / 10000).toFixed(1)}万+` : `${n}+`
}

function goDetail() {
  router.push(`/product/${props.product.id}`)
}

function showToast(type: 'ok' | 'err', text: string) {
  flash.value = { type, text }
  clearTimeout(flashTimer)
  flashTimer = setTimeout(() => (flash.value = null), 1800)
}

/** 快捷加购：未登录去登录；取第一个 SKU 加 1 件 */
async function quickAdd() {
  if (adding.value) return
  if (!auth.token) {
    router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }
  adding.value = true
  try {
    const { data } = await getProduct(props.product.id)
    const sku = data.data.skus?.[0]
    if (!sku) {
      showToast('err', '商品暂无规格')
      return
    }
    await addToCart(sku.id, 1)
    showToast('ok', '已加入购物车')
  } catch (e) {
    showToast('err', e instanceof Error ? e.message : '加购失败')
  } finally {
    adding.value = false
  }
}

const emojiByIndex = ['👕', '🎧', '🥤', '⌨️', '👟', '🧴', '💻', '📦', '🎒', '☕']
</script>

<template>
  <!-- 竖版卡片（分类页网格） -->
  <div
    v-if="layout === 'vertical'"
    class="group relative flex cursor-pointer flex-col overflow-hidden rounded-xl border border-slate-100 bg-white shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
    @click="goDetail"
  >
    <span
      v-if="tag"
      class="absolute left-0 top-0 z-10 rounded-br-lg bg-[#1677ff] px-2 py-1 text-[11px] font-medium leading-none text-white"
    >{{ tag === 'hot' ? '热销' : '新品' }}</span>

    <!-- 图 -->
    <div class="flex aspect-square items-center justify-center bg-gradient-to-br from-[#f5faff] to-[#e6f4ff]">
      <img v-if="product.main_image" :src="product.main_image" class="h-full w-full object-cover" alt="" />
      <span v-else class="text-6xl transition-transform group-hover:scale-105">{{ emojiByIndex[product.id % emojiByIndex.length] }}</span>
    </div>

    <!-- 信息 -->
    <div class="flex flex-1 flex-col gap-1 p-3">
      <div class="truncate text-sm font-semibold text-slate-800 group-hover:text-[#1677ff]">{{ product.title }}</div>
      <div class="truncate text-xs text-slate-400">{{ product.subtitle || '品质好物 · 官方直供' }}</div>

      <div class="mt-auto flex items-end justify-between pt-1.5">
        <div class="text-lg font-bold leading-6 text-[#ff4d4f]"><span class="text-xs">¥</span>{{ product.price }}</div>
        <div class="text-xs text-slate-400">已售 {{ fmtSales(product.sales_count) }}</div>
      </div>

      <div class="flex justify-end">
        <button
          class="flex items-center gap-1 rounded-full border border-[#1677ff] px-3 py-1 text-xs leading-none text-[#1677ff] transition-colors hover:bg-[#1677ff] hover:text-white disabled:opacity-60"
          :disabled="adding"
          title="加入购物车"
          @click.stop="quickAdd"
        >
          <ShoppingCart class="h-3 w-3" /> {{ adding ? '加购中…' : '加入购物车' }}
        </button>
      </div>
    </div>

    <!-- 轻提示 -->
    <transition
      enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-300" leave-to-class="opacity-0"
    >
      <div
        v-if="flash"
        class="absolute bottom-14 left-1/2 z-10 -translate-x-1/2 rounded-full px-3 py-1 text-xs text-white shadow"
        :class="flash.type === 'ok' ? 'bg-slate-800/90' : 'bg-[#ff4d4f]/95'"
      >{{ flash.text }}</div>
    </transition>
  </div>

  <!-- 横向紧凑卡（首页 / 列表视图，保持原样式） -->
  <div
    v-else
    class="group relative flex cursor-pointer gap-3 rounded-xl border border-slate-100 bg-white p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
    @click="goDetail"
  >
    <span
      v-if="tag"
      class="absolute left-0 top-0 z-10 rounded-tl-xl rounded-br-lg px-1.5 py-0.5 text-[11px] font-medium leading-none text-white"
      :class="tag === 'hot' ? 'bg-[#ff4d4f]' : 'bg-[#1677ff]'"
    >{{ tag === 'hot' ? '热销' : '新品' }}</span>

    <div class="flex h-24 w-24 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-4xl">
      <img v-if="product.main_image" :src="product.main_image" class="h-full w-full rounded-lg object-cover" alt="" />
      <span v-else>{{ emojiByIndex[product.id % emojiByIndex.length] }}</span>
    </div>

    <div class="flex min-w-0 flex-1 flex-col">
      <div class="truncate font-semibold text-slate-800 group-hover:text-[#1677ff]">{{ product.title }}</div>
      <div class="mt-0.5 truncate text-xs text-slate-400">已售 {{ fmtSales(product.sales_count) }}</div>

      <div class="mt-auto flex items-end justify-between gap-2">
        <div class="text-lg font-bold text-[#ff4d4f]"><span class="text-xs">¥</span>{{ product.price }}</div>
        <button
          class="flex shrink-0 items-center gap-1 rounded-md border border-[#1677ff] px-2 py-1 text-[11px] leading-none text-[#1677ff] transition-colors hover:bg-[#1677ff] hover:text-white disabled:opacity-60"
          :disabled="adding"
          title="加入购物车"
          @click.stop="quickAdd"
        >
          <ShoppingCart class="h-3 w-3" /> 加购
        </button>
      </div>
    </div>

    <transition
      enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-300" leave-to-class="opacity-0"
    >
      <div
        v-if="flash"
        class="absolute left-1/2 top-1/2 z-10 -translate-x-1/2 -translate-y-1/2 rounded-full px-3 py-1 text-xs text-white shadow"
        :class="flash.type === 'ok' ? 'bg-slate-800/90' : 'bg-[#ff4d4f]/95'"
      >{{ flash.text }}</div>
    </transition>
  </div>
</template>
