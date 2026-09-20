<script setup lang="ts">
import { computed } from 'vue'
import { Clock, Mail, MapPin, Phone } from 'lucide-vue-next'
import type { CmsPageBlock } from '@/api/cms'

/**
 * 单页模板 · 联系我们（CMS-112）
 *
 * 版式预设计，内容由后台「内容管理 → 联系我们」维护（模板 key = contact）。
 * 字段 schema 真源在后端 `App\Support\CmsPageTemplate::TEMPLATES['contact']`：
 * address(text,required) / phone / email / work_time / map_image(image) / intro(markdown)。
 *
 * 联系方式按「填了才显示」渲染（address 必填，其余可选），避免出现空图标行。
 */
const props = defineProps<{
  name: string
  fields: Record<string, unknown>
  html: Record<string, string>
  /** CMS-203：区块化模板才用得到；固定模板组件同样声明，保持 props 契约统一 */
  blocks?: CmsPageBlock[]
}>()

function str(key: string): string {
  const v = props.fields[key]
  return v === null || v === undefined ? '' : String(v)
}

/** 联系方式条目：只保留有值的 */
const contacts = computed(() =>
  [
    { key: 'address', label: '公司地址', value: str('address'), icon: MapPin },
    { key: 'phone', label: '联系电话', value: str('phone'), icon: Phone },
    { key: 'email', label: '客服邮箱', value: str('email'), icon: Mail },
    { key: 'work_time', label: '工作时间', value: str('work_time'), icon: Clock },
  ].filter((c) => c.value !== ''),
)

const mapImage = computed(() => str('map_image'))
const introHtml = computed(() => props.html.intro ?? '')
</script>

<template>
  <div data-testid="page-contact">
    <header class="bg-gradient-to-r from-[#1677ff] to-[#69b1ff] py-12 text-center text-white">
      <h1 class="text-2xl font-bold tracking-wide sm:text-3xl">{{ name }}</h1>
      <p class="mt-2 text-sm text-white/85">有任何疑问，欢迎随时与我们联系</p>
    </header>

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-8 sm:px-6">
      <!-- 联系方式 -->
      <section v-if="contacts.length" class="grid gap-4 sm:grid-cols-2" data-testid="page-contact-info">
        <div
          v-for="c in contacts"
          :key="c.key"
          class="flex items-start gap-3 rounded-xl bg-white p-5 shadow-sm"
          :data-testid="`page-contact-${c.key}`"
        >
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#e6f4ff] text-[#1677ff]">
            <component :is="c.icon" class="h-4 w-4" />
          </span>
          <div class="min-w-0">
            <p class="text-xs text-slate-400">{{ c.label }}</p>
            <p class="mt-1 break-words text-sm leading-6 text-slate-700">{{ c.value }}</p>
          </div>
        </div>
      </section>

      <!-- 地图 -->
      <section v-if="mapImage" class="overflow-hidden rounded-xl bg-white p-2 shadow-sm" data-testid="page-contact-map">
        <img :src="mapImage" alt="公司位置" class="w-full rounded-lg object-cover" />
      </section>

      <!-- 补充说明 -->
      <section v-if="introHtml" class="rounded-xl bg-white p-6 shadow-sm sm:p-8" data-testid="page-contact-intro">
        <div class="cms-prose break-words text-sm leading-7 text-slate-600" v-html="introHtml" />
      </section>

      <!-- 全空兜底 -->
      <p
        v-if="!contacts.length && !mapImage && !introHtml"
        class="rounded-xl bg-white py-16 text-center text-sm text-slate-400 shadow-sm"
        data-testid="page-contact-empty"
      >暂无联系方式，请在后台「内容管理」中补充</p>
    </div>
  </div>
</template>
