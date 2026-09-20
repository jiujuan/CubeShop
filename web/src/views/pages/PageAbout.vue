<script setup lang="ts">
import { computed } from 'vue'
import { Building2, Flag, Sparkles } from 'lucide-vue-next'
import type { CmsPageBlock } from '@/api/cms'

/**
 * 单页模板 · 关于我们（CMS-112）
 *
 * 版式预设计，内容由后台「内容管理 → 关于我们」维护（模板 key = about）。
 * 字段 schema 真源在后端 `App\Support\CmsPageTemplate::TEMPLATES['about']`：
 * banner(image) / intro(markdown,required) / milestones(repeater) / values(repeater)。
 *
 * 缺图缺字段一律优雅降级：没有 banner 就用品牌渐变头图，空 repeater 不渲染整段。
 */
const props = defineProps<{
  name: string
  fields: Record<string, unknown>
  /** markdown 字段的渲染产物（后端已净化），按字段 key 取用 */
  html: Record<string, string>
  /** CMS-203：区块化模板才用得到；固定模板组件同样声明，保持 props 契约统一 */
  blocks?: CmsPageBlock[]
}>()

function str(key: string): string {
  const v = props.fields[key]
  return v === null || v === undefined ? '' : String(v)
}

/** 取 repeaters 行并剔掉全空行（后台可留空行） */
function rows(key: string): Array<Record<string, string>> {
  const v = props.fields[key]
  if (!Array.isArray(v)) return []

  return (v as Array<Record<string, string>>).filter((row) =>
    Object.values(row ?? {}).some((cell) => String(cell ?? '').trim() !== ''),
  )
}

const banner = computed(() => str('banner'))
const introHtml = computed(() => props.html.intro ?? '')
const milestones = computed(() => rows('milestones'))
const values = computed(() => rows('values'))
</script>

<template>
  <div data-testid="page-about">
    <!-- 头图：有图用图，无图用品牌渐变（不留空白区） -->
    <header class="relative h-44 overflow-hidden sm:h-56">
      <img v-if="banner" :src="banner" :alt="name" class="h-full w-full object-cover" />
      <div v-else class="h-full w-full bg-gradient-to-r from-[#1677ff] to-[#69b1ff]" />
      <div v-if="banner" class="absolute inset-0 bg-black/35" />
      <div class="absolute inset-0 flex flex-col items-center justify-center px-4 text-center text-white">
        <h1 class="text-2xl font-bold tracking-wide sm:text-3xl">{{ name }}</h1>
        <p class="mt-2 text-sm text-white/85">了解我们，与更好的购物体验同行</p>
      </div>
    </header>

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-8 sm:px-6">
      <!-- 公司简介 -->
      <section v-if="introHtml" class="rounded-xl bg-white p-6 shadow-sm sm:p-8" data-testid="page-about-intro">
        <h2 class="mb-4 flex items-center gap-2 text-base font-semibold text-slate-800">
          <Building2 class="h-4 w-4 text-[#1677ff]" /> 公司简介
        </h2>
        <div class="cms-prose break-words text-sm leading-7 text-slate-600" v-html="introHtml" />
      </section>

      <!-- 发展历程 -->
      <section v-if="milestones.length" class="rounded-xl bg-white p-6 shadow-sm sm:p-8" data-testid="page-about-milestones">
        <h2 class="mb-5 flex items-center gap-2 text-base font-semibold text-slate-800">
          <Flag class="h-4 w-4 text-[#1677ff]" /> 发展历程
        </h2>
        <ol class="relative border-l border-slate-200 pl-6">
          <li
            v-for="(m, i) in milestones"
            :key="i"
            class="relative pb-6 last:pb-0"
            :data-testid="`page-about-milestone-${i}`"
          >
            <span class="absolute -left-[31px] top-1 flex h-2.5 w-2.5 rounded-full bg-[#1677ff] ring-4 ring-white" />
            <p class="text-sm font-semibold text-[#1677ff]">{{ m.year }}</p>
            <p class="mt-0.5 text-sm leading-6 text-slate-600">{{ m.event }}</p>
          </li>
        </ol>
      </section>

      <!-- 企业价值观 -->
      <section v-if="values.length" class="rounded-xl bg-white p-6 shadow-sm sm:p-8" data-testid="page-about-values">
        <h2 class="mb-5 flex items-center gap-2 text-base font-semibold text-slate-800">
          <Sparkles class="h-4 w-4 text-[#1677ff]" /> 我们的坚持
        </h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <div
            v-for="(v, i) in values"
            :key="i"
            class="rounded-lg border border-slate-100 bg-slate-50/60 p-4"
            :data-testid="`page-about-value-${i}`"
          >
            <p class="text-sm font-semibold text-slate-700">{{ v.title }}</p>
            <p class="mt-1.5 text-sm leading-6 text-slate-500">{{ v.desc }}</p>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
