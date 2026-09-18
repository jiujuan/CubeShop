<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronRight, HelpCircle, Search } from 'lucide-vue-next'
import { getFaqCategories, type FaqCategory } from '@/api/cs'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'

/**
 * 帮助中心 · 分类页（CS-112）
 *
 * 一级分类 + 每类已发布文章数；顶部搜索框进入列表页搜索。
 */
const router = useRouter()
const loading = ref(true)
const keyword = ref('')
const categories = ref<FaqCategory[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await getFaqCategories()
    categories.value = data.data
  } finally {
    loading.value = false
  }
}

function goSearch() {
  const kw = keyword.value.trim()
  router.push({ path: '/service-center/faq/list', query: kw ? { keyword: kw } : {} })
}

onMounted(load)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 sm:px-6" data-testid="faq-category">
      <!-- 面包屑 -->
      <nav class="mb-4 flex items-center gap-1 text-xs text-slate-400">
        <button class="hover:text-[#1677ff]" @click="router.push('/service-center')">服务中心</button>
        <ChevronRight class="h-3 w-3" />
        <span class="text-slate-600">帮助中心</span>
      </nav>

      <div class="mb-4 flex items-center gap-2 rounded-full bg-white p-1 pl-4">
        <Search class="h-4 w-4 shrink-0 text-slate-400" />
        <input
          v-model="keyword"
          type="text"
          placeholder="搜索常见问题"
          class="min-w-0 flex-1 bg-transparent py-2 text-sm text-slate-700 outline-none"
          data-testid="faq-search-input"
          @keyup.enter="goSearch"
        />
        <button class="shrink-0 rounded-full bg-[#1677ff] px-5 py-2 text-sm text-white hover:bg-[#4096ff]" data-testid="faq-search-btn" @click="goSearch">搜索</button>
      </div>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="!categories.length" class="rounded-xl bg-white py-20 text-center text-sm text-slate-400" data-testid="category-empty">
        暂无帮助分类
      </div>

      <div v-else class="space-y-3">
        <button
          v-for="c in categories" :key="c.id"
          class="flex w-full items-center gap-3 rounded-xl bg-white px-4 py-4 text-left transition-shadow hover:shadow-sm"
          :data-testid="`category-item-${c.id}`"
          @click="router.push({ path: '/service-center/faq/list', query: { category_id: c.id } })"
        >
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e6f4ff] text-[#1677ff]">
            <HelpCircle class="h-5 w-5" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-medium text-slate-700">{{ c.name }}</span>
            <span class="mt-0.5 block text-xs text-slate-400">{{ c.published_count }} 篇文章</span>
          </span>
          <ChevronRight class="h-4 w-4 shrink-0 text-slate-300" />
        </button>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
