<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Search } from 'lucide-vue-next'
import { getCategories, getProducts, type CategoryNode, type ProductBrief } from '@/api/shop'
import ProductCard from '@/components/ProductCard.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 分类页 / 搜索结果页（复用：分类筛选 + 排序 + 商品网格）
 */
const route = useRoute()

const keyword = ref((route.query.keyword as string) || '')
const categoryId = ref<number | undefined>(route.params.id ? Number(route.params.id) : undefined)
const sort = ref((route.query.sort as string) || 'newest')
const categories = ref<CategoryNode[]>([])
const list = ref<ProductBrief[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(false)

onMounted(async () => {
  const { data } = await getCategories()
  categories.value = data.data
  load()
})

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getProducts({
      keyword: keyword.value || undefined,
      category_id: categoryId.value,
      sort: sort.value,
      page,
      page_size: 20,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

watch(
  () => route.fullPath,
  () => {
    keyword.value = (route.query.keyword as string) || ''
    categoryId.value = route.params.id ? Number(route.params.id) : undefined
    sort.value = (route.query.sort as string) || 'newest'
    load(1)
  },
)

const activeCategoryName = () => {
  if (!categoryId.value) return '全部商品'
  for (const root of categories.value) {
    if (root.id === categoryId.value) return root.name
    const child = root.children.find((c) => c.id === categoryId.value)
    if (child) return `${root.name} / ${child.name}`
  }
  return '全部商品'
}

function pickCategory(id?: number) {
  categoryId.value = id
  load(1)
}

function goPage(page: number) {
  if (page >= 1 && page <= pagination.value.total_pages) load(page)
}
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-6">
      <!-- 面包屑 + 搜索词 -->
      <div class="mb-4 flex items-center justify-between text-sm">
        <div class="text-slate-600">
          <template v-if="keyword">
            <span class="text-slate-400">搜索：</span><span class="font-semibold text-[#1677ff]">{{ keyword }}</span>
            <span class="ml-2 text-xs text-slate-400">共 {{ pagination.total }} 件商品</span>
          </template>
          <template v-else>{{ activeCategoryName() }}</template>
        </div>
        <div class="flex items-center gap-2 text-[13px]">
          <span class="text-slate-400">排序</span>
          <select v-model="sort" class="rounded-md border border-slate-200 px-2 py-1 outline-none focus:border-[#1677ff]" @change="load(1)">
            <option value="newest">最新</option>
            <option value="sales_desc">销量优先</option>
            <option value="price_asc">价格从低到高</option>
            <option value="price_desc">价格从高到低</option>
          </select>
        </div>
      </div>

      <!-- 分类快捷筛选 -->
      <div class="mb-5 flex flex-wrap items-center gap-2 text-[13px]">
        <button
          class="rounded-full px-3 py-1"
          :class="!categoryId ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600 hover:bg-[#e6f4ff]'"
          @click="pickCategory(undefined)"
        >全部</button>
        <template v-for="root in categories" :key="root.id">
          <button
            class="rounded-full px-3 py-1"
            :class="categoryId === root.id ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-600 hover:bg-[#e6f4ff]'"
            @click="pickCategory(root.id)"
          >{{ root.name }}</button>
          <button
            v-for="c in root.children" :key="c.id"
            class="rounded-full px-3 py-1 text-xs"
            :class="categoryId === c.id ? 'bg-[#1677ff] text-white' : 'bg-slate-50 text-slate-500 hover:bg-[#e6f4ff]'"
            @click="pickCategory(c.id)"
          >{{ c.name }}</button>
        </template>
      </div>

      <!-- 商品网格 -->
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <ProductCard v-for="p in list" :key="p.id" :product="p" />
      </div>

      <LoadingSpinner v-if="loading" class="py-16" />

      <div v-if="!list.length && !loading" class="flex flex-col items-center py-20 text-slate-400">
        <Search class="mb-3 h-10 w-10" />
        <p>暂时无数据</p>
      </div>

      <!-- 分页 -->
      <div v-if="pagination.total_pages > 1" class="mt-8 flex items-center justify-center gap-2 text-sm">
        <button
          class="rounded-md border border-slate-200 px-3 py-1.5 disabled:opacity-40"
          :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)"
        >上一页</button>
        <button
          v-for="page in pagination.total_pages" :key="page"
          class="min-w-9 rounded-md border px-2 py-1.5"
          :class="page === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
          @click="goPage(page)"
        >{{ page }}</button>
        <button
          class="rounded-md border border-slate-200 px-3 py-1.5 disabled:opacity-40"
          :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)"
        >下一页</button>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>
