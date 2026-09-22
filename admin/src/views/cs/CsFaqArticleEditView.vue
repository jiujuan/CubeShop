<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Check, ChevronRight, CornerDownLeft, Save, X } from 'lucide-vue-next'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ImagePicker from '@/components/ImagePicker.vue'
import {
  createCsFaqArticle, getCsFaqArticle, getCsFaqCategories, updateCsFaqArticle, uploadCmsImage,
  type CsFaqArticlePayload, type CsFaqCategoryRow,
} from '@/api/cs'
import { getProducts } from '@/api/product'
import { ARTICLE_STATUS_OPTIONS, buildProductToken, hasProductToken, hotLabelFor } from '@/utils/csArticle'

/**
 * 内容中心 CMS · 文章新增/编辑（独立页面）
 *
 * 原先是列表页里的侧边弹层：表单字段又多（标题/栏目/slug/摘要/标签/封面/正文/
 * SEO/关联商品），抽屉里只能一路竖排，一屏放不下、编辑区还被挤到很窄。
 * 现在拆成独立路由：
 * - `/cs/faq/articles/new`          新增（可用 `?category_id=` 预选栏目）
 * - `/cs/faq/articles/:id/edit`     编辑（按 id 回源，刷新/直达不空白）
 *
 * 布局按「两个字段一行」组织（`md:grid-cols-2`），把页面高度压到约一屏；
 * SEO 与关联商品这类低频块并排放在底部，正文独占一整行。
 *
 * 正文用 Markdown 编辑（源存 content_md），HTML 产物由后端渲染 + 净化派生。
 */
const route = useRoute()
const router = useRouter()

const articleId = computed<number | null>(() => {
  const raw = Array.isArray(route.params.id) ? route.params.id[0] : route.params.id
  return raw ? Number(raw) : null
})
const isEdit = computed(() => articleId.value !== null)
const pageTitle = computed(() => (isEdit.value ? '编辑文章' : '新增文章'))

// ---------------- 栏目（channel 才是文章的容器） ----------------

interface ChannelOption { id: number; name: string; depth: number }

const channels = ref<ChannelOption[]>([])
const loading = ref(false)
const saving = ref(false)
const tip = ref('')
const tipType = ref<'ok' | 'err'>('ok')

function notify(type: 'ok' | 'err', text: string) {
  tipType.value = type
  tip.value = text
}

/** 栏目树拍平（保留深度用于下拉缩进），并剔掉单页 —— 文章不能挂在单页下 */
function flattenChannels(nodes: CsFaqCategoryRow[], depth = 0, out: ChannelOption[] = []): ChannelOption[] {
  for (const n of nodes) {
    if (n.type !== 'page') out.push({ id: n.id, name: n.name, depth })
    if (n.children?.length) flattenChannels(n.children, depth + 1, out)
  }
  return out
}

const currentChannelName = computed(
  () => channels.value.find((c) => c.id === form.value.category_id)?.name ?? null,
)
/** 公告栏目里的「热门」在业务上是「置顶」 */
const hotLabel = computed(() => hotLabelFor(currentChannelName.value))

// ---------------- 表单 ----------------

function emptyForm(categoryId: number): CsFaqArticlePayload {
  return {
    category_id: categoryId, title: '', slug: '', summary: '', cover_image: null, content_md: '',
    seo_title: '', seo_keywords: '', seo_description: '', tags: '', product_ids: [],
    sort: 0, is_hot: false, status: 'draft',
  }
}

const form = ref<CsFaqArticlePayload>(emptyForm(0))
const coverUploading = ref(false)
/** SEO 折叠区展开态（编辑时若已有 SEO 内容则自动展开） */
const showSeo = ref(false)

// ---- 关联种草商品 ----

/** 已选商品：`id` 是勾选/提交用的自增主键，`public_id` 是写进正文标记的对外标识 */
interface PickedProduct { id: number; public_id: string; title: string }

const selectedProducts = ref<PickedProduct[]>([])
const productPicker = ref<{ keyword: string; loading: boolean; results: PickedProduct[] }>(
  { keyword: '', loading: false, results: [] },
)
/** 结果面板开合态：搜索后展开，点外部 / 取消按钮 / Esc 收起 */
const productPickerOpen = ref(false)
/** 搜索行 + 结果面板容器，用于「点击外部关闭」的范围判断 */
const productPickerRef = ref<HTMLElement | null>(null)
/** markdown 编辑器实例（「插入正文」要在光标处放标记） */
const bodyEditorRef = ref<{ insertAtCursor: (text: string) => void } | null>(null)

/** 该商品是否已关联（结果行右侧「已选」绿标用） */
function isProductSelected(id: number): boolean {
  return (form.value.product_ids ?? []).includes(id)
}

/** 该商品的卡片是否已经插进正文（chip 上标出来，避免重复插） */
function isProductInlined(publicId: string): boolean {
  return hasProductToken(form.value.content_md, publicId)
}

/** 收起结果面板（点外部 / 取消按钮 / Esc 共用同一出口） */
function closeProductPicker() {
  productPickerOpen.value = false
}

/**
 * 点击面板外部时收起
 *
 * 用 mousedown 而不是 click：click 要等 mouseup 才触发，
 * 在「点外部的同时又点到别的按钮」时会先执行那个按钮的动作，面板一闪才关，体验很怪。
 */
function onProductPickerMousedown(e: MouseEvent) {
  if (!productPickerOpen.value) return
  if (productPickerRef.value && !productPickerRef.value.contains(e.target as Node)) {
    closeProductPicker()
  }
}

function onProductPickerKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') closeProductPicker()
}

onMounted(() => {
  document.addEventListener('mousedown', onProductPickerMousedown)
  document.addEventListener('keydown', onProductPickerKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onProductPickerMousedown)
  document.removeEventListener('keydown', onProductPickerKeydown)
})

async function searchProducts() {
  const keyword = productPicker.value.keyword.trim()
  if (!keyword) { productPicker.value.results = []; closeProductPicker(); return }
  productPicker.value.loading = true
  // 先展开：让「搜索中…」与「没有匹配的商品」都在面板里给出反馈，而不是静默无反应
  productPickerOpen.value = true
  try {
    const { data } = await getProducts({ keyword, page_size: 10 })
    productPicker.value.results = data.data.list.map((p) => ({ id: p.id, public_id: p.public_id, title: p.title }))
  } catch {
    productPicker.value.results = []
  } finally {
    productPicker.value.loading = false
  }
}

function toggleProduct(p: PickedProduct) {
  const ids = form.value.product_ids ?? []
  const idx = ids.indexOf(p.id)
  if (idx >= 0) {
    ids.splice(idx, 1)
    selectedProducts.value = selectedProducts.value.filter((s) => s.id !== p.id)
  } else {
    ids.push(p.id)
    if (!selectedProducts.value.some((s) => s.id === p.id)) {
      selectedProducts.value.push({ id: p.id, public_id: p.public_id, title: p.title })
    }
  }
  form.value.product_ids = [...ids]
}

/**
 * 把商品卡标记插到正文光标处（独占一段）
 *
 * 前后各留一个空行：后端只认「独占一段」的标记（渲染成 `<p>[[product:x]]</p>`），
 * 贴着上下的文字写成行内的话标记会按字面保留、前台直接显示这串字符。
 *
 * 顺带把商品勾选上（未选中则选中）：没关联的商品即使正文里有标记，出口也不给数据，
 * 前台那处就是空的 —— 与其让作者去理解这条规则，不如插入时就一并关联。
 */
function insertProductToken(p: PickedProduct) {
  if (!isProductSelected(p.id)) toggleProduct(p)

  const editor = bodyEditorRef.value
  if (!editor) return

  editor.insertAtCursor(`\n\n${buildProductToken(p.public_id)}\n\n`)
  notify('ok', `已把「${p.title}」的商品卡插入正文光标处`)
}

function removeProduct(id: number) {
  form.value.product_ids = (form.value.product_ids ?? []).filter((x) => x !== id)
  selectedProducts.value = selectedProducts.value.filter((s) => s.id !== id)
}

// ---- 封面 ----

async function uploadCover(ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  coverUploading.value = true
  try {
    const { data } = await uploadCmsImage(file)
    form.value.cover_image = data.data.url
    notify('ok', '封面上传成功')
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '封面上传失败')
  } finally {
    coverUploading.value = false
    input.value = ''
  }
}

function removeCover() {
  form.value.cover_image = null
}

// 封面：原上传入口保留；另提供「从媒体库选择」复用已有图片
const coverPickerOpen = ref(false)
function openCoverLibrary() {
  coverPickerOpen.value = true
}
function onCoverPicked(urls: string[]) {
  if (urls.length) form.value.cover_image = urls[0]
}

// ---------------- 加载 / 保存 ----------------

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getCsFaqCategories()
    channels.value = flattenChannels(data.data)

    if (!channels.value.length) {
      notify('err', '还没有可用的栏目，请先在内容管理里创建栏目')
      return
    }

    if (isEdit.value) {
      const res = await getCsFaqArticle(articleId.value as number)
      const a = res.data.data
      form.value = {
        category_id: a.category_id,
        title: a.title,
        slug: a.slug ?? '',
        summary: a.summary ?? '',
        cover_image: a.cover_image ?? null,
        // 存量未迁移的行没有 markdown 源：退回 HTML 产物兜底（作者可另存为 markdown 或直接重写）
        content_md: a.content_md ?? a.content ?? '',
        seo_title: a.seo_title ?? '',
        seo_keywords: a.seo_keywords ?? '',
        seo_description: a.seo_description ?? '',
        tags: (a.tags ?? []).join(', '),
        product_ids: [...(a.product_ids ?? [])],
        sort: a.sort,
        is_hot: a.is_hot,
        status: a.status,
      }
      selectedProducts.value = (a.products ?? []).map((p) => ({ id: p.id, public_id: p.public_id, title: p.title }))
      // 已填过 SEO 就默认展开，免得运营以为丢了
      showSeo.value = !!(a.seo_title || a.seo_keywords || a.seo_description)
    } else {
      const fromQuery = Number(route.query.category_id ?? 0)
      const initial = channels.value.some((c) => c.id === fromQuery) ? fromQuery : channels.value[0].id
      form.value = emptyForm(initial)
    }
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '加载失败')
  } finally {
    loading.value = false
  }
}

async function save() {
  const f = form.value
  if (!f.category_id) { notify('err', '请选择所属栏目'); return }
  if (!f.title.trim()) { notify('err', '请填写标题'); return }
  if (!f.content_md.trim()) { notify('err', '请填写正文'); return }

  saving.value = true
  try {
    if (isEdit.value) await updateCsFaqArticle(articleId.value as number, f)
    else await createCsFaqArticle(f)
    // 回列表并带上所属栏目，运营接着看的就是刚才那一栏
    await router.push({ name: 'cs-faq', query: { category_id: String(f.category_id) } })
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '保存失败')
  } finally {
    saving.value = false
  }
}

function goBack() {
  router.push({ name: 'cs-faq', query: form.value.category_id ? { category_id: String(form.value.category_id) } : {} })
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-6 shadow-sm" data-testid="cs-article-edit-view">
    <div class="mb-5 flex items-center justify-between border-b-2 border-[#1677ff] pb-2">
      <div class="flex items-baseline gap-3">
        <h2 class="text-lg font-semibold text-slate-800">{{ pageTitle }}</h2>
        <span v-if="currentChannelName" class="text-xs text-slate-400">{{ currentChannelName }}</span>
      </div>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="flex items-center gap-1 rounded-md bg-[#1677ff] px-3 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-60"
          :disabled="saving || loading"
          data-testid="cs-article-save"
          @click="save"
        >
          <Save class="h-3.5 w-3.5" /> {{ saving ? '保存中…' : '保存' }}
        </button>
        <button
          type="button"
          class="flex items-center gap-1 rounded-md px-2.5 py-1.5 text-[13px] text-slate-600 transition-colors hover:bg-slate-100 hover:text-[#1677ff]"
          data-testid="cs-article-back"
          @click="goBack"
        >
          <ArrowLeft class="h-4 w-4" /> 返回
        </button>
      </div>
    </div>

    <p v-if="tip" class="mb-3 rounded-md px-3 py-2 text-xs" :class="tipType === 'ok' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500'" data-testid="cs-article-tip">{{ tip }}</p>

    <div v-if="loading" class="py-16"><LoadingSpinner /></div>

    <div v-else class="mx-auto max-w-5xl text-[13px]">
      <!-- 基本信息：两列成行，压缩纵向高度 -->
      <div class="grid grid-cols-1 gap-x-5 gap-y-3 md:grid-cols-2" data-testid="cs-article-form-grid">
        <label class="block">
          <span class="mb-1 block text-slate-500">所属栏目 <span class="text-red-500">*</span></span>
          <select v-model.number="form.category_id" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-category">
            <option :value="0" disabled>请选择栏目</option>
            <option v-for="c in channels" :key="c.id" :value="c.id">{{ '　'.repeat(c.depth) }}{{ c.name }}</option>
          </select>
        </label>

        <label class="block">
          <span class="mb-1 block text-slate-500">标题 <span class="text-red-500">*</span></span>
          <input v-model="form.title" type="text" maxlength="191" placeholder="文章标题" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-title" />
        </label>

        <label class="block">
          <span class="mb-1 block text-slate-500">URL 别名 slug（前台 /news/{slug}；留空按标题自动生成，编辑时留空不改）</span>
          <input v-model="form.slug" type="text" maxlength="191" placeholder="如 how-to-refund" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-slug" />
        </label>

        <label class="block">
          <span class="mb-1 block text-slate-500">摘要（列表行与卡片展示用）</span>
          <input v-model="form.summary" type="text" maxlength="255" placeholder="一句话概括" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-summary" />
        </label>

        <label class="block">
          <span class="mb-1 block text-slate-500">标签（逗号分隔，用于专题聚合）</span>
          <input v-model="form.tags" type="text" placeholder="如 新品, 促销" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-tags" />
        </label>

        <!-- 状态：前台可见与否只看这一项，选项文案直接写明可见性（运营最容易漏发布） -->
        <label class="block">
          <span class="mb-1 block text-slate-500">状态</span>
          <select v-model="form.status" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-status">
            <option v-for="o in ARTICLE_STATUS_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}（{{ o.hint }}）</option>
          </select>
        </label>

        <label class="block">
          <span class="mb-1 block text-slate-500">排序</span>
          <input v-model.number="form.sort" type="number" min="0" class="w-32 rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-sort" />
        </label>

        <label class="flex items-center gap-2 self-end pb-2.5 text-slate-600">
          <input v-model="form.is_hot" type="checkbox" data-testid="cs-article-form-hot" /> {{ hotLabel }}
        </label>
      </div>

      <!-- 封面图（压成一行：缩略图 + 操作按钮并排） -->
      <div class="mt-4 flex items-start gap-3">
        <span class="w-24 shrink-0 pt-1.5 text-slate-500">封面图</span>
        <div class="flex items-center gap-3">
          <img
            v-if="form.cover_image"
            :src="form.cover_image"
            alt="封面预览"
            class="h-16 w-24 rounded-md border border-slate-200 object-cover"
            data-testid="cs-article-form-cover-preview"
          />
          <div class="flex flex-col gap-1.5">
            <label class="inline-flex w-fit cursor-pointer items-center gap-1 rounded-md border border-slate-300 px-3 py-1.5 text-slate-600 hover:bg-slate-50" data-testid="cs-article-form-cover-upload">
              <input type="file" accept="image/*" class="hidden" :disabled="coverUploading" @change="uploadCover" />
              {{ coverUploading ? '上传中…' : '上传封面' }}
            </label>
            <button v-if="form.cover_image" type="button" class="w-fit text-[#ff4d4f] hover:underline" data-testid="cs-article-form-cover-remove" @click="removeCover">移除封面</button>
            <button type="button" v-permission="'media.view'" class="w-fit rounded-md border border-slate-300 px-3 py-1.5 text-slate-600 hover:bg-slate-50" data-testid="cs-article-form-cover-library" @click="openCoverLibrary">从媒体库选择</button>
            <span class="text-xs text-slate-400">图文卡片新闻建议填；列表行新闻可不填</span>
          </div>
        </div>
      </div>

      <!-- 正文：独占整行 -->
      <div class="mt-4">
        <span class="mb-1 block text-slate-500">正文（Markdown） <span class="text-red-500">*</span></span>
        <MarkdownEditor
          ref="bodyEditorRef"
          v-model="form.content_md"
          data-testid="cs-article-form-content"
          @upload-error="(msg: string) => notify('err', msg)"
        />
        <span class="mt-1 block text-xs text-slate-400">
          支持标题、段落、列表、表格、图片与链接。工具栏的图片按钮会走后台统一上传接口；
          链接锚点可用标题生成的 id（如 <code>#content-小节标题</code>）。
          保存时后端会渲染并按白名单净化（脚本、内联样式等会被剥离）；以列表页「预览」看到的效果为准（与用户端同一份内容）。
        </span>
      </div>

      <!-- 低频块并排：SEO 与关联种草商品 -->
      <div class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
        <!-- 文章级 SEO（留空则前台回落栏目/标题摘要） -->
        <div class="rounded-md border border-slate-200">
          <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-slate-600 hover:bg-slate-50" data-testid="cs-article-form-seo-toggle" @click="showSeo = !showSeo">
            <span>SEO 设置（可选）</span>
            <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="showSeo ? 'rotate-90' : ''" />
          </button>
          <div v-if="showSeo" class="space-y-2 border-t border-slate-100 px-3 py-3">
            <label class="block">
              <span class="mb-1 block text-slate-500">SEO 标题</span>
              <input v-model="form.seo_title" type="text" maxlength="128" placeholder="留空则用文章标题" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-seo-title" />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">SEO 关键词（逗号分隔）</span>
              <input v-model="form.seo_keywords" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-seo-keywords" />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">SEO 描述</span>
              <input v-model="form.seo_description" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-article-form-seo-description" />
            </label>
          </div>
        </div>

        <!-- 关联种草商品（前台详情页展示：插进正文 = 卡在正文里，其余落底部「相关商品」） -->
        <div class="rounded-md border border-slate-200 px-3 py-3">
          <span class="mb-2 block text-slate-500">关联种草商品（可选）</span>
          <div class="mb-2 flex flex-wrap gap-1.5" data-testid="cs-article-form-products">
            <span
              v-for="p in selectedProducts" :key="p.id"
              class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
              :class="isProductInlined(p.public_id) ? 'bg-[#e6f4ff] text-[#1677ff]' : 'bg-slate-100 text-slate-600'"
            >
              {{ p.title }}
              <span v-if="isProductInlined(p.public_id)" class="text-[10px]" :data-testid="`cs-article-form-product-inlined-${p.id}`">已插入正文</span>
              <button
                type="button" class="text-slate-400 hover:text-[#1677ff]"
                title="插入到正文光标处"
                :data-testid="`cs-article-form-product-insert-${p.id}`"
                @click="insertProductToken(p)"
              ><CornerDownLeft class="h-3 w-3" /></button>
              <button type="button" class="text-slate-400 hover:text-[#ff4d4f]" title="取消关联" :data-testid="`cs-article-form-product-remove-${p.id}`" @click="removeProduct(p.id)"><X class="h-3 w-3" /></button>
            </span>
            <span v-if="!selectedProducts.length" class="text-xs text-slate-400">尚未关联商品</span>
          </div>
          <p class="mb-2 text-xs text-slate-400">
            点商品后的箭头把商品卡插到正文光标处（会插成独立一段）；不插的商品会统一列在正文末尾的「相关商品」。
          </p>
          <div ref="productPickerRef">
            <div class="flex gap-2">
              <input
                v-model="productPicker.keyword" type="text" placeholder="搜索商品标题"
                class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
                data-testid="cs-article-form-product-search"
                @keyup.enter="searchProducts"
              />
              <button type="button" class="shrink-0 rounded-md border border-slate-300 px-3 py-1.5 text-slate-600 hover:bg-slate-50" data-testid="cs-article-form-product-search-btn" @click="searchProducts">搜索</button>
            </div>

            <!-- 搜索结果面板：点外部 / 面板内「取消」/ Esc 都能收起 -->
            <div v-if="productPickerOpen" class="mt-2 rounded-md border border-slate-200 bg-white shadow-sm" data-testid="cs-article-form-product-picker">
              <div class="flex items-center justify-between border-b border-slate-100 px-3 py-1.5">
                <span class="text-xs text-slate-400">{{ productPicker.loading ? '搜索中…' : `共 ${productPicker.results.length} 个结果` }}</span>
                <button type="button" class="text-xs text-slate-400 hover:text-[#1677ff]" data-testid="cs-article-form-product-picker-cancel" @click="closeProductPicker">取消</button>
              </div>
              <div class="max-h-40 overflow-y-auto">
                <div
                  v-for="p in productPicker.results" :key="p.id"
                  class="flex items-center gap-2 px-3 py-1.5 text-xs hover:bg-slate-50"
                >
                  <button
                    type="button"
                    class="flex flex-1 items-center justify-between gap-2 text-left"
                    :data-testid="`cs-article-form-product-option-${p.id}`"
                    @click="toggleProduct(p)"
                  >
                    <span class="truncate" :class="isProductSelected(p.id) ? 'text-green-700' : 'text-slate-600'">{{ p.title }}</span>
                    <span v-if="isProductSelected(p.id)" class="inline-flex shrink-0 items-center gap-1 text-green-600" :data-testid="`cs-article-form-product-selected-${p.id}`">
                      已选 <Check class="h-3.5 w-3.5" />
                    </span>
                    <span v-else class="shrink-0 text-[#1677ff]">添加</span>
                  </button>
                  <button
                    type="button" class="shrink-0 text-[#1677ff] hover:underline"
                    :data-testid="`cs-article-form-product-insert-option-${p.id}`"
                    @click="insertProductToken(p)"
                  >插入正文</button>
                </div>
                <p v-if="!productPicker.loading && !productPicker.results.length" class="px-3 py-3 text-center text-xs text-slate-400" data-testid="cs-article-form-product-picker-empty">没有匹配的商品</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 底部操作 -->
      <div class="mt-5 flex justify-end gap-2">
        <button type="button" class="rounded-md border border-slate-200 px-4 py-1.5 text-slate-500 hover:bg-slate-50" data-testid="cs-article-cancel" @click="goBack">取消</button>
        <button type="button" class="rounded-md bg-[#1677ff] px-5 py-1.5 text-white hover:bg-[#4096ff] disabled:opacity-60" :disabled="saving || loading" data-testid="cs-article-save-bottom" @click="save">
          {{ saving ? '保存中…' : '保存' }}
        </button>
      </div>
    </div>

    <ImagePicker v-model:open="coverPickerOpen" module="cms" @select="onCoverPicked" />
  </div>
</template>
