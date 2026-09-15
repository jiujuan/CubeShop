<script setup lang="ts">
import { ShoppingCart } from 'lucide-vue-next'
import { useRouter } from 'vue-router'
import type { ProductBrief } from '@/api/types'

/**
 * 商品卡片（按原型：左上角标签 + 图 + 标题/已售 + 价格 + 小号加购按钮，整卡可点进详情）
 */
const props = defineProps<{
  product: ProductBrief
  tag?: 'hot' | 'new' | null
}>()

const router = useRouter()

function fmtSales(n: number) {
  return n >= 10000 ? `${(n / 10000).toFixed(1)}万+` : `${n}+`
}

function goDetail() {
  router.push(`/product/${props.product.id}`)
}

const emojiByIndex = ['👕', '🎧', '🥤', '⌨️', '👟', '🧴', '💻', '📦', '🎒', '☕']
</script>

<template>
  <div
    class="group relative flex cursor-pointer gap-3 rounded-xl border border-slate-100 bg-white p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
    @click="goDetail"
  >
    <!-- 左上角标签（压在图片上） -->
    <span
      v-if="tag"
      class="absolute left-0 top-0 z-10 rounded-tl-xl rounded-br-lg px-1.5 py-0.5 text-[11px] font-medium leading-none text-white"
      :class="tag === 'hot' ? 'bg-[#ff4d4f]' : 'bg-[#1677ff]'"
    >{{ tag === 'hot' ? '热销' : '新品' }}</span>

    <!-- 图 -->
    <div class="flex h-24 w-24 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-[#f0f7ff] to-[#e6f4ff] text-4xl">
      <img v-if="product.main_image" :src="product.main_image" class="h-full w-full rounded-lg object-cover" alt="" />
      <span v-else>{{ emojiByIndex[product.id % emojiByIndex.length] }}</span>
    </div>

    <!-- 信息 -->
    <div class="flex min-w-0 flex-1 flex-col">
      <div class="truncate font-semibold text-slate-800 group-hover:text-[#1677ff]">{{ product.title }}</div>
      <div class="mt-0.5 truncate text-xs text-slate-400">已售 {{ fmtSales(product.sales_count) }}</div>

      <div class="mt-auto flex items-end justify-between gap-2">
        <div class="text-lg font-bold text-[#ff4d4f]"><span class="text-xs">¥</span>{{ product.price }}</div>
        <button
          class="flex shrink-0 items-center gap-1 rounded-md border border-[#1677ff] px-2 py-1 text-[11px] leading-none text-[#1677ff] transition-colors hover:bg-[#1677ff] hover:text-white"
          title="加入购物车"
          @click.stop
        >
          <ShoppingCart class="h-3 w-3" /> 加购
        </button>
      </div>
    </div>
  </div>
</template>
