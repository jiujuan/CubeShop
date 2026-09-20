<script setup lang="ts">
import { computed } from 'vue'
import type { CmsPageBlock } from '@/api/cms'

/**
 * 区块 · 图文分栏（CMS-203）
 *
 * 字段 schema 真源在后端 `App\Support\CmsBlock::BLOCKS['text_image']`：
 * title / text(markdown, required) / image / side(select: left|right)。
 *
 * 降级策略：没有配图时正文占满整行（不保留半栏空白）；正文为空整块不渲染。
 */
const props = defineProps<{ block: CmsPageBlock }>()

function str(key: string): string {
  const v = props.block.data[key]
  return v === null || v === undefined ? '' : String(v)
}

const title = computed(() => str('title'))
const image = computed(() => str('image'))
const textHtml = computed(() => props.block.html.text ?? '')

/** side=right 时图片移到右侧；无图时退化为单列 */
const imageFirst = computed(() => str('side') !== 'right')
</script>

<template>
  <section v-if="textHtml" class="mx-auto w-full max-w-4xl px-4 sm:px-6" data-testid="block-text-image">
    <div class="rounded-xl bg-white p-6 shadow-sm sm:p-8">
      <h2 v-if="title" class="mb-4 text-base font-semibold text-slate-800" data-testid="block-text-image-title">
        {{ title }}
      </h2>

      <div :class="image ? 'grid items-center gap-6 md:grid-cols-2' : ''">
        <div v-if="image" :class="imageFirst ? '' : 'md:order-2'" data-testid="block-text-image-media">
          <img :src="image" alt="" class="w-full rounded-lg object-cover" />
        </div>
        <div
          class="cms-prose break-words text-sm leading-7 text-slate-600"
          :class="image && !imageFirst ? 'md:order-1' : ''"
          data-testid="block-text-image-body"
          v-html="textHtml"
        />
      </div>
    </div>
  </section>
</template>
