<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronRight, HelpCircle, Search } from 'lucide-vue-next'
import FaqBreadcrumb from '@/components/FaqBreadcrumb.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useFaqStore } from '@/stores/faq'

/**
 * 帮助中心 · 分类页（CS-112 / CMS-201）
 *
 * CMS-201：栏目已支持父子化，本页渲染**两级** —— 一级栏目卡 + 其下子栏目的 pill。
 * 三级及以上不在这里展开（会撑爆首页），进列表页后可看完整侧栏栏目树。
 *
 * 数据来自 `useFaqStore`（树的单一真源），本页不再自己发请求。
 */
const router = useRouter()
const faq = useFaqStore()
const keyword = ref('')

const loading = computed(() => faq.loading && !faq.loaded)
const categories = computed(() => faq.tree)

function goList(categoryId: number) {
  router.push({ path: '/service-center/faq/list', query: { category_id: categoryId } })
}

function goSearch() {
  const kw = keyword.value.trim()
  router.push({ path: '/service-center/faq/list', query: kw ? { keyword: kw } : {} })
}

onMounted(() => faq.loadTree())
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 sm:px-6" data-testid="faq-category">
      <FaqBreadcrumb />

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
        <div
          v-for="c in categories" :key="c.id"
          class="rounded-xl bg-white p-4"
          :data-testid="`category-item-${c.id}`"
        >
          <button class="flex w-full items-center gap-3 text-left" @click="goList(c.id)">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e6f4ff] text-[#1677ff]">
              <HelpCircle class="h-5 w-5" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-medium text-slate-700">{{ c.name }}</span>
              <span class="mt-0.5 block text-xs text-slate-400">{{ c.published_count }} 篇文章</span>
            </span>
            <ChevronRight class="h-4 w-4 shrink-0 text-slate-300" />
          </button>

          <!-- 二级栏目：pill 直达，省一层跳转 -->
          <div v-if="c.children?.length" class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
            <button
              v-for="child in c.children" :key="child.id"
              class="rounded-full bg-slate-50 px-3 py-1.5 text-xs text-slate-600 transition-colors hover:bg-[#e6f4ff] hover:text-[#1677ff]"
              :data-testid="`category-child-${child.id}`"
              @click="goList(child.id)"
            >
              {{ child.name }}
              <span class="ml-1 text-slate-400">{{ child.published_count }}</span>
            </button>
          </div>
        </div>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
