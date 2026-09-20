<script setup lang="ts">
import { computed } from 'vue'
import type { CmsPageBlock } from '@/api/cms'

/**
 * 区块 · 首屏横幅（CMS-203）
 *
 * 字段 schema 真源在后端 `App\Support\CmsBlock::BLOCKS['hero']`：
 * title(required) / subtitle / image / button_text / button_link。
 *
 * 降级策略：缺图用品牌渐变底（不留空白区）；按钮文案与链接缺任一则不渲染按钮；
 * 标题为空（required 缺失时的兜底）整块不渲染，避免留下一条空横幅。
 */
const props = defineProps<{ block: CmsPageBlock }>()

function str(key: string): string {
  const v = props.block.data[key]
  return v === null || v === undefined ? '' : String(v)
}

const title = computed(() => str('title'))
const subtitle = computed(() => str('subtitle'))
const image = computed(() => str('image'))
const buttonText = computed(() => str('button_text'))
const buttonLink = computed(() => str('button_link'))

/** 站外链接走 <a>，站内路径走 RouterLink（避免把 https://… 当路由解析） */
const isExternal = computed(() => /^https?:\/\//i.test(buttonLink.value))
</script>

<template>
  <header v-if="title" class="relative h-56 overflow-hidden sm:h-72" data-testid="block-hero">
    <img v-if="image" :src="image" alt="" class="h-full w-full object-cover" />
    <div v-else class="h-full w-full bg-gradient-to-r from-[#1677ff] to-[#69b1ff]" />
    <div v-if="image" class="absolute inset-0 bg-black/35" />

    <div class="absolute inset-0 flex flex-col items-center justify-center px-4 text-center text-white">
      <h1 class="text-2xl font-bold tracking-wide sm:text-3xl" data-testid="block-hero-title">{{ title }}</h1>
      <p v-if="subtitle" class="mt-3 max-w-2xl text-sm leading-6 text-white/90" data-testid="block-hero-subtitle">
        {{ subtitle }}
      </p>

      <a
        v-if="buttonText && buttonLink && isExternal"
        :href="buttonLink"
        class="mt-6 rounded-full bg-white px-6 py-2 text-sm font-medium text-[#1677ff] hover:bg-white/90"
        data-testid="block-hero-button"
      >{{ buttonText }}</a>
      <RouterLink
        v-else-if="buttonText && buttonLink"
        :to="buttonLink"
        class="mt-6 rounded-full bg-white px-6 py-2 text-sm font-medium text-[#1677ff] hover:bg-white/90"
        data-testid="block-hero-button"
      >{{ buttonText }}</RouterLink>
    </div>
  </header>
</template>
