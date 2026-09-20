<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref, watch, type Component } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getCmsPage, type CmsPageContent } from '@/api/cms'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { applySeo } from '@/composables/useSeo'
import { BRAND_PLACEHOLDER, setTitleBase } from '@/stores/site'

/**
 * 站点单页容器（CMS-112）
 *
 * 取路由 `params.slug` → 请求 `/cms/pages/{slug}` → 按返回的 `template` key 分发到
 * `views/pages/Page{Key}.vue` 模板组件。模板注册表由目录 glob 自动装配：
 * 后端 `CmsPageTemplate` 新增模板并补上对应组件后即自动接通（守卫测试保证一一对应），
 * 组件缺失或模板未知则走 404 分支 —— 前台永远不会渲染出空壳。
 *
 * 所有模板组件共用同一份 props 契约：`name` / `fields` / `html` / `blocks`
 * （`blocks` 只有区块化模板 `template=blocks` 用得上，但一律下发 —— 契约统一
 * 才不会让未声明的 prop 变成根元素上的垃圾属性）。
 *
 * 页面**公开可访问**（决策 D4），无需登录。
 */
const route = useRoute()
const router = useRouter()

/** 模板 key → 组件（key 取自文件名 Page{Key}.vue 并小写化，与后端 CmsPageTemplate 对齐） */
const pageModules = import.meta.glob('./pages/Page*.vue')
const templateRegistry: Record<string, Component> = {}
for (const path in pageModules) {
  const matched = /Page([A-Za-z0-9_]+)\.vue$/.exec(path)
  if (matched) {
    templateRegistry[matched[1].toLowerCase()] = defineAsyncComponent(
      pageModules[path] as () => Promise<{ default: Component }>,
    )
  }
}

const loading = ref(true)
const notFound = ref(false)
const page = ref<CmsPageContent | null>(null)

const templateComponent = computed<Component | null>(() =>
  page.value ? (templateRegistry[page.value.template] ?? null) : null,
)

/** 组件缺失也视为「页面不存在」——避免渲染空白 */
const unavailable = computed(() => notFound.value || !page.value || !templateComponent.value)

const fields = computed<Record<string, unknown>>(() => page.value?.fields ?? {})
const html = computed<Record<string, string>>(() => page.value?.html ?? {})
/** CMS-203：区块化单页的内容（固定模板单页为 []) */
const blocks = computed(() => page.value?.blocks ?? [])

async function load() {
  const slug = String(route.params.slug ?? '')
  if (!slug) { notFound.value = true; loading.value = false; return }

  loading.value = true
  notFound.value = false
  try {
    const { data } = await getCmsPage(slug)
    page.value = data.data
    // 浏览器标题用页面名（保留品牌占位符，由 setTitleBase 按站点名渲染）
    setTitleBase(`${data.data.name} · ${BRAND_PLACEHOLDER}`)
    // CMS-202：后台填了 SEO 就以它为准（含 description/keywords），否则保持上面的标题
    const seo = data.data.seo
    if (seo) applySeo({ title: seo.title, description: seo.description, keywords: seo.keywords })
  } catch {
    page.value = null
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
// 站内从 /p/about 跳到 /p/contact：组件复用不重新挂载，需手动重载
watch(() => route.params.slug, () => { if (route.name === 'page') load() })
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="flex-1" data-testid="cms-page">
      <LoadingSpinner v-if="loading" />

      <div v-else-if="unavailable" class="mx-auto w-full max-w-3xl px-4 py-24 text-center sm:px-6" data-testid="cms-page-not-found">
        <p class="text-base text-slate-500">页面不存在或已下线</p>
        <button
          class="mt-4 rounded-full border border-slate-200 bg-white px-6 py-2 text-sm text-slate-600 hover:text-[#1677ff]"
          data-testid="cms-page-back-home"
          @click="router.push('/')"
        >返回首页</button>
      </div>

      <component
        :is="templateComponent"
        v-else-if="page"
        :name="page.name"
        :fields="fields"
        :html="html"
        :blocks="blocks"
      />
    </main>

    <ShopFooter />
  </div>
</template>
