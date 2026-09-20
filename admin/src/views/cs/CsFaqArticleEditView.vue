<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, ChevronRight, Save, X } from 'lucide-vue-next'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import {
  createCsFaqArticle, getCsFaqArticle, getCsFaqCategories, updateCsFaqArticle, uploadCmsImage,
  type CsFaqArticlePayload, type CsFaqCategoryRow,
} from '@/api/cs'
import { getProducts } from '@/api/product'
import { ARTICLE_STATUS_OPTIONS, hotLabelFor } from '@/utils/csArticle'

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

const selectedProducts = ref<Array<{ id: number; title: string }>>([])
const productPicker = ref<{ keyword: string; loading: boolean; results: Array<{ id: number; title: string }> }>(
  { keyword: '', loading: false, results: [] },
)

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

function toggleProduct(p: { id: number; title: string }) {
  const ids = form.value.product_ids ?? []
  const idx = ids.indexOf(p.id)
  if (idx >= 0) {
    ids.splice(idx, 1)
    selectedProducts.value = selectedProducts.value.filter((s) => s.id !== p.id)
  } else {
    ids.push(p.id)
    if (!selectedProducts.value.some((s) => s.id === p.id)) selectedProducts.value.push({ id: p.id, title: p.title })
  }
  form.value.product_ids = [...ids]
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
      selectedProducts.value = (a.products ?? []).map((p) => ({ id: p.id, title: p.title }))
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
            <span class="text-xs text-slate-400">图文卡片新闻建议填；列表行新闻可不填</span>
          </div>
        </div>
      </div>

      <!-- 正文：独占整行 -->
      <div class="mt-4">
        <span class="mb-1 block text-slate-500">正文（Markdown） <span class="text-red-500">*</span></span>
        <MarkdownEditor
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

        <!-- 关联种草商品（前台详情页展示，商品详情页反查相关资讯） -->
        <div class="rounded-md border border-slate-200 px-3 py-3">
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
              <span class="shrink-0 text-[#1677ff]">{{ (form.product_ids ?? []).includes(p.id) ? '已选' : '添加' }}</span>
            </button>
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
  </div>
</template>
