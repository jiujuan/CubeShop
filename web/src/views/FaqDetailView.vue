<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, Eye, ThumbsDown, ThumbsUp } from 'lucide-vue-next'
import { getFaqArticle, postFaqFeedback, type FaqArticle } from '@/api/cs'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 帮助中心 · 文章详情（CS-112）
 *
 * 正文是**富文本 HTML**（设计文档 §4：支持图片、表格、锚点），此处用 v-html 渲染；
 * XSS 防线在**写入侧**：`CsFaqArticle::setContentAttribute()` 会经
 * `App\Support\HtmlSanitizer` 白名单净化后才落库（历史数据由迁移 000042 清洗），
 * 因此接口返回的 content 已是安全 HTML。排版由本组件 scoped 样式统一控制
 * （净化器刻意不放行 style/class，避免正文自带样式破坏页面）。
 *
 * 底部「是否有帮助」提交后本地置灰防重复；相关推荐由后端同分类返回（已排除自身）。
 */
const route = useRoute()
const router = useRouter()

const id = route.params.id as string
const loading = ref(true)
const notFound = ref(false)
const article = ref<FaqArticle | null>(null)
const related = ref<FaqArticle[]>([])
const submitted = ref(false)
const submitting = ref(false)
const feedbackTip = ref('')

async function load() {
  loading.value = true
  notFound.value = false
  try {
    const { data } = await getFaqArticle(id)
    article.value = data.data.article
    // 相关推荐剔除当前文章（后端已排除，前端兜底一次）
    related.value = data.data.related.filter((r) => String(r.id) !== String(id))
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

async function submitFeedback(helpful: boolean) {
  if (submitted.value || submitting.value) return
  submitting.value = true
  try {
    const { data } = await postFaqFeedback(id, helpful)
    if (article.value) {
      article.value.helpful_count = data.data.helpful_count
      article.value.unhelpful_count = data.data.unhelpful_count
    }
    submitted.value = true
    feedbackTip.value = '感谢您的反馈'
  } catch (e) {
    feedbackTip.value = e instanceof Error ? e.message : '提交失败'
  } finally {
    submitting.value = false
  }
}

function contactService() {
  router.push({ path: '/service-center/tickets/new', query: { title: article.value?.title ?? '' } })
}

onMounted(load)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 sm:px-6" data-testid="faq-detail">
      <!-- 面包屑 -->
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center')">服务中心</button>
        <ChevronRight class="h-3 w-3" />
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center/faq')">帮助中心</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">详情</span>
      </nav>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="notFound || !article" class="rounded-xl bg-white py-20 text-center" data-testid="faq-not-found">
        <p class="text-sm text-slate-500">文章不存在或已下架</p>
        <button class="mt-3 rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:text-[#1677ff]" @click="router.push('/service-center/faq')">返回帮助中心</button>
      </div>

      <template v-else>
        <article class="rounded-xl bg-white p-5 sm:p-7" data-testid="faq-article">
          <p v-if="article.category" class="mb-2 text-xs text-[#1677ff]">{{ article.category.name }}</p>
          <h1 class="text-lg font-bold text-slate-800" data-testid="faq-title">{{ article.title }}</h1>
          <p class="mt-2 flex items-center gap-3 text-xs text-slate-400">
            <span class="inline-flex items-center gap-1"><Eye class="h-3.5 w-3.5" /> {{ article.view_count }}</span>
            <span>有帮助 {{ article.helpful_count }} · 无帮助 {{ article.unhelpful_count }}</span>
          </p>
          <!-- 正文：富文本 HTML（写入侧已白名单净化，见文件头注释） -->
          <div class="faq-body mt-5 break-words text-sm leading-7 text-slate-700" data-testid="faq-content" v-html="article.content" />
        </article>

        <!-- 反馈 -->
        <section class="mt-4 rounded-xl bg-white p-5" data-testid="faq-feedback">
          <p class="mb-3 text-sm text-slate-600">这篇文章对您有帮助吗？</p>
          <div class="flex items-center gap-3">
            <button
              class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-5 py-2 text-sm text-slate-600 transition-colors hover:border-[#52c41a] hover:text-[#52c41a] disabled:cursor-not-allowed disabled:opacity-40"
              :disabled="submitted || submitting"
              data-testid="feedback-helpful"
              @click="submitFeedback(true)"
            ><ThumbsUp class="h-4 w-4" /> 有帮助</button>
            <button
              class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-5 py-2 text-sm text-slate-600 transition-colors hover:border-[#ff4d4f] hover:text-[#ff4d4f] disabled:cursor-not-allowed disabled:opacity-40"
              :disabled="submitted || submitting"
              data-testid="feedback-unhelpful"
              @click="submitFeedback(false)"
            ><ThumbsDown class="h-4 w-4" /> 没帮助</button>
            <span v-if="feedbackTip" class="text-xs text-slate-400" data-testid="feedback-tip">{{ feedbackTip }}</span>
          </div>
        </section>

        <!-- 没解决？联系客服 -->
        <section class="mt-4 flex items-center justify-between rounded-xl bg-white p-5">
          <div>
            <p class="text-sm text-slate-600">没有解决您的问题？</p>
            <p class="mt-0.5 text-xs text-slate-400">提交工单，客服会尽快与您联系</p>
          </div>
          <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]" data-testid="faq-contact-service" @click="contactService">联系客服</button>
        </section>

        <!-- 相关推荐 -->
        <section v-if="related.length" class="mt-4 rounded-xl bg-white p-5" data-testid="faq-related">
          <h2 class="mb-2 text-sm font-semibold text-slate-700">相关内容</h2>
          <ul class="divide-y divide-slate-100">
            <li v-for="r in related" :key="r.id">
              <button class="flex w-full items-center justify-between gap-2 py-3 text-left text-sm text-slate-600 hover:text-[#1677ff]" :data-testid="`related-${r.id}`" @click="router.push(`/service-center/faq/${r.id}`)">
                <span class="min-w-0 flex-1 truncate">{{ r.title }}</span>
                <ChevronRight class="h-3.5 w-3.5 shrink-0 text-slate-300" />
              </button>
            </li>
          </ul>
        </section>
      </template>
    </main>

    <ShopFooter />
  </div>
</template>

<style scoped>
/*
 * 富文本正文排版。
 * 净化器不放行 style/class，所以段落间距、列表符号、表格边框等必须由这里补齐
 * （Tailwind Preflight 会清掉默认的列表符号与标题字号）。
 */
.faq-body :deep(p) {
  margin: 0 0 0.75em;
}
.faq-body :deep(p:last-child) {
  margin-bottom: 0;
}
.faq-body :deep(h1),
.faq-body :deep(h2),
.faq-body :deep(h3),
.faq-body :deep(h4),
.faq-body :deep(h5),
.faq-body :deep(h6) {
  margin: 1.25em 0 0.5em;
  font-weight: 600;
  color: #1e293b;
  line-height: 1.4;
}
.faq-body :deep(h1) { font-size: 1.15rem; }
.faq-body :deep(h2) { font-size: 1.05rem; }
.faq-body :deep(h3),
.faq-body :deep(h4),
.faq-body :deep(h5),
.faq-body :deep(h6) { font-size: 0.9375rem; }
.faq-body :deep(ul),
.faq-body :deep(ol) {
  margin: 0.5em 0 0.75em;
  padding-left: 1.375rem;
}
.faq-body :deep(ul) { list-style: disc; }
.faq-body :deep(ol) { list-style: decimal; }
.faq-body :deep(li) { margin: 0.25em 0; }
.faq-body :deep(a) {
  color: #1677ff;
  text-decoration: underline;
  word-break: break-all;
}
.faq-body :deep(img) {
  max-width: 100%;
  height: auto;
  margin: 0.5em 0;
  border-radius: 0.5rem;
}
.faq-body :deep(table) {
  width: 100%;
  margin: 0.75em 0;
  border-collapse: collapse;
  font-size: 0.8125rem;
}
.faq-body :deep(th),
.faq-body :deep(td) {
  padding: 0.5rem 0.625rem;
  border: 1px solid #e2e8f0;
  text-align: left;
  vertical-align: top;
}
.faq-body :deep(th) {
  background: #f8fafc;
  font-weight: 600;
  color: #334155;
}
.faq-body :deep(blockquote) {
  margin: 0.75em 0;
  padding-left: 0.75rem;
  border-left: 3px solid #cbd5e1;
  color: #64748b;
}
.faq-body :deep(code) {
  padding: 0.1rem 0.3rem;
  border-radius: 0.25rem;
  background: #f1f5f9;
  font-size: 0.8125rem;
}
.faq-body :deep(pre) {
  margin: 0.75em 0;
  padding: 0.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  background: #f8fafc;
  overflow-x: auto;
}
.faq-body :deep(pre code) {
  padding: 0;
  background: transparent;
}
.faq-body :deep(hr) {
  margin: 1em 0;
  border: 0;
  border-top: 1px solid #e2e8f0;
}
.faq-body :deep(figcaption) {
  margin-top: 0.25em;
  font-size: 0.75rem;
  color: #94a3b8;
}
</style>
