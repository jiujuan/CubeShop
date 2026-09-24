<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowUpDown, Bike, ChevronDown, ChevronRight, ChevronUp,
  Flame, Gem, Headphones, Home as HomeIcon, House, LayoutGrid, List, PackageOpen,
  SearchX, Shirt, SlidersHorizontal, Smartphone, Sparkles, X,
} from 'lucide-vue-next'
import {
  getAttributes, getBrands, getCategories, getProducts,
  type AttributeOption, type BrandOption, type CategoryNode, type ProductBrief,
} from '@/api/shop'
import { searchProducts, type SearchMeta, type SearchSort } from '@/api/search'
import type { PublicPagination } from '@/api/types'
import ProductCard from '@/components/ProductCard.vue'
import FilterRows from '@/components/FilterRows.vue'
import Pagination from '@/components/Pagination.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 分类商品列表页（原型重设计：左侧分类树 + 顶部横幅 + 排序工具栏 + 商品网格）
 * 复用 /search：keyword 模式下横幅与面包屑展示搜索词；空数据提示「暂无分类商品」。
 * V1.1 T-014：新增品牌 / 属性筛选面板（同属性 OR、跨属性 AND），筛选态同步 URL。
 */
const route = useRoute()
const router = useRouter()

const keyword = ref((route.query.keyword as string) || '')
const categoryId = ref<string | undefined>(route.params.id
  ? String(route.params.id)
  : (route.query.category_id ? String(route.query.category_id) : undefined))
const sort = ref((route.query.sort as string) || 'newest')
const categories = ref<CategoryNode[]>([])
const list = ref<ProductBrief[]>([])
// SEC-04：公开商品列表不返回精确总量，total / total_pages 为 null，翻页用 has_more
const pagination = ref<PublicPagination>({ page: 1, page_size: 20, total: null, total_pages: null, has_more: false })
const loading = ref(false)
const viewMode = ref<'grid' | 'list'>('grid')

/** V1.2 S1-10：keyword 模式走 /search，带 meta（放宽提示 / 相关分类 / 零结果推荐位） */
const searchMeta = ref<SearchMeta | null>(null)

/** 价格区间筛选 */
const priceFilterOpen = ref(false)
const minPrice = ref('')
const maxPrice = ref('')

/** V1.1 T-014：品牌 / 属性筛选 */
const filterOpen = ref(false)
const brands = ref<BrandOption[]>([])
const filterAttributes = ref<AttributeOption[]>([])
const selectedBrands = ref<string[]>([])
const selectedAttrs = ref<Record<number, string[]>>({})

/** 从 URL query 还原筛选态（可分享复现） */
function parseFiltersFromQuery() {
  const brandQ = (route.query.brand as string) || ''
  selectedBrands.value = brandQ ? brandQ.split(',').map((x) => x.trim()).filter(Boolean) : []

  const raw = route.query.attr
  const arr = Array.isArray(raw) ? raw : raw ? [raw] : []
  const map: Record<number, string[]> = {}
  for (const pair of arr) {
    const [aid, v] = String(pair).split(':')
    const id = Number(aid)
    if (id > 0 && v) (map[id] ??= []).push(v)
  }
  selectedAttrs.value = map
}

/** 已选属性 → 请求参数格式 `${attribute_id}:${value}`（同属性 OR、跨属性 AND） */
const attributeValuesParam = computed(() =>
  Object.entries(selectedAttrs.value).flatMap(([aid, values]) => values.map((v) => `${aid}:${v}`)),
)

/** 筛选面板属性组折叠：不带分类时可能返回全部属性（几十组），默认只显示前 5 组 */
const ATTR_GROUPS_COLLAPSED = 5
const attrGroupsExpanded = ref(false)
const visibleAttrs = computed(() =>
  attrGroupsExpanded.value ? filterAttributes.value : filterAttributes.value.slice(0, ATTR_GROUPS_COLLAPSED),
)

const filterCount = computed(
  () => selectedBrands.value.length + attributeValuesParam.value.length,
)

onMounted(async () => {
  parseFiltersFromQuery()
  const { data } = await getCategories()
  categories.value = data.data
  await loadFilters()
  load()
})

/* ---------- 价格区间浮层：点击浮层以外区域收起 ---------- */

const pricePopRef = ref<HTMLElement | null>(null)

/**
 * 文档级 click 监听：点击价格区间浮层与其触发按钮之外的任何地方即收起。
 * 「确定 / 清除」按钮与输入框都在浮层内部，contains 判定为 true，不受影响。
 */
function onDocClick(e: MouseEvent) {
  if (!priceFilterOpen.value) return
  const el = pricePopRef.value
  if (el && !el.contains(e.target as Node)) priceFilterOpen.value = false
}

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

/** 按当前分类加载可用筛选维度（品牌按分类收敛 + 可筛属性） */
async function loadFilters() {
  try {
    const [b, a] = await Promise.all([
      getBrands({ category_id: categoryId.value }),
      getAttributes({ category_id: categoryId.value, filterable: 1 }),
    ])
    brands.value = b.data.data
    filterAttributes.value = a.data.data
  } catch {
    brands.value = []
    filterAttributes.value = []
  }
}

async function load(page = 1) {
  loading.value = true
  try {
    // 参数在两条链路上保持逐字一致：/search 是 /search 关键词富入口，
    // /products 保留原语义（无关键词浏览，V1.2 前的线上行为不动）
    const params = {
      keyword: keyword.value || undefined,
      category_id: categoryId.value,
      min_price: minPrice.value ? Number(minPrice.value) : undefined,
      max_price: maxPrice.value ? Number(maxPrice.value) : undefined,
      brand_id: selectedBrands.value.length === 1 ? selectedBrands.value[0] : undefined,
      attribute_values: attributeValuesParam.value.length ? attributeValuesParam.value : undefined,
      sort: sort.value,
      page,
      page_size: 20,
    }

    if (keyword.value) {
      // sort 的 UI 取值集是 /search 的子集（newest/price_asc/price_desc/sales_desc），
      // 但 ref 初始化成 string，这里收紧到 SearchSort —— 未知值后端本就回落 newest
      const { data } = await searchProducts({
        ...params,
        keyword: keyword.value,
        sort: sort.value as SearchSort,
      })
      list.value = data.data.list
      pagination.value = data.data.pagination
      searchMeta.value = data.data.meta ?? null
      return
    }

    searchMeta.value = null

    const { data } = await getProducts(params)
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

/** 相关分类 chip 点击：未选 → 带 category_id 收敛；已选中 → 清除收敛（URL 驱动，可分享） */
function toggleSearchCategory(id: string) {
  const query: Record<string, string> = { keyword: keyword.value }
  if (categoryId.value !== id) query.category_id = id
  router.push({ path: '/search', query })
}

watch(
  () => route.fullPath,
  async () => {
    keyword.value = (route.query.keyword as string) || ''
    categoryId.value = (route.params.id ? String(route.params.id) : undefined)
      ?? (route.query.category_id ? String(route.query.category_id) : undefined)
    sort.value = (route.query.sort as string) || 'newest'
    priceFilterOpen.value = false
    attrGroupsExpanded.value = false
    parseFiltersFromQuery()
    await loadFilters()
    load(1)
  },
)

/* ---------- 筛选交互 ---------- */

function isBrandSelected(id: string) {
  return selectedBrands.value.includes(id)
}

function toggleBrand(id: string) {
  selectedBrands.value = isBrandSelected(id)
    ? selectedBrands.value.filter((x) => x !== id)
    : [...selectedBrands.value, id]
  applyFilters()
}

function isAttrSelected(attributeId: number, value: string) {
  return (selectedAttrs.value[attributeId] ?? []).includes(value)
}

function toggleAttr(attributeId: number, value: string) {
  const cur = selectedAttrs.value[attributeId] ?? []
  const next = cur.includes(value) ? cur.filter((v) => v !== value) : [...cur, value]
  const map = { ...selectedAttrs.value }
  if (next.length) map[attributeId] = next
  else delete map[attributeId]
  selectedAttrs.value = map
  applyFilters()
}

/** 筛选条件写入 URL（可分享），由 route watch 触发重新加载 */
function applyFilters() {
  const query: Record<string, string | string[]> = {}
  for (const [k, v] of Object.entries(route.query)) {
    if (k !== 'brand' && k !== 'attr' && v !== undefined) query[k] = v as string | string[]
  }
  if (selectedBrands.value.length) query.brand = selectedBrands.value.join(',')
  if (attributeValuesParam.value.length) query.attr = attributeValuesParam.value
  router.push({ path: route.path, query })
}

/** 已选条件 chips */
const chips = computed(() => {
  const out: { key: string; label: string; remove: () => void }[] = []
  for (const bid of selectedBrands.value) {
    const b = brands.value.find((x) => x.id === bid)
    out.push({ key: `brand-${bid}`, label: b?.name ?? `品牌#${bid}`, remove: () => toggleBrand(bid) })
  }
  for (const [aid, values] of Object.entries(selectedAttrs.value)) {
    const attr = filterAttributes.value.find((x) => x.id === Number(aid))
    for (const v of values) {
      out.push({
        key: `attr-${aid}-${v}`,
        label: `${attr?.name ?? '属性'}：${v}`,
        remove: () => toggleAttr(Number(aid), v),
      })
    }
  }
  return out
})

function clearFilters() {
  selectedBrands.value = []
  selectedAttrs.value = {}
  applyFilters()
}

/* ---------- 分类定位 ---------- */

const activeRoot = computed(() =>
  categories.value.find(
    (root) => root.id === categoryId.value || root.children.some((c) => c.id === categoryId.value),
  ),
)
const activeName = computed(() => {
  if (keyword.value) return keyword.value
  if (!categoryId.value) return '全部商品'
  if (activeRoot.value?.id === categoryId.value) return activeRoot.value.name
  return activeRoot.value?.children.find((c) => c.id === categoryId.value)?.name ?? '全部商品'
})

/** 顶部信息区大标题：当前大（一级）分类名；搜索 / 无分类时给出对应兜底文案 */
const rootName = computed(() => {
  if (keyword.value) return `搜索：${keyword.value}`
  return activeRoot.value?.name ?? '全部商品'
})

/** 顶部信息区小分类（可点击）：命中小分类则高亮该小分类；处于大分类时展开其下全部小分类 */
const subLinks = computed(() => {
  const q = categoryId.value
  if (keyword.value || !q) return []
  const root = activeRoot.value
  if (!root) return []
  return root.children.map((c) => ({ id: c.id, name: c.name, active: c.id === q }))
})

/** 顶部大分类是否可点击（有分类上下文且当前不在该大分类页时） */
const rootClickable = computed(() => !keyword.value && !!activeRoot.value && activeRoot.value.id !== categoryId.value)

/** 点击分类 → 跳转分类列表页（URL 驱动） */
function goCategory(id?: string) {
  if (id) router.push(`/category/${id}`)
}

/* ---------- 排序 / 筛选 ---------- */

function setSort(s: 'newest' | 'sales_desc') {
  sort.value = s
  load(1)
}

/** 价格升/降序切换 */
function togglePriceSort() {
  sort.value = sort.value === 'price_asc' ? 'price_desc' : 'price_asc'
  load(1)
}

function applyPriceFilter() {
  priceFilterOpen.value = false
  load(1)
}

function clearPriceFilter() {
  minPrice.value = ''
  maxPrice.value = ''
  priceFilterOpen.value = false
  load(1)
}

const hasPriceFilter = computed(() => minPrice.value !== '' || maxPrice.value !== '')

/* ---------- 分页 ---------- */

/** 翻页：交给统一分页组件，跳页时回顶 */
function onPage(page: number) {
  load(page)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

/* ---------- 展示辅助 ---------- */

/** 销量 ≥1000 打「热销」标 */
const tagOf = (p: ProductBrief) => (p.sales_count >= 1000 ? 'hot' : null)

const rootIcons = [Flame, Shirt, Smartphone, House, Sparkles, Bike, Gem, Headphones]
const iconOf = (i: number) => rootIcons[i % rootIcons.length]

/** 是否存在任何筛选条件（含价格） */
const hasAnyFilter = computed(() => filterCount.value > 0 || hasPriceFilter.value)

/** 加载完成但零结果：隐藏品牌区与排序工具栏，页面收敛为提示 + 推荐/空态 */
const isNoResult = computed(() => !loading.value && list.value.length === 0)

/** 搜索页（keyword 模式）：无论有无结果都不提供分类筛选/排序（结果由搜索词决定） */
const isKeywordSearch = computed(() => !!keyword.value)

/** 品牌、排序工具栏与筛选面板的显隐：搜索页一律隐藏；分类浏览仅零结果时隐藏 */
const filtersVisible = computed(() => !isNoResult.value && !isKeywordSearch.value)

function clearAllFilters() {
  clearPriceFilter()
  clearFilters()
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-[#f5f7fa]">
    <ShopHeader />

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-4">
      <!-- 面包屑 -->
      <nav class="mb-3 flex items-center gap-1.5 text-[13px] text-slate-500">
        <RouterLink to="/" class="flex items-center gap-1 hover:text-[#1677ff]">
          <HomeIcon class="h-3.5 w-3.5" /> 首页
        </RouterLink>
        <ChevronRight class="h-3 w-3 text-slate-300" />
        <RouterLink to="/" class="hover:text-[#1677ff]">全部商品分类</RouterLink>
        <template v-if="activeName">
          <ChevronRight class="h-3 w-3 text-slate-300" />
          <span class="font-medium text-[#1677ff]">{{ activeName }}</span>
        </template>
      </nav>

      <div class="flex items-start gap-4">
        <!-- 左侧分类树 -->
        <aside class="hidden w-56 shrink-0 self-start rounded-xl border border-slate-100 bg-white shadow-sm lg:block">
          <div class="border-b border-slate-100 py-3.5 text-center text-[15px] font-bold text-slate-800">全部商品分类</div>
          <ul class="p-2">
            <li v-for="(root, i) in categories" :key="root.id">
              <button
                class="relative flex w-full items-center gap-2.5 rounded-lg px-3.5 py-2.5 text-sm transition-colors"
                :class="activeRoot?.id === root.id ? 'bg-[#e6f4ff] font-medium text-[#1677ff]' : 'text-slate-600 hover:bg-slate-50 hover:text-[#1677ff]'"
                @click="goCategory(root.id)"
              >
                <span
                  v-if="activeRoot?.id === root.id"
                  class="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r bg-[#1677ff]"
                />
                <component :is="iconOf(i)" class="h-4 w-4 shrink-0" :class="activeRoot?.id === root.id ? 'text-[#1677ff]' : 'text-slate-400'" />
                <span class="flex-1 text-left">{{ root.name }}</span>
                <ChevronRight class="h-3.5 w-3.5 text-slate-300" />
              </button>

              <!-- 子分类（激活的一级展开） -->
              <ul v-if="activeRoot?.id === root.id" class="mb-1 mt-0.5">
                <li v-for="c in root.children" :key="c.id">
                  <button
                    class="relative flex w-full items-center py-2 pl-[52px] pr-3 text-[13px] transition-colors"
                    :class="categoryId === c.id ? 'font-medium text-[#1677ff]' : 'text-slate-500 hover:text-[#1677ff]'"
                    @click="goCategory(c.id)"
                  >
                    <span
                      v-if="categoryId === c.id"
                      class="absolute left-[38px] top-1/2 h-4 w-1 -translate-y-1/2 rounded-r bg-[#1677ff]"
                    />
                    {{ c.name }}
                  </button>
                </li>
              </ul>
            </li>
          </ul>
        </aside>

        <!-- 右侧主区 -->
        <section class="min-w-0 flex-1">
          <!-- 分类信息（原顶部横幅图片位）：大分类 / 小分类（均可点击） / 该分类下品牌 -->
          <div class="mb-4 rounded-xl bg-white p-5 shadow-sm" data-testid="browse-heading">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
              <h1
                class="text-xl font-bold"
                :class="rootClickable ? 'cursor-pointer text-slate-800 hover:text-[#1677ff]' : 'text-slate-800'"
                :title="rootClickable ? '查看该大分类下全部商品' : undefined"
                data-testid="browse-heading-root"
                @click="rootClickable && goCategory(activeRoot!.id)"
              >{{ rootName }}</h1>
              <template v-if="subLinks.length">
                <span class="text-[13px] text-slate-300">/</span>
                <button
                  v-for="s in subLinks" :key="s.id"
                  class="text-[13px] transition-colors"
                  :class="s.active ? 'font-medium text-[#1677ff]' : 'text-slate-400 hover:text-[#1677ff]'"
                  :data-testid="`heading-sub-${s.id}`"
                  @click="!s.active && goCategory(s.id)"
                >{{ s.name }}</button>
              </template>
            </div>

            <!-- 搜索页 / 零结果时隐藏品牌列表（无商品可筛） -->
            <template v-if="filtersVisible">
              <div v-if="brands.length" class="mt-3 flex items-start gap-2.5" data-testid="browse-heading-brands">
                <span class="shrink-0 pt-0.5 text-[13px] text-slate-400">品牌</span>
                <!-- 品牌多时收起为 4 行，「更多/收起」展开（超 8 行滚动） -->
                <FilterRows test-id="heading-brands">
                  <button
                    v-for="b in brands" :key="b.id"
                    class="rounded-full border px-3 py-0.5 text-[13px] transition-colors"
                    :class="isBrandSelected(b.id)
                      ? 'border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]'
                      : 'border-slate-200 text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]'"
                    :data-testid="`heading-brand-${b.id}`"
                    @click="toggleBrand(b.id)"
                  >{{ b.name }}</button>
                </FilterRows>
              </div>
              <p v-else class="mt-2 text-[13px] text-slate-400" data-testid="browse-heading-brands-empty">暂无品牌</p>
            </template>
          </div>

          <!-- V1.2 S1-10：放宽匹配提示（后端 search.expose_debug 开启时返回 relaxed） -->
          <div
            v-if="searchMeta?.relaxed"
            class="mb-3 rounded-lg bg-[#fffbe6] px-4 py-2 text-[13px] text-[#ad8b00]"
            data-testid="search-relaxed"
          >没有完全匹配的结果，已为你放宽匹配条件</div>

          <!-- V1.2 S1-10：相关分类聚合（命中商品的高频分类，点击收敛范围） -->
          <div
            v-if="searchMeta?.related_categories?.length"
            class="mb-3 flex flex-wrap items-center gap-2 text-[13px]"
            data-testid="search-related-categories"
          >
            <span class="text-slate-400">相关分类</span>
            <button
              v-for="c in searchMeta.related_categories" :key="c.id"
              class="rounded-full border px-3 py-0.5 transition-colors"
              :class="categoryId === c.id
                ? 'border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]'
                : 'border-slate-200 text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]'"
              :data-testid="`search-related-category-${c.id}`"
              @click="toggleSearchCategory(c.id)"
            >{{ c.name }}（{{ c.count }}）</button>
          </div>

          <!-- 排序工具栏（搜索页 / 零结果时隐藏：无排序与视图切换需求） -->
          <div v-if="filtersVisible" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <button
              class="rounded-full px-4 py-1.5 transition-colors"
              :class="!sort.startsWith('price') && sort !== 'sales_desc' ? 'bg-[#1677ff] font-medium text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]'"
              @click="setSort('newest')"
            >综合排序</button>
            <button
              class="flex items-center gap-1 rounded-full px-4 py-1.5 transition-colors"
              :class="sort === 'sales_desc' ? 'bg-[#1677ff] font-medium text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]'"
              @click="setSort('sales_desc')"
            >销量 <ArrowUpDown class="h-3 w-3" /></button>
            <button
              class="flex items-center gap-0.5 rounded-full px-4 py-1.5 transition-colors"
              :class="sort.startsWith('price') ? 'bg-[#1677ff] font-medium text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]'"
              @click="togglePriceSort"
            >
              价格
              <span class="flex flex-col leading-none">
                <ChevronUp class="h-2.5 w-2.5" :class="sort === 'price_asc' ? 'text-white' : 'text-slate-400'" />
                <ChevronDown class="h-2.5 w-2.5" :class="sort === 'price_desc' ? 'text-white' : 'text-slate-400'" />
              </span>
            </button>

            <!-- 价格区间（点击浮层以外区域自动收起） -->
            <div ref="pricePopRef" class="relative">
              <button
                data-testid="price-filter-toggle"
                class="flex items-center gap-1 rounded-full px-4 py-1.5 transition-colors"
                :class="hasPriceFilter ? 'border border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]' : 'border border-slate-200 bg-white text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]'"
                @click="priceFilterOpen = !priceFilterOpen"
              >
                价格区间 <ChevronDown class="h-3.5 w-3.5" />
              </button>
              <div
                v-if="priceFilterOpen"
                data-testid="price-filter-pop"
                class="absolute left-0 top-10 z-20 w-64 rounded-lg border border-slate-100 bg-white p-3 shadow-lg"
              >
                <div class="flex items-center gap-2">
                  <input
                    v-model="minPrice" type="number" min="0" placeholder="最低价"
                    class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs outline-none focus:border-[#1677ff]"
                  />
                  <span class="text-slate-300">—</span>
                  <input
                    v-model="maxPrice" type="number" min="0" placeholder="最高价"
                    class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs outline-none focus:border-[#1677ff]"
                  />
                </div>
                <div class="mt-2.5 flex justify-end gap-2 text-xs">
                  <button class="rounded-md px-3 py-1 text-slate-500 hover:text-[#1677ff]" @click="clearPriceFilter">清除</button>
                  <button class="rounded-md bg-[#1677ff] px-3 py-1 text-white hover:bg-[#4096ff]" @click="applyPriceFilter">确定</button>
                </div>
              </div>
            </div>

            <!-- V1.1 T-014：品牌 / 属性筛选入口 -->
            <button
              data-testid="filter-toggle"
              class="flex items-center gap-1 rounded-full px-4 py-1.5 transition-colors"
              :class="filterCount > 0 ? 'border border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]' : 'border border-slate-200 bg-white text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]'"
              @click="filterOpen = !filterOpen"
            >
              <SlidersHorizontal class="h-3.5 w-3.5" /> 筛选
              <span v-if="filterCount" class="rounded-full bg-[#1677ff] px-1.5 text-[11px] leading-4 text-white">{{ filterCount }}</span>
            </button>

              <!-- 右侧：视图切换 -->
            <div class="ml-auto flex items-center gap-3 text-[13px] text-slate-500">
              <div class="flex overflow-hidden rounded-md border border-slate-200">
                <button
                  class="flex h-7 w-8 items-center justify-center"
                  :class="viewMode === 'grid' ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-400 hover:text-[#1677ff]'"
                  title="网格视图"
                  @click="viewMode = 'grid'"
                ><LayoutGrid class="h-3.5 w-3.5" /></button>
                <button
                  class="flex h-7 w-8 items-center justify-center border-l border-slate-200"
                  :class="viewMode === 'list' ? 'bg-[#1677ff] text-white' : 'bg-white text-slate-400 hover:text-[#1677ff]'"
                  title="列表视图"
                  @click="viewMode = 'list'"
                ><List class="h-3.5 w-3.5" /></button>
              </div>
            </div>
          </div>

          <!-- V1.1 T-014：筛选面板（可筛属性；品牌已上移至顶部信息区） -->
          <div
            v-if="filterOpen && filtersVisible"
            data-testid="filter-panel"
            class="mb-3 rounded-xl border border-slate-100 bg-white p-4 shadow-sm"
          >
            <div
              v-for="attr in visibleAttrs" :key="attr.id"
              class="mb-3 flex items-start gap-3 text-sm"
              :data-testid="`filter-attr-${attr.id}`"
            >
              <span class="w-14 shrink-0 pt-1 text-slate-500">{{ attr.name }}</span>
              <!-- 属性值多时收起为 5 行，「更多/收起」展开（超 8 行滚动） -->
              <FilterRows :test-id="`filter-attr-rows-${attr.id}`">
                <button
                  v-for="v in attr.values" :key="v.id"
                  class="rounded-lg border px-3 py-1 text-[13px] transition-colors"
                  :class="isAttrSelected(attr.id, v.value)
                    ? 'border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]'
                    : 'border-slate-200 text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]'"
                  :data-testid="`filter-attr-${attr.id}-${v.value}`"
                  @click="toggleAttr(attr.id, v.value)"
                >{{ v.value }}</button>
              </FilterRows>
            </div>

            <!-- 属性组超过 5 组：更多 / 收起 -->
            <button
              v-if="filterAttributes.length > ATTR_GROUPS_COLLAPSED"
              type="button"
              class="flex items-center gap-0.5 text-xs text-[#1677ff] hover:text-[#4096ff]"
              data-testid="attr-groups-toggle"
              @click="attrGroupsExpanded = !attrGroupsExpanded"
            >
              {{ attrGroupsExpanded ? '收起' : '更多' }}
              <ChevronUp v-if="attrGroupsExpanded" class="h-3 w-3" />
              <ChevronDown v-else class="h-3 w-3" />
            </button>

            <p v-if="!filterAttributes.length" class="py-2 text-center text-xs text-slate-400">
              当前分类暂无可筛选条件
            </p>
          </div>

          <!-- V1.1 T-014：已选条件 chips -->
          <div v-if="chips.length" class="mb-3 flex flex-wrap items-center gap-2 text-xs" data-testid="filter-chips">
            <span
              v-for="chip in chips" :key="chip.key"
              class="flex items-center gap-1 rounded-full border border-[#1677ff] bg-[#e6f4ff] px-2.5 py-1 text-[#1677ff]"
              :data-testid="`chip-${chip.key}`"
            >
              {{ chip.label }}
              <button class="text-[#1677ff]/70 hover:text-[#1677ff]" @click="chip.remove"><X class="h-3 w-3" /></button>
            </span>
            <button class="ml-1 text-slate-400 hover:text-[#1677ff]" data-testid="filter-clear" @click="clearFilters">
              清空筛选
            </button>
          </div>

          <!-- 商品网格（竖版卡片） -->
          <div v-if="viewMode === 'grid' && list.length" class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
            <ProductCard v-for="p in list" :key="p.id" :product="p" :tag="tagOf(p)" layout="vertical" />
          </div>

          <!-- 列表视图（横向卡片） -->
          <div v-else-if="viewMode === 'list' && list.length" class="space-y-3">
            <ProductCard v-for="p in list" :key="p.id" :product="p" :tag="tagOf(p)" />
          </div>

          <LoadingSpinner v-if="loading" class="py-16" />

          <!-- 零结果推荐位（V1.2 S1-10，降级链第 4 步：不白屏） -->
          <template v-if="!list.length && !loading && (searchMeta?.recommendations?.length ?? 0) > 0">
            <div class="flex h-20 items-center justify-center gap-2 rounded-xl bg-white text-sm text-slate-500" data-testid="search-recommendations-title">
              <SearchX class="h-5 w-5 text-slate-400" />
              没有找到相关商品，为你推荐这些
            </div>
            <div class="mb-3 mt-5 text-sm font-semibold text-slate-700" data-testid="search-recommendations-heading">推荐商品</div>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4" data-testid="search-recommendations">
              <ProductCard
                v-for="p in searchMeta!.recommendations" :key="p.id"
                :product="p" :tag="tagOf(p)" layout="vertical"
              />
            </div>
          </template>

          <!-- 空状态 -->
          <div v-if="!list.length && !loading && !(searchMeta?.recommendations?.length)" class="flex flex-col items-center rounded-xl bg-white py-20 text-slate-400" data-testid="browse-empty">
            <PackageOpen class="mb-3 h-12 w-12 text-slate-300" />
            <p class="text-sm">暂无分类商品</p>
            <button
              v-if="hasAnyFilter"
              class="mt-4 rounded-full border border-[#1677ff] px-4 py-1.5 text-xs text-[#1677ff] hover:bg-[#1677ff] hover:text-white"
              data-testid="empty-clear-filter"
              @click="clearAllFilters"
            >清空筛选条件</button>
            <button
              v-else
              class="mt-4 rounded-full border border-[#1677ff] px-4 py-1.5 text-xs text-[#1677ff] hover:bg-[#1677ff] hover:text-white"
              @click="router.push('/')"
            >去首页逛逛</button>
          </div>

          <!-- 分页（统一分页条，风格与后台一致） -->
          <Pagination v-if="pagination.has_more || pagination.page > 1 || pagination.total_pages != null" :pagination="pagination" @change="onPage" />
        </section>
      </div>
    </main>

    <ShopFooter />
  </div>
</template>

<style scoped>
/* 数字输入框隐藏上下箭头，保持价格区间输入简洁 */
input[type='number']::-webkit-outer-spin-button,
input[type='number']::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
input[type='number'] {
  appearance: textfield;
  -moz-appearance: textfield;
}
</style>
