<script setup lang="ts">
import { computed } from 'vue'
import type { CmsPageBlock } from '@/api/cms'
import AppImage from '@/components/AppImage.vue'

/**
 * 区块 · 图集（CMS-203）
 *
 * 字段 schema 真源在后端 `App\Support\CmsBlock::BLOCKS['gallery']`：
 * title / images(image_list, required)。
 *
 * 降级策略：一张图都没有则整块不渲染（不留空网格）。
 */
const props = defineProps<{ block: CmsPageBlock }>()

function str(key: string): string {
  const v = props.block.data[key]
  return v === null || v === undefined ? '' : String(v)
}

const title = computed(() => str('title'))

/** 剔掉空串（后台允许先加位置后传图） */
const images = computed(() => {
  const v = props.block.data.images
  return Array.isArray(v) ? (v as unknown[]).map((i) => String(i ?? '')).filter((u) => u !== '') : []
})
</script>

<template>
  <section v-if="images.length" class="mx-auto w-full max-w-4xl px-4 sm:px-6" data-testid="block-gallery">
    <div class="rounded-xl bg-white p-6 shadow-sm sm:p-8">
      <h2 v-if="title" class="mb-4 text-base font-semibold text-slate-800" data-testid="block-gallery-title">
        {{ title }}
      </h2>

      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <AppImage
          v-for="(url, index) in images"
          :key="index"
          :src="url"
          alt=""
          class="aspect-square w-full rounded-lg object-cover"
          :data-testid="`block-gallery-image-${index}`"
        />
      </div>
    </div>
  </section>
</template>
