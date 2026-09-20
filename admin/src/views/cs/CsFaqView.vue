<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronLeft, ChevronRight, Eye, ExternalLink, Pencil, Plus, Trash2, X } from 'lucide-vue-next'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import PageBlockEditor from '@/components/PageBlockEditor.vue'
import PageFieldForm from '@/components/PageFieldForm.vue'
import {
  createCsFaqArticle, createCsFaqCategory, deleteCsFaqArticle, deleteCsFaqCategory,
  getCsFaqArticles, getCsFaqCategories, getCsFaqPage, getCsFaqPageBlocks, getCsFaqPageTemplates,
  moveCsFaqCategory, offlineCsFaqArticle, previewCsFaqArticle,
  publishCsFaqArticle, saveCsFaqPage, saveCsFaqPageBlocks, sortCsFaqCategories,
  updateCsFaqArticle, updateCsFaqCategory, uploadCmsImage,
  type CmsPageBlockOption, type CmsPageBlockPayload, type CmsPageDetail, type CmsPageTemplateOption,
  type CsChannelOption, type CsFaqArticlePayload, type CsFaqArticleRow,
  type CsFaqCategoryPayload, type CsFaqCategoryRow,
} from '@/api/cs'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { getProducts } from '@/api/product'
import { useAuthStore } from '@/stores/auth'

/**
 * 内容中心 CMS 后台管理页（原 CS-115 帮助中心 → CMS-109 内容管理）
 *
 * 布局：**左栏目树 + 右内容区**。右侧按选中栏目的 `type` 自动切换：
 * - `type=channel` → 文章列表（筛选 / 分页 / 增删改 / 发布 / 下架 / 预览）
 * - `type=page`    → 单页内容：固定模板用字段表单（PageFieldForm），
 *                    `template=blocks` 用区块编辑器（PageBlockEditor，CMS-203）
 *
 * 栏目支持无限父子（后端 CmsCategoryService 维护 level/path，本页只做展示与转发）。
 * 正文用 Markdown 编辑（源存 content_md），HTML 产物由后端渲染派生。
 * 无 cs.faq.manage 时页面只读，不渲染任何写操作入口。
 */
const auth = useAuthStore()
const canManage = computed(() => auth.hasPermission('cs.faq.manage'))

const STATUS_LABELS: Record<string, string> = { draft: '草稿', published: '已发布', offline: '已下架' }

const tip = ref('')
const tipType = ref<'ok' | 'err'>('ok')

function notify(type: 'ok' | 'err', text: string) {
  tipType.value = type
  tip.value = text
}

// ---------------- 栏目树 ----------------

const categories = ref<CsFaqCategoryRow[]>([])
const catLoading = ref(false)
const selectedCategoryId = ref<number | null>(null)

/** 拍平成「节点 + 深度」的行列表，便于按 level 缩进渲染（树只渲染一层 DOM） */
const flatCategories = computed(() => {
  const out: Array<{ node: CsFaqCategoryRow; depth: number }> = []
  const walk = (nodes: CsFaqCategoryRow[], depth: number) => {
    for (const n of nodes) {
      out.push({ node: n, depth })
      if (n.children?.length) walk(n.children, depth + 1)
    }
  }
  walk(categories.value, 0)
  return out
})

const allNodes = computed(() => flatCategories.value.map((x) => x.node))

const selectedCategory = computed(() => allNodes.value.find((n) => n.id === selectedCategoryId.value) ?? null)

/** 单页模板下拉（真源在后端 CmsPageTemplate 注册表） */
const templateOptions = ref<CmsPageTemplateOption[]>([])

const catEditor = ref<{
  open: boolean
  id: number | null
  name: string
  sort: number
  is_active: boolean
  parent_id: number
  type: 'channel' | 'page'
  slug: string
  template: string
  show_in_nav: boolean
  /** CMS 新闻中心：列表形态 card=图文卡片 / list=列表行（channel 才有意义） */
  list_style: string
  /** CMS-202：SEO 三列（空串即清空） */
  seo_title: string
  seo_keywords: string
  seo_description: string
}>({
  open: false, id: null, name: '', sort: 0, is_active: true,
  parent_id: 0, type: 'channel', slug: '', template: '', show_in_nav: false,
  list_style: 'list',
  seo_title: '', seo_keywords: '', seo_description: '',
})
const deleteCatTarget = ref<CsFaqCategoryRow | null>(null)
const sortSaving = ref(false)

/** 编辑时的「上级栏目」候选：排除自身与其所有后代（后端另有防环兜底） */
const parentOptions = computed(() => {
  const editingId = catEditor.value.id
  if (editingId === null) return flatCategories.value

  const excluded = new Set<number>()
  const findNode = (nodes: CsFaqCategoryRow[]): CsFaqCategoryRow | null => {
    for (const n of nodes) {
      if (n.id === editingId) return n
      const hit = findNode(n.children ?? [])
      if (hit) return hit
    }
    return null
  }
  const collect = (n: CsFaqCategoryRow) => {
    excluded.add(n.id)
    ;(n.children ?? []).forEach(collect)
  }
  const self = findNode(categories.value)
  if (self) collect(self)

  return flatCategories.value.filter((x) => !excluded.has(x.node.id))
})

async function loadCategories() {
  catLoading.value = true
  try {
    const { data } = await getCsFaqCategories()
    categories.value = data.data

    const nodes = allNodes.value
    const stillExists = nodes.some((n) => n.id === selectedCategoryId.value)
    if (!stillExists) {
      // 优先落到第一个非单页栏目（列表为主场景）；否则第一个节点
      const fallback = nodes.find((n) => n.type !== 'page') ?? nodes[0] ?? null
      await selectCategory(fallback)
    } else {
      // 选中项仍在：刷新右侧（如改名后需要同步）
      await selectCategory(selectedCategory.value)
    }
  } finally {
    catLoading.value = false
  }
}

/** 选中栏目并按类型加载右侧内容区 */
async function selectCategory(node: CsFaqCategoryRow | null) {
  selectedCategoryId.value = node?.id ?? null
  pageDetail.value = null

  if (!node) return

  if (node.type === 'page') {
    await loadPage(node.id)
  } else {
    artFilters.value.category_id = node.id
    await loadArticles(1)
  }
}

function openCatCreate() {
  catEditor.value = {
    open: true, id: null, name: '', sort: 0, is_active: true,
    parent_id: 0, type: 'channel', slug: '', template: '', show_in_nav: false,
    list_style: 'list',
    seo_title: '', seo_keywords: '', seo_description: '',
  }
}

/** 在指定栏目下新建子栏目 */
function openCatCreateChild(parent: CsFaqCategoryRow) {
  catEditor.value = {
    open: true, id: null, name: '', sort: 0, is_active: true,
    parent_id: parent.id, type: 'channel', slug: '', template: '', show_in_nav: false,
    list_style: 'list',
    seo_title: '', seo_keywords: '', seo_description: '',
  }
}

function openCatEdit(c: CsFaqCategoryRow) {
  catEditor.value = {
    open: true, id: c.id, name: c.name, sort: c.sort, is_active: c.is_active,
    parent_id: c.parent_id, type: c.type ?? 'channel', slug: c.slug ?? '',
    template: c.template ?? '', show_in_nav: c.show_in_nav,
    list_style: c.list_style ?? 'list',
    seo_title: c.seo_title ?? '', seo_keywords: c.seo_keywords ?? '',
    seo_description: c.seo_description ?? '',
  }
}

async function saveCategory() {
  const e = catEditor.value
  if (!e.name.trim()) { notify('err', '请填写栏目名称'); return }
  if (e.type === 'page') {
    if (!e.slug.trim()) { notify('err', '单页必须填写 slug（前台 /p/{slug} 访问）'); return }
    if (!e.template) { notify('err', '单页必须选择模板'); return }
  }

  const payload: CsFaqCategoryPayload = {
    name: e.name.trim(),
    sort: e.sort,
    is_active: e.is_active,
    type: e.type,
    slug: e.type === 'page' ? e.slug.trim() : null,
    template: e.type === 'page' ? e.template : null,
    show_in_nav: e.show_in_nav,
    // CMS 新闻中心：列表形态只对栏目（channel）生效；单页忽略
    list_style: e.type === 'channel' ? e.list_style : null,
    // CMS-202：SEO 只对单页开放（栏目页的 SEO 还没做前台出口）；空串即清空
    seo_title: e.type === 'page' ? e.seo_title.trim() : null,
    seo_keywords: e.type === 'page' ? e.seo_keywords.trim() : null,
    seo_description: e.type === 'page' ? e.seo_description.trim() : null,
  }

  try {
    if (e.id === null) {
      payload.parent_id = e.parent_id
      await createCsFaqCategory(payload)
    } else {
      const current = allNodes.value.find((n) => n.id === e.id)
      const parentChanged = !!current && current.parent_id !== e.parent_id
      await updateCsFaqCategory(e.id, payload)
      if (parentChanged) await moveCsFaqCategory(e.id, e.parent_id)
    }
    catEditor.value.open = false
    notify('ok', '已保存')
    await loadCategories()
  } catch (err) {
    notify('err', err instanceof Error ? err.message : '保存失败')
  }
}

async function toggleCategory(c: CsFaqCategoryRow) {
  try {
    await updateCsFaqCategory(c.id, { is_active: !c.is_active })
    await loadCategories()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '操作失败')
  }
}

async function saveSort() {
  sortSaving.value = true
  try {
    await sortCsFaqCategories(allNodes.value.map((c) => ({ id: c.id, sort: c.sort })))
    notify('ok', '排序已保存')
    await loadCategories()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '排序保存失败')
  } finally {
    sortSaving.value = false
  }
}

async function doDeleteCategory() {
  const target = deleteCatTarget.value
  deleteCatTarget.value = null
  if (!target) return
  try {
    await deleteCsFaqCategory(target.id)
    if (selectedCategoryId.value === target.id) selectedCategoryId.value = null
    notify('ok', '已删除')
    await loadCategories()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '删除失败')
  }
}

function categoryBadge(c: CsFaqCategoryRow): string {
  return c.type === 'page' ? '单页' : '栏目'
}

// ---------------- 单页内容 ----------------

const pageDetail = ref<CmsPageDetail | null>(null)
const pageValues = ref<Record<string, unknown>>({})
/** CMS-203：区块化单页的内容（固定模板恒为空数组） */
const pageBlocks = ref<CmsPageBlockPayload[]>([])
/** 区块库（真源在后端 CmsBlock）；只在遇到区块模板时才拉，避免每次开弹窗多传一份 schema */
const blockOptions = ref<CmsPageBlockOption[]>([])
const pageLoading = ref(false)
const pageSaving = ref(false)

/**
 * 可选栏目（`channels` 字段用）
 *
 * 复用左栏目的扁平列表并剔掉单页 —— 与后端 `faqEmbedItems` 的口径一致
 * （单页不是「文章容器」，嵌进去只会得到空列表）。
 */
const channelOptions = computed<CsChannelOption[]>(() =>
  allNodes.value
    .filter((n) => n.type !== 'page')
    .map((n) => ({ id: n.id, name: n.name, level: n.level })),
)

async function loadPage(id: number) {
  pageLoading.value = true
  pageDetail.value = null
  pageValues.value = {}
  pageBlocks.value = []
  try {
    const { data } = await getCsFaqPage(id)
    pageDetail.value = data.data
    // 后端已与 schema 默认值合并，前端只做浅拷贝以便就地编辑
    pageValues.value = { ...data.data.values }
    pageBlocks.value = data.data.blocks.map((b) => ({ ...b, data: { ...b.data } }))

    if (data.data.template.is_blocks && !blockOptions.value.length) {
      const res = await getCsFaqPageBlocks()
      blockOptions.value = res.data.data
    }
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '单页加载失败')
  } finally {
    pageLoading.value = false
  }
}

async function savePage() {
  const cat = selectedCategory.value
  if (!cat || !pageDetail.value) return
  pageSaving.value = true
  try {
    // 两套载体走同一接口，后端按模板分流（固定模板写 fields，区块模板写 blocks）
    if (pageDetail.value.template.is_blocks) {
      await saveCsFaqPageBlocks(cat.id, pageBlocks.value)
    } else {
      await saveCsFaqPage(cat.id, pageValues.value)
    }
    notify('ok', '单页内容已保存')
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '保存失败')
  } finally {
    pageSaving.value = false
  }
}

// ---------------- 文章 ----------------

const articles = ref<CsFaqArticleRow[]>([])
const artLoading = ref(false)
const artPagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const artFilters = ref<{ category_id: number | null; status: string; keyword: string }>({ category_id: null, status: '', keyword: '' })

function emptyForm(): CsFaqArticlePayload {
  return {
    category_id: 0, title: '', slug: '', summary: '', cover_image: null, content_md: '',
    seo_title: '', seo_keywords: '', seo_description: '', tags: '', product_ids: [],
    sort: 0, is_hot: false, status: 'draft',
  }
}
const articleEditor = ref<{ open: boolean; id: number | null; form: CsFaqArticlePayload }>(
  { open: false, id: null, form: emptyForm() },
)
const deleteArtTarget = ref<CsFaqArticleRow | null>(null)
const publishTarget = ref<{ article: CsFaqArticleRow; action: 'publish' | 'offline' } | null>(null)
const previewData = ref<{ title: string; content: string; category_name: string | null; status: string } | null>(null)
/** CMS 新闻中心：封面图上传中状态 */
const coverUploading = ref(false)

// ---- 后期增强：关联种草商品多选 ----
/** 已选商品（chips 展示用；id + 标题） */
const selectedProducts = ref<Array<{ id: number; title: string }>>([])
/** 商品搜索框状态 */
const productPicker = ref<{ keyword: string; loading: boolean; results: Array<{ id: number; title: string }> }>(
  { keyword: '', loading: false, results: [] },
)
/** 文章编辑器 SEO 折叠区展开态 */
const showSeo = ref(false)

/** 搜索商品（按标题；用于种草关联多选） */
async function searchProducts() {
  const keyword = productPicker.value.keyword.trim()
  if (!keyword) { productPicker.value.results = []; return }
  productPicker.value.loading = true
  try {
    const { data } = await getProducts({ keyword, page_size: 10 })
    productPicker.value.results = data.data.list.map((p) => ({ id: p.id, title: p.title }))
  } catch {
    productPicker.value.results = []
  } finally {
    productPicker.value.loading = false
  }
}

/** 勾选/取消一件种草商品 */
function toggleProduct(p: { id: number; title: string }) {
  const ids = articleEditor.value.form.product_ids ?? []
  const idx = ids.indexOf(p.id)
  if (idx >= 0) {
    ids.splice(idx, 1)
    selectedProducts.value = selectedProducts.value.filter((s) => s.id !== p.id)
  } else {
    ids.push(p.id)
    if (!selectedProducts.value.some((s) => s.id === p.id)) selectedProducts.value.push({ id: p.id, title: p.title })
  }
  articleEditor.value.form.product_ids = [...ids]
}

function removeProduct(id: number) {
  articleEditor.value.form.product_ids = (articleEditor.value.form.product_ids ?? []).filter((x) => x !== id)
  selectedProducts.value = selectedProducts.value.filter((s) => s.id !== id)
}

/** 封面图上传（复用 CMS 统一上传接口 /admin/cs/faq/upload） */
async function uploadCover(ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  coverUploading.value = true
  try {
    const { data } = await uploadCmsImage(file)
    articleEditor.value.form.cover_image = data.data.url
    notify('ok', '封面上传成功')
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '封面上传失败')
  } finally {
    coverUploading.value = false
    input.value = ''
  }
}

function removeCover() {
  articleEditor.value.form.cover_image = null
}

async function loadArticles(page = 1) {
  artLoading.value = true
  try {
    const { data } = await getCsFaqArticles({
      category_id: artFilters.value.category_id ?? undefined,
      status: artFilters.value.status || undefined,
      keyword: artFilters.value.keyword.trim() || undefined,
      page,
      per_page: artPagination.value.page_size,
    })
    articles.value = data.data.list
    artPagination.value = data.data.pagination
  } finally {
    artLoading.value = false
  }
}

/** 文章列表「所属栏目」筛选：切换下拉时同步高亮左树并重新拉取 */
function onArticleCategoryFilter() {
  selectedCategoryId.value = artFilters.value.category_id
  loadArticles(1)
}

function openArtCreate() {
  const form = emptyForm()
  const channel = selectedCategory.value
  form.category_id = channel && channel.type !== 'page' ? channel.id : (allNodes.value.find((n) => n.type !== 'page')?.id ?? 0)
  articleEditor.value = { open: true, id: null, form }
  resetArticlePickers()
}
function openArtEdit(a: CsFaqArticleRow) {
  articleEditor.value = {
    open: true, id: a.id,
    // 存量未迁移的行没有 markdown 源：退回 HTML 产物兜底（作者可另存为 markdown 或直接重写）
    form: {
      category_id: a.category_id, title: a.title, slug: a.slug ?? '', summary: a.summary ?? '',
      cover_image: a.cover_image ?? null,
      content_md: a.content_md ?? a.content ?? '', sort: a.sort, is_hot: a.is_hot, status: a.status,
      seo_title: a.seo_title ?? '', seo_keywords: a.seo_keywords ?? '', seo_description: a.seo_description ?? '',
      tags: (a.tags ?? []).join(', '),
      product_ids: [...(a.product_ids ?? [])],
    },
  }
  resetArticlePickers()
  // 已关联商品先用 id 占位，再从 preview 拉标题回填 chips（避免无标题的裸 id）
  selectedProducts.value = (a.product_ids ?? []).map((id) => ({ id, title: `#${id}` }))
  if (a.product_ids?.length) {
    previewCsFaqArticle(a.id).then(({ data: res }) => {
      if (res.data.products?.length) {
        selectedProducts.value = res.data.products.map((p) => ({ id: p.id, title: p.title }))
      }
    }).catch(() => {})
  }
}

/** 重置文章编辑器的商品搜索与已选（切换新建/编辑时避免串味） */
function resetArticlePickers() {
  productPicker.value = { keyword: '', loading: false, results: [] }
  selectedProducts.value = []
  showSeo.value = false
}
async function saveArticle() {
  const f = articleEditor.value.form
  if (!f.category_id) { notify('err', '请选择分类'); return }
  if (!f.title.trim()) { notify('err', '请填写标题'); return }
  if (!f.content_md.trim()) { notify('err', '请填写正文'); return }
  try {
    if (articleEditor.value.id === null) await createCsFaqArticle(f)
    else await updateCsFaqArticle(articleEditor.value.id, f)
    articleEditor.value.open = false
    notify('ok', '已保存')
    await loadArticles(artPagination.value.page)
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '保存失败')
  }
}
async function confirmPublish() {
  const target = publishTarget.value
  publishTarget.value = null
  if (!target) return
  try {
    if (target.action === 'publish') await publishCsFaqArticle(target.article.id)
    else await offlineCsFaqArticle(target.article.id)
    notify('ok', target.action === 'publish' ? '已发布' : '已下架')
    await loadArticles(artPagination.value.page)
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '操作失败')
  }
}
async function doDeleteArticle() {
  const target = deleteArtTarget.value
  deleteArtTarget.value = null
  if (!target) return
  try {
    await deleteCsFaqArticle(target.id)
    notify('ok', '已删除')
    await loadArticles(artPagination.value.page)
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '删除失败')
  }
}
async function openPreview(a: CsFaqArticleRow) {
  try {
    const { data } = await previewCsFaqArticle(a.id)
    previewData.value = { title: data.data.title, content: data.data.content, category_name: data.data.category_name, status: data.data.status }
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '预览失败')
  }
}

// ---------------- 工具 ----------------

function fmtRate(row: CsFaqArticleRow): string {
  if (row.helpful_rate === null || row.helpful_count + row.unhelpful_count === 0) return '—'
  return `${Math.round(row.helpful_rate * 100)}%`
}

function statusClass(status: string): string {
  return { draft: 'bg-slate-100 text-slate-500', published: 'bg-green-50 text-green-600', offline: 'bg-amber-50 text-amber-600' }[status] ?? 'bg-slate-100 text-slate-500'
}

// ---------------- 公告语境（CMS-204） ----------------

/** 公告并入内容中心后寄存的栏目名（与后端迁移 000098、AnnouncementController 同一口径） */
const ANNOUNCEMENT_CHANNEL_NAME = '公告'

/**
 * 「热门」标记的文案按栏目语境显示
 *
 * 公告栏目的「热门」在业务上就是「置顶」（公开接口把它映射回 `is_top`），
 * 在公告栏目里显示「热门」会让运营无从判断这条公告会不会被顶到最前。
 */
function hotLabel(categoryId: number): string {
  const node = allNodes.value.find((n) => n.id === categoryId)
  return node?.name === ANNOUNCEMENT_CHANNEL_NAME ? '置顶' : '热门'
}

/** 文章表单里的标记文案（新建时看当前所选栏目，编辑时看文章所在栏目） */
const formHotLabel = computed(() => hotLabel(articleEditor.value.form.category_id))

onMounted(async () => {
  await loadCategories()
  try {
    const { data } = await getCsFaqPageTemplates()
    templateOptions.value = data.data
  } catch {
    // 模板下拉失败不阻断页面（新增栏目仍可用，只是没有单页模板可选项）
  }
})
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="cs-faq-view">
    <!-- 标题 -->
    <div class="mb-4 flex items-center gap-3">
      <h2 class="text-lg font-semibold text-slate-800">内容管理</h2>
      <span class="text-xs text-slate-400">栏目树 / 文章 / 单页</span>
    </div>

    <p v-if="tip" class="mb-3 rounded-md px-3 py-2 text-xs" :class="tipType === 'ok' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500'" data-testid="cs-faq-tip">{{ tip }}</p>

    <div class="flex gap-5">
      <!-- 左：栏目树 -->
      <aside class="w-64 shrink-0 border-r border-slate-100 pr-4" data-testid="cs-faq-category-tree">
        <div class="mb-3 flex items-center justify-between">
          <span class="text-[13px] font-medium text-slate-700">栏目树</span>
          <div class="flex gap-2">
            <button v-if="canManage" class="text-[13px] text-slate-500 hover:text-[#1677ff]" :disabled="sortSaving" data-testid="cs-cat-save-sort" @click="saveSort">保存排序</button>
            <button v-if="canManage" class="flex items-center gap-0.5 text-[13px] text-[#1677ff] hover:underline" data-testid="cs-cat-create" @click="openCatCreate"><Plus class="h-3.5 w-3.5" /> 新增</button>
          </div>
        </div>

        <ul class="space-y-0.5">
          <li v-for="row in flatCategories" :key="row.node.id">
            <div
              class="group flex items-center gap-1.5 rounded-md px-2 py-1.5 text-[13px]"
              :class="row.node.id === selectedCategoryId ? 'bg-[#e6f4ff] text-[#1677ff]' : 'hover:bg-slate-50'"
              :style="{ paddingLeft: `${8 + row.depth * 14}px` }"
              :data-testid="`cs-faq-category-row-${row.node.id}`"
              @click="selectCategory(row.node)"
            >
              <button class="flex-1 truncate text-left" :data-testid="`cs-cat-select-${row.node.id}`">
                {{ row.node.name }}
              </button>
              <span class="shrink-0 rounded px-1 py-0.5 text-[10px]" :class="row.node.type === 'page' ? 'bg-purple-50 text-purple-500' : 'bg-slate-100 text-slate-500'">{{ categoryBadge(row.node) }}</span>
              <span v-if="row.node.type !== 'page'" class="shrink-0 text-[11px] text-slate-400">{{ row.node.articles_count }}</span>
              <span v-if="row.node.show_in_nav" class="shrink-0 text-[10px] text-[#1677ff]" title="显示在前台导航">导航</span>
              <span v-if="!row.node.is_active" class="shrink-0 text-[10px] text-slate-400">停用</span>
              <span class="hidden shrink-0 items-center gap-1 group-hover:flex">
                <template v-if="canManage">
                  <button class="text-slate-400 hover:text-[#1677ff]" :data-testid="`cs-cat-add-child-${row.node.id}`" title="新增子栏目" @click.stop="openCatCreateChild(row.node)"><Plus class="h-3 w-3" /></button>
                  <button class="text-slate-400 hover:text-[#1677ff]" :data-testid="`cs-cat-edit-${row.node.id}`" title="编辑" @click.stop="openCatEdit(row.node)"><Pencil class="h-3 w-3" /></button>
                  <button class="text-slate-400 hover:text-[#1677ff]" :data-testid="`cs-cat-toggle-${row.node.id}`" :title="row.node.is_active ? '停用' : '启用'" @click.stop="toggleCategory(row.node)">{{ row.node.is_active ? '停' : '启' }}</button>
                  <button class="text-slate-400 hover:text-[#ff4d4f]" :data-testid="`cs-cat-delete-${row.node.id}`" title="删除" @click.stop="deleteCatTarget = row.node"><Trash2 class="h-3 w-3" /></button>
                </template>
                <span v-else class="text-slate-300">只读</span>
              </span>
            </div>
          </li>
        </ul>

        <div v-if="catLoading" class="py-4"><LoadingSpinner /></div>
        <p v-if="!flatCategories.length && !catLoading" class="px-2 py-8 text-center text-xs text-slate-400" data-testid="cs-cat-empty">暂无栏目</p>
      </aside>

      <!-- 右：内容区 -->
      <section class="min-w-0 flex-1">
        <!-- 未选中 -->
        <div v-if="!selectedCategory" class="py-16 text-center text-sm text-slate-400" data-testid="cs-faq-empty">请从左侧选择一个栏目</div>

        <!-- 单页：字段表单 -->
        <div v-else-if="selectedCategory.type === 'page'" data-testid="cs-faq-page">
          <div class="mb-3 flex flex-wrap items-center gap-2">
            <h3 class="text-[15px] font-medium text-slate-800">{{ selectedCategory.name }}</h3>
            <span v-if="pageDetail" class="rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-500">{{ pageDetail.template.label }}</span>
            <a
              v-if="selectedCategory.slug"
              :href="`/p/${selectedCategory.slug}`"
              target="_blank"
              rel="noopener"
              class="flex items-center gap-1 text-[13px] text-[#1677ff] hover:underline"
              :data-testid="`cs-page-link-${selectedCategory.id}`"
            >
              /p/{{ selectedCategory.slug }} <ExternalLink class="h-3 w-3" />
            </a>
          </div>

          <div v-if="pageLoading" class="py-12"><LoadingSpinner /></div>
          <template v-else-if="pageDetail">
            <!-- CMS-203：区块化模板走区块编辑器 -->
            <PageBlockEditor
              v-if="pageDetail.template.is_blocks"
              v-model="pageBlocks"
              :options="blockOptions"
              :channels="channelOptions"
              :disabled="!canManage"
              @upload-error="(msg: string) => notify('err', msg)"
            />
            <PageFieldForm
              v-else
              :schema="pageDetail.template.fields"
              v-model="pageValues"
              :disabled="!canManage"
              @upload-error="(msg: string) => notify('err', msg)"
            />
            <div v-if="canManage" class="mt-5 flex justify-end">
              <button class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff]" :disabled="pageSaving" data-testid="cs-page-save" @click="savePage">
                {{ pageSaving ? '保存中…' : '保存单页' }}
              </button>
            </div>
          </template>
          <p v-else class="py-12 text-center text-sm text-slate-400" data-testid="cs-page-empty">单页内容加载失败或模板无效</p>
        </div>

        <!-- 栏目：文章列表 -->
        <div v-else data-testid="cs-faq-articles">
          <div class="mb-3 flex flex-wrap items-center gap-2 text-[13px]">
            <h3 class="text-[15px] font-medium text-slate-800">{{ selectedCategory.name }}</h3>
            <select v-model.number="artFilters.category_id" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-article-category-filter" @change="onArticleCategoryFilter">
              <option v-for="n in allNodes.filter((x) => x.type !== 'page')" :key="n.id" :value="n.id">{{ n.name }}</option>
            </select>
            <select v-model="artFilters.status" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-article-status-filter" @change="loadArticles(1)">
              <option value="">全部状态</option>
              <option value="draft">草稿</option>
              <option value="published">已发布</option>
              <option value="offline">已下架</option>
            </select>
            <input v-model="artFilters.keyword" type="text" placeholder="标题/正文关键词" class="rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-article-keyword" @keyup.enter="loadArticles(1)" />
            <button class="rounded-md border border-slate-300 px-4 py-1.5 text-slate-600 hover:bg-slate-50" data-testid="cs-article-search" @click="loadArticles(1)">查询</button>
            <button v-if="canManage" class="ml-auto flex items-center gap-1 rounded-md bg-[#1677ff] px-4 py-1.5 text-white hover:bg-[#4096ff]" data-testid="cs-article-create" @click="openArtCreate"><Plus class="h-3.5 w-3.5" /> 新增文章</button>
          </div>

          <table class="w-full text-[13px]">
            <thead>
              <tr class="border-b border-slate-200 text-left text-slate-500">
                <th class="px-3 py-1.5">标题</th>
                <th class="px-3 py-1.5">状态</th>
                <th class="px-3 py-1.5">浏览</th>
                <th class="px-3 py-1.5">有帮助率</th>
                <th class="px-3 py-1.5">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in articles" :key="a.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`cs-faq-article-row-${a.id}`">
                <td class="max-w-[240px] truncate px-3 py-1.5 text-black">
                  {{ a.title }}
                  <span v-if="a.is_hot" class="ml-1 rounded bg-[#fff1f0] px-1.5 py-0.5 text-[10px] text-[#ff4d4f]" :data-testid="`cs-article-hot-${a.id}`">{{ hotLabel(a.category_id) }}</span>
                </td>
                <td class="px-3 py-1.5">
                  <span class="rounded px-2 py-0.5 text-xs" :class="statusClass(a.status)" :data-testid="`cs-article-status-${a.id}`">{{ STATUS_LABELS[a.status] }}</span>
                </td>
                <td class="px-3 py-1.5 text-black">{{ a.view_count }}</td>
                <td class="px-3 py-1.5 text-black" :data-testid="`cs-article-rate-${a.id}`">{{ fmtRate(a) }}</td>
                <td class="px-3 py-1.5">
                  <div class="flex items-center gap-2">
                    <template v-if="canManage">
                      <button class="text-[#1677ff] hover:underline" :data-testid="`cs-article-edit-${a.id}`" @click="openArtEdit(a)">编辑</button>
                      <button v-if="a.status !== 'published'" class="text-green-600 hover:underline" :data-testid="`cs-article-publish-${a.id}`" @click="publishTarget = { article: a, action: 'publish' }">发布</button>
                      <button v-else class="text-amber-600 hover:underline" :data-testid="`cs-article-offline-${a.id}`" @click="publishTarget = { article: a, action: 'offline' }">下架</button>
                    </template>
                    <button class="text-slate-500 hover:underline" :data-testid="`cs-article-preview-${a.id}`" @click="openPreview(a)"><Eye class="inline h-3.5 w-3.5" /> 预览</button>
                    <button v-if="canManage" class="text-[#ff4d4f] hover:underline" :data-testid="`cs-article-delete-${a.id}`" @click="deleteArtTarget = a">删除</button>
                  </div>
                </td>
              </tr>
              <tr v-if="artLoading">
                <td colspan="5"><LoadingSpinner /></td>
              </tr>
              <tr v-if="!articles.length && !artLoading">
                <td colspan="5" class="px-3 py-12 text-center text-slate-400" data-testid="cs-article-empty">暂无文章</td>
              </tr>
            </tbody>
          </table>

          <!-- 分页 -->
          <div v-if="artPagination.total_pages > 1" class="mt-4 flex items-center justify-between text-[13px] text-slate-500">
            <span>共 {{ artPagination.total }} 条记录 / 每页 {{ artPagination.page_size }} 条</span>
            <div class="flex items-center gap-1">
              <button
                class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
                :disabled="artPagination.page <= 1" @click="loadArticles(artPagination.page - 1)"
              ><ChevronLeft class="h-4 w-4" /></button>
              <button
                v-for="page in artPagination.total_pages" :key="page"
                class="h-7 min-w-7 rounded border px-1.5"
                :class="page === artPagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
                @click="loadArticles(page)"
              >{{ page }}</button>
              <button
                class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
                :disabled="artPagination.page >= artPagination.total_pages" @click="loadArticles(artPagination.page + 1)"
              ><ChevronRight class="h-4 w-4" /></button>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- 栏目编辑弹窗 -->
    <div v-if="catEditor.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" data-testid="cs-category-editor" @click.self="catEditor.open = false">
      <div class="max-h-[85vh] w-[420px] overflow-y-auto rounded-xl bg-white p-6">
        <h3 class="mb-3 text-sm font-semibold text-slate-800">{{ catEditor.id === null ? '新增栏目' : '编辑栏目' }}</h3>

        <label class="mb-2 block text-[13px]">
          <span class="mb-1 block text-slate-500">名称</span>
          <input v-model="catEditor.name" type="text" maxlength="64" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-name" />
        </label>

        <label class="mb-2 block text-[13px]">
          <span class="mb-1 block text-slate-500">类型</span>
          <select v-model="catEditor.type" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-type">
            <option value="channel">栏目（挂文章列表）</option>
            <option value="page">单页（关于我们 / 联系我们…）</option>
          </select>
        </label>

        <!-- CMS 新闻中心：列表形态（仅栏目有「图文卡片 / 列表行」之分，单页用不上） -->
        <label v-if="catEditor.type === 'channel'" class="mb-2 block text-[13px]">
          <span class="mb-1 block text-slate-500">列表形态</span>
          <select v-model="catEditor.list_style" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-list-style">
            <option value="list">列表行（标题 + 摘要）</option>
            <option value="card">图文卡片（封面 + 标题）</option>
          </select>
        </label>

        <label class="mb-2 block text-[13px]">
          <span class="mb-1 block text-slate-500">上级栏目</span>
          <select v-model.number="catEditor.parent_id" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-parent">
            <option :value="0">顶级栏目</option>
            <option v-for="row in parentOptions" :key="row.node.id" :value="row.node.id">{{ '　'.repeat(row.depth) }}{{ row.node.name }}</option>
          </select>
        </label>

        <!-- 单页专属 -->
        <template v-if="catEditor.type === 'page'">
          <label class="mb-2 block text-[13px]">
            <span class="mb-1 block text-slate-500">模板</span>
            <select v-model="catEditor.template" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-template">
              <option value="">请选择模板</option>
              <option v-for="t in templateOptions" :key="t.key" :value="t.key">{{ t.label }}</option>
            </select>
          </label>
          <label class="mb-2 block text-[13px]">
            <span class="mb-1 block text-slate-500">slug（前台 /p/{slug} 访问）</span>
            <input v-model="catEditor.slug" type="text" maxlength="64" placeholder="如 about" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-slug" />
          </label>

          <!-- CMS-202：SEO（三项都可留空，前台按栏目名/正文回落） -->
          <div class="mb-2 rounded-md bg-slate-50 p-3">
            <p class="mb-2 text-[12px] text-slate-500">
              搜索引擎优化（留空则前台自动回落：标题用栏目名、描述取正文首段）
            </p>
            <label class="mb-2 block text-[13px]">
              <span class="mb-1 block text-slate-500">SEO 标题</span>
              <input v-model="catEditor.seo_title" type="text" maxlength="128" placeholder="留空则用栏目名" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-seo-title" />
            </label>
            <label class="mb-2 block text-[13px]">
              <span class="mb-1 block text-slate-500">关键词（逗号分隔）</span>
              <input v-model="catEditor.seo_keywords" type="text" maxlength="255" placeholder="电商,正品" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-seo-keywords" />
            </label>
            <label class="block text-[13px]">
              <span class="mb-1 block text-slate-500">页面描述</span>
              <textarea v-model="catEditor.seo_description" rows="2" maxlength="255" placeholder="一句话说明这个页面" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-seo-description" />
            </label>
          </div>
        </template>

        <label class="mb-2 block text-[13px]">
          <span class="mb-1 block text-slate-500">排序</span>
          <input v-model.number="catEditor.sort" type="number" min="0" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-category-sort" />
        </label>

        <div class="mb-3 space-y-1.5 text-[13px] text-slate-600">
          <label class="flex items-center gap-2">
            <input v-model="catEditor.is_active" type="checkbox" data-testid="cs-category-active" /> 启用
          </label>
          <label class="flex items-center gap-2">
            <input v-model="catEditor.show_in_nav" type="checkbox" data-testid="cs-category-nav" /> 显示在前台导航
          </label>
        </div>

        <div class="flex justify-end gap-2">
          <button class="rounded-md border border-slate-200 px-4 py-1.5 text-[13px] text-slate-500" @click="catEditor.open = false">取消</button>
          <button class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white" data-testid="cs-category-save" @click="saveCategory">保存</button>
        </div>
      </div>
    </div>

    <!-- 文章编辑弹窗 -->
    <div v-if="articleEditor.open" class="fixed inset-0 z-50 flex justify-end bg-black/40" data-testid="cs-article-editor" @click.self="articleEditor.open = false">
      <div class="flex h-full w-full max-w-xl flex-col overflow-y-auto bg-white p-5">
        <div class="mb-4 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-slate-800">{{ articleEditor.id === null ? '新增文章' : '编辑文章' }}</h3>
          <button class="text-slate-400" @click="articleEditor.open = false"><X class="h-4 w-4" /></button>
        </div>
        <label class="mb-3 block text-[13px]">
          <span class="mb-1 block text-slate-500">分类</span>
          <select v-model.number="articleEditor.form.category_id" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-category">
            <option :value="0" disabled>请选择分类</option>
            <option v-for="n in allNodes.filter((x) => x.type !== 'page')" :key="n.id" :value="n.id">{{ n.name }}</option>
          </select>
        </label>
        <label class="mb-3 block text-[13px]">
          <span class="mb-1 block text-slate-500">标题</span>
          <input v-model="articleEditor.form.title" type="text" maxlength="191" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-title" />
        </label>
        <label class="mb-3 block text-[13px]">
          <span class="mb-1 block text-slate-500">URL 别名 slug（前台 /news/{slug}；留空按标题自动生成，编辑时留空不改）</span>
          <input v-model="articleEditor.form.slug" type="text" maxlength="191" placeholder="如 how-to-refund" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-slug" />
        </label>
        <label class="mb-3 block text-[13px]">
          <span class="mb-1 block text-slate-500">摘要</span>
          <input v-model="articleEditor.form.summary" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-summary" />
        </label>
        <label class="mb-3 block text-[13px]">
          <span class="mb-1 block text-slate-500">标签（逗号分隔，用于专题聚合）</span>
          <input v-model="articleEditor.form.tags" type="text" placeholder="如 新品, 促销" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-tags" />
        </label>
        <div class="mb-3 text-[13px]">
          <span class="mb-1 block text-slate-500">封面图（图文卡片新闻用；列表行新闻可不填）</span>
          <div class="flex items-center gap-3">
            <img
              v-if="articleEditor.form.cover_image"
              :src="articleEditor.form.cover_image"
              alt="封面预览"
              class="h-20 w-20 rounded-md border border-slate-200 object-cover"
              data-testid="cs-article-form-cover-preview"
            />
            <div class="flex flex-col gap-2">
              <label class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-slate-300 px-3 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" data-testid="cs-article-form-cover-upload">
                <input type="file" accept="image/*" class="hidden" :disabled="coverUploading" @change="uploadCover" />
                {{ coverUploading ? '上传中…' : '上传封面' }}
              </label>
              <button v-if="articleEditor.form.cover_image" type="button" class="inline-flex items-center gap-1 text-[13px] text-[#ff4d4f] hover:underline" data-testid="cs-article-form-cover-remove" @click="removeCover">移除封面</button>
            </div>
          </div>
        </div>
        <div class="mb-3 text-[13px]">
          <span class="mb-1 block text-slate-500">正文（Markdown）</span>
          <MarkdownEditor
            v-model="articleEditor.form.content_md"
            data-testid="cs-article-form-content"
            @upload-error="(msg: string) => notify('err', msg)"
          />
          <span class="mt-1 block text-xs text-slate-400">
            支持标题、段落、列表、表格、图片与链接。工具栏的图片按钮会走后台统一上传接口；
            链接锚点可用标题生成的 id（如 <code>#content-小节标题</code>）。
            保存时后端会渲染并按白名单净化（脚本、内联样式等会被剥离）；
            以「预览」按钮看到的效果为准（与用户端同一份内容）。
          </span>
        </div>
        <!-- 后期增强：文章级 SEO 折叠区（留空则前台回落栏目/标题摘要） -->
        <div class="mb-3 rounded-md border border-slate-200 text-[13px]">
          <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-slate-600 hover:bg-slate-50" data-testid="cs-article-form-seo-toggle" @click="showSeo = !showSeo">
            <span>SEO 设置（可选）</span>
            <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="showSeo ? 'rotate-90' : ''" />
          </button>
          <div v-if="showSeo" class="space-y-2 border-t border-slate-100 px-3 py-3">
            <label class="block">
              <span class="mb-1 block text-slate-500">SEO 标题</span>
              <input v-model="articleEditor.form.seo_title" type="text" maxlength="128" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-seo-title" />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">SEO 关键词</span>
              <input v-model="articleEditor.form.seo_keywords" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-seo-keywords" />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">SEO 描述</span>
              <input v-model="articleEditor.form.seo_description" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-seo-description" />
            </label>
          </div>
        </div>
        <!-- 后期增强：关联种草商品（前台详情页展示，商品详情页反查相关资讯） -->
        <div class="mb-3 rounded-md border border-slate-200 px-3 py-3 text-[13px]">
          <span class="mb-2 block text-slate-500">关联种草商品（可选）</span>
          <div class="mb-2 flex flex-wrap gap-1.5" data-testid="cs-article-form-products">
            <span
              v-for="p in selectedProducts" :key="p.id"
              class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
            >
              {{ p.title }}
              <button type="button" class="text-slate-400 hover:text-[#ff4d4f]" :data-testid="`cs-article-form-product-remove-${p.id}`" @click="removeProduct(p.id)"><X class="h-3 w-3" /></button>
            </span>
            <span v-if="!selectedProducts.length" class="text-xs text-slate-400">尚未关联商品</span>
          </div>
          <div class="flex gap-2">
            <input
              v-model="productPicker.keyword" type="text" placeholder="搜索商品标题"
              class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              data-testid="cs-article-form-product-search"
              @keyup.enter="searchProducts"
            />
            <button type="button" class="shrink-0 rounded-md border border-slate-300 px-3 py-1.5 text-slate-600 hover:bg-slate-50" data-testid="cs-article-form-product-search-btn" @click="searchProducts">搜索</button>
          </div>
          <div v-if="productPicker.results.length" class="mt-2 max-h-40 overflow-y-auto rounded-md border border-slate-100">
            <button
              v-for="p in productPicker.results" :key="p.id" type="button"
              class="flex w-full items-center justify-between px-3 py-1.5 text-left text-xs hover:bg-slate-50"
              :data-testid="`cs-article-form-product-option-${p.id}`"
              @click="toggleProduct(p)"
            >
              <span class="truncate text-slate-600">{{ p.title }}</span>
              <span class="shrink-0 text-[#1677ff]">{{ (articleEditor.form.product_ids ?? []).includes(p.id) ? '已选' : '添加' }}</span>
            </button>
          </div>
        </div>
        <div class="mb-4 flex items-center gap-4 text-[13px] text-slate-600">
          <label class="flex items-center gap-2"><input v-model="articleEditor.form.is_hot" type="checkbox" data-testid="cs-article-form-hot" /> {{ formHotLabel }}</label>
          <label class="flex items-center gap-2">排序 <input v-model.number="articleEditor.form.sort" type="number" min="0" class="w-20 rounded border border-slate-300 px-2 py-1 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-sort" /></label>
        </div>
        <div class="mt-auto flex justify-end gap-2">
          <button class="rounded-md border border-slate-200 px-4 py-1.5 text-[13px] text-slate-500" @click="articleEditor.open = false">取消</button>
          <button class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white" data-testid="cs-article-save" @click="saveArticle">保存草稿</button>
        </div>
      </div>
    </div>

    <!-- 预览弹窗 -->
    <div v-if="previewData" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" data-testid="cs-article-preview" @click.self="previewData = null">
      <div class="max-h-[80vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-base font-bold text-slate-800">{{ previewData.title }}</h3>
          <button class="text-slate-400" @click="previewData = null"><X class="h-4 w-4" /></button>
        </div>
        <p class="mb-3 text-xs text-slate-400">{{ previewData.category_name }} · {{ STATUS_LABELS[previewData.status] }}</p>
        <!-- 与用户端一致：正文按富文本渲染（内容已由后端净化） -->
        <div class="faq-body break-words text-sm leading-7 text-slate-700" data-testid="cs-article-preview-content" v-html="previewData.content" />
      </div>
    </div>

    <ConfirmDialog :open="!!deleteCatTarget" title="删除栏目" message="确认删除该栏目？单页将连同内容一并删除。" confirm-text="删除" danger @confirm="doDeleteCategory" @cancel="deleteCatTarget = null" />
    <ConfirmDialog :open="!!deleteArtTarget" title="删除文章" message="确认删除该文章？" confirm-text="删除" danger @confirm="doDeleteArticle" @cancel="deleteArtTarget = null" />
    <ConfirmDialog
      :open="!!publishTarget"
      :title="publishTarget?.action === 'publish' ? '发布文章' : '下架文章'"
      :message="publishTarget?.action === 'publish' ? '发布后用户端立即可见，确认发布？' : '下架后用户端将不可见，确认下架？'"
      :confirm-text="publishTarget?.action === 'publish' ? '确认发布' : '确认下架'"
      @confirm="confirmPublish"
      @cancel="publishTarget = null"
    />
  </div>
</template>

<style scoped>
/*
 * 富文本正文排版（与用户端 FaqDetailView 的 .faq-body 保持一致）。
 * 净化器不放行 style/class，段落间距、列表符号、表格边框必须由这里补齐。
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
