<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, Eye, Megaphone } from 'lucide-vue-next'
import { getAnnouncement, type AnnouncementDetail } from '@/api/announcement'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 公告详情（P-Announcement，公开）
 *
 * 正文是后端渲染 + 净化后的安全 HTML（见 CsAnnouncement saving 钩子），此处用 v-html 渲染。
 * 净化器不放行 style/class，排版由本组件 scoped 样式统一样式。
 */
const route = useRoute()
const router = useRouter()

const id = route.params.id as string
const loading = ref(true)
const notFound = ref(false)
const announcement = ref<AnnouncementDetail | null>(null)

async function load() {
  loading.value = true
  notFound.value = false
  try {
    const { data } = await getAnnouncement(id)
    announcement.value = data.data.announcement
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 sm:px-6" data-testid="announcement-detail">
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/')">首页</button>
        <ChevronRight class="h-3 w-3" />
        <button class="hover:text-[#1677ff]" @click="router.push('/announcements')">公告</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">详情</span>
      </nav>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="notFound || !announcement" class="rounded-xl bg-white py-20 text-center" data-testid="announcement-not-found">
        <p class="text-sm text-slate-500">公告不存在或已下架</p>
        <button class="mt-3 rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:text-[#1677ff]" @click="router.push('/announcements')">返回公告列表</button>
      </div>

      <article v-else class="rounded-xl bg-white p-5 sm:p-7" data-testid="announcement-article">
        <div class="mb-2 flex items-center gap-2">
          <Megaphone v-if="announcement.is_top" class="h-4 w-4 text-[#1677ff]" />
          <span v-if="announcement.is_top" class="rounded bg-[#eaf4ff] px-1.5 py-0.5 text-xs text-[#1677ff]">置顶</span>
        </div>
        <h1 class="text-lg font-bold text-slate-800" data-testid="announcement-title">{{ announcement.title }}</h1>
        <p class="mt-2 flex items-center gap-3 text-xs text-slate-400">
          <span class="inline-flex items-center gap-1"><Eye class="h-3.5 w-3.5" /> 发布于 {{ announcement.published_at?.slice(0, 10) }}</span>
        </p>
        <div class="ann-body mt-5 break-words text-sm leading-7 text-slate-700" data-testid="announcement-content" v-html="announcement.content" />
      </article>
    </main>

    <ShopFooter />
  </div>
</template>

<style scoped>
.ann-body :deep(p) {
  margin: 0 0 0.75em;
}
.ann-body :deep(p:last-child) {
  margin-bottom: 0;
}
.ann-body :deep(h1),
.ann-body :deep(h2),
.ann-body :deep(h3),
.ann-body :deep(h4),
.ann-body :deep(h5),
.ann-body :deep(h6) {
  margin: 1.25em 0 0.5em;
  font-weight: 600;
  color: #1e293b;
  line-height: 1.4;
}
.ann-body :deep(h1) { font-size: 1.15rem; }
.ann-body :deep(h2) { font-size: 1.05rem; }
.ann-body :deep(h3),
.ann-body :deep(h4),
.ann-body :deep(h5),
.ann-body :deep(h6) { font-size: 0.9375rem; }
.ann-body :deep(ul),
.ann-body :deep(ol) {
  margin: 0.5em 0 0.75em;
  padding-left: 1.375rem;
}
.ann-body :deep(ul) { list-style: disc; }
.ann-body :deep(ol) { list-style: decimal; }
.ann-body :deep(li) { margin: 0.25em 0; }
.ann-body :deep(a) {
  color: #1677ff;
  text-decoration: underline;
  word-break: break-all;
}
.ann-body :deep(img) {
  max-width: 100%;
  height: auto;
  margin: 0.5em 0;
  border-radius: 0.5rem;
}
.ann-body :deep(table) {
  width: 100%;
  margin: 0.75em 0;
  border-collapse: collapse;
  font-size: 0.8125rem;
}
.ann-body :deep(th),
.ann-body :deep(td) {
  padding: 0.5rem 0.625rem;
  border: 1px solid #e2e8f0;
  text-align: left;
  vertical-align: top;
}
.ann-body :deep(th) {
  background: #f8fafc;
  font-weight: 600;
  color: #334155;
}
.ann-body :deep(blockquote) {
  margin: 0.75em 0;
  padding-left: 0.75rem;
  border-left: 3px solid #cbd5e1;
  color: #64748b;
}
.ann-body :deep(code) {
  padding: 0.1rem 0.3rem;
  border-radius: 0.25rem;
  background: #f1f5f9;
  font-size: 0.8125rem;
}
.ann-body :deep(pre) {
  margin: 0.75em 0;
  padding: 0.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  background: #f8fafc;
  overflow-x: auto;
}
.ann-body :deep(pre code) {
  padding: 0;
  background: transparent;
}
.ann-body :deep(hr) {
  margin: 1em 0;
  border: 0;
  border-top: 1px solid #e2e8f0;
}
.ann-body :deep(figcaption) {
  margin-top: 0.25em;
  font-size: 0.75rem;
  color: #94a3b8;
}
</style>
