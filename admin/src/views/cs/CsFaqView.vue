<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronLeft, ChevronRight, Eye, Plus, X } from 'lucide-vue-next'
import { MdEditor } from 'md-editor-v3'
import 'md-editor-v3/lib/style.css'
import {
  createCsFaqArticle, createCsFaqCategory, deleteCsFaqArticle, deleteCsFaqCategory,
  getCsFaqArticles, getCsFaqCategories, offlineCsFaqArticle, previewCsFaqArticle,
  publishCsFaqArticle, sortCsFaqCategories, updateCsFaqArticle, updateCsFaqCategory,
  type CsFaqArticlePayload, type CsFaqArticleRow, type CsFaqCategoryRow,
} from '@/api/cs'
import { uploadImage } from '@/api/product'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * FAQ 分类与文章管理（CS-115）
 *
 * 分类：增删改、排序、启停（有已发布文章的分类删除被后端拒绝，前端展示原因）。
 * 文章：筛选 + 增删改 + 发布/下架/预览 + 统计列。
 * 正文用 md-editor-v3 编辑 **Markdown**（源存入 content_md），HTML 产物由后端渲染派生。
 * 无 cs.faq.manage 时页面只读，不渲染任何写操作入口。
 */
const auth = useAuthStore()
const canManage = computed(() => auth.hasPermission('cs.faq.manage'))

const STATUS_LABELS: Record<string, string> = { draft: '草稿', published: '已发布', offline: '已下架' }

const tab = ref<'categories' | 'articles'>('articles')

// 与营销管理页同款分段控件（Tab 的位置与样式保持一致）
const tabs: Array<{ key: 'articles' | 'categories'; label: string }> = [
  { key: 'articles', label: '文章管理' },
  { key: 'categories', label: '分类管理' },
]
const tip = ref('')
const tipType = ref<'ok' | 'err'>('ok')

function notify(type: 'ok' | 'err', text: string) {
  tipType.value = type
  tip.value = text
}

// ---------------- 分类 ----------------
const categories = ref<CsFaqCategoryRow[]>([])
const catLoading = ref(false)
const catEditor = ref<{ open: boolean; id: number | null; name: string; sort: number; is_active: boolean }>(
  { open: false, id: null, name: '', sort: 0, is_active: true },
)
const deleteCatTarget = ref<CsFaqCategoryRow | null>(null)
const sortSaving = ref(false)

async function loadCategories() {
  catLoading.value = true
  try {
    categories.value = (await getCsFaqCategories()).data.data
  } finally {
    catLoading.value = false
  }
}

function openCatCreate() {
  catEditor.value = { open: true, id: null, name: '', sort: 0, is_active: true }
}
function openCatEdit(c: CsFaqCategoryRow) {
  catEditor.value = { open: true, id: c.id, name: c.name, sort: c.sort, is_active: c.is_active }
}
async function saveCategory() {
  if (!catEditor.value.name.trim()) { notify('err', '请填写分类名称'); return }
  try {
    const payload = { name: catEditor.value.name.trim(), sort: catEditor.value.sort, is_active: catEditor.value.is_active }
    if (catEditor.value.id === null) await createCsFaqCategory(payload)
    else await updateCsFaqCategory(catEditor.value.id, payload)
    catEditor.value.open = false
    notify('ok', '已保存')
    await loadCategories()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '保存失败')
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
    await sortCsFaqCategories(categories.value.map((c) => ({ id: c.id, sort: c.sort })))
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
    notify('ok', '已删除')
    await loadCategories()
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '删除失败')
  }
}

// ---------------- 文章 ----------------
const articles = ref<CsFaqArticleRow[]>([])
const artLoading = ref(false)
const artPagination = ref({ page: 1, page_size: 15, total: 0, total_pages: 1 })
const artFilters = ref<{ category_id: number | null; status: string; keyword: string }>({ category_id: null, status: '', keyword: '' })

function emptyForm(): CsFaqArticlePayload {
  return { category_id: 0, title: '', summary: '', content_md: '', sort: 0, is_hot: false, status: 'draft' }
}
const articleEditor = ref<{ open: boolean; id: number | null; form: CsFaqArticlePayload }>(
  { open: false, id: null, form: emptyForm() },
)
const deleteArtTarget = ref<CsFaqArticleRow | null>(null)
const publishTarget = ref<{ article: CsFaqArticleRow; action: 'publish' | 'offline' } | null>(null)
const previewData = ref<{ title: string; content: string; category_name: string | null; status: string } | null>(null)

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

function openArtCreate() {
  const form = emptyForm()
  form.category_id = categories.value[0]?.id ?? 0
  articleEditor.value = { open: true, id: null, form }
}
function openArtEdit(a: CsFaqArticleRow) {
  articleEditor.value = {
    open: true, id: a.id,
    // 存量未迁移的行没有 markdown 源：退回 HTML 产物兜底（作者可另存为 markdown 或直接重写）
    form: {
      category_id: a.category_id, title: a.title, summary: a.summary ?? '',
      content_md: a.content_md ?? a.content ?? '', sort: a.sort, is_hot: a.is_hot, status: a.status,
    },
  }
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

// ---------------- 正文编辑（md-editor-v3） ----------------
//
// 正文的源是 Markdown（存 content_md），HTML 产物由后端 MarkdownRenderer 渲染 +
// HtmlSanitizer 白名单净化后写入 content —— 前端不做净化，也不生成 HTML。
// 注意：编辑器内置预览用的是它自带的 markdown-it，与后端渲染口径可能有细微差异，
// 以「预览」按钮（走后端、与用户端同一份内容）为准。

/**
 * md-editor-v3 的图片上传钩子：复用后台统一上传接口 `POST /api/admin/upload`，
 * 拿到 url 后回填让编辑器插入 markdown 图片语法。
 */
async function onUploadImg(
  files: File[],
  callback: (urls: Array<{ url: string; alt: string; title: string }>) => void,
) {
  try {
    const results = await Promise.all(files.map((f) => uploadImage(f)))

    callback(results.map(({ data }, i) => ({
      url: data.data.url,
      alt: files[i]?.name ?? '图片',
      title: files[i]?.name ?? '',
    })))
  } catch (e) {
    callback([])
    notify('err', e instanceof Error ? e.message : '图片上传失败')
  }
}

function fmtRate(row: CsFaqArticleRow): string {
  if (row.helpful_rate === null || row.helpful_count + row.unhelpful_count === 0) return '—'
  return `${Math.round(row.helpful_rate * 100)}%`
}

function statusClass(status: string): string {
  return { draft: 'bg-slate-100 text-slate-500', published: 'bg-green-50 text-green-600', offline: 'bg-amber-50 text-amber-600' }[status] ?? 'bg-slate-100 text-slate-500'
}

onMounted(async () => {
  await loadCategories()
  await loadArticles(1)
})
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm" data-testid="cs-faq-view">
    <!-- 标题 + Tab（Tab 位置与样式与营销管理页一致） -->
    <div class="mb-4 flex items-center gap-3">
      <h2 class="text-lg font-semibold text-slate-800">帮助中心管理</h2>
      <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm" data-testid="cs-faq-tabs">
        <button
          v-for="t in tabs" :key="t.key"
          class="rounded-md px-4 py-1.5 transition-colors"
          :class="tab === t.key ? 'bg-white font-medium text-[#1677ff] shadow-sm' : 'text-slate-500 hover:text-slate-700'"
          :data-testid="`cs-faq-tab-${t.key}`"
          @click="tab = t.key"
        >{{ t.label }}</button>
      </div>
    </div>

    <p v-if="tip" class="mb-3 rounded-md px-3 py-2 text-xs" :class="tipType === 'ok' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500'" data-testid="cs-faq-tip">{{ tip }}</p>

    <!-- 分类管理 -->
    <section v-if="tab === 'categories'" data-testid="cs-faq-categories">
      <div class="mb-3 flex items-center justify-between">
        <p class="text-xs text-slate-400">可直接修改排序值，改完点「保存排序」提交</p>
        <div class="flex gap-2">
          <button v-if="canManage" class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" :disabled="sortSaving" data-testid="cs-cat-save-sort" @click="saveSort">保存排序</button>
          <button v-if="canManage" class="flex items-center gap-1 rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff]" data-testid="cs-cat-create" @click="openCatCreate"><Plus class="h-3.5 w-3.5" /> 新增分类</button>
        </div>
      </div>

      <table class="w-full text-[13px]">
        <thead>
          <tr class="border-b border-slate-200 text-left text-slate-500">
            <th class="px-3 py-1.5">排序</th>
            <th class="px-3 py-1.5">名称</th>
            <th class="px-3 py-1.5">文章数</th>
            <th class="px-3 py-1.5">已发布</th>
            <th class="px-3 py-1.5">状态</th>
            <th class="px-3 py-1.5">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in categories" :key="c.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`cs-faq-category-row-${c.id}`">
            <td class="px-3 py-1.5">
              <input v-model.number="c.sort" type="number" min="0" max="9999" :disabled="!canManage" class="w-16 rounded border border-slate-300 px-2 py-1 disabled:bg-slate-50" :data-testid="`cs-cat-sort-${c.id}`" />
            </td>
            <td class="px-3 py-1.5 text-black">{{ c.name }}</td>
            <td class="px-3 py-1.5 text-black">{{ c.articles_count }}</td>
            <td class="px-3 py-1.5 text-black">{{ c.published_count }}</td>
            <td class="px-3 py-1.5">
              <button class="rounded px-2 py-0.5 text-xs" :class="c.is_active ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-400'" :disabled="!canManage" :data-testid="`cs-cat-toggle-${c.id}`" @click="toggleCategory(c)">
                {{ c.is_active ? '启用' : '停用' }}
              </button>
            </td>
            <td class="px-3 py-1.5">
              <template v-if="canManage">
                <button class="mr-3 text-[#1677ff] hover:underline" :data-testid="`cs-cat-edit-${c.id}`" @click="openCatEdit(c)">编辑</button>
                <button class="text-[#ff4d4f] hover:underline" :data-testid="`cs-cat-delete-${c.id}`" @click="deleteCatTarget = c">删除</button>
              </template>
              <span v-else class="text-slate-300">只读</span>
            </td>
          </tr>
          <tr v-if="catLoading">
            <td colspan="6"><LoadingSpinner /></td>
          </tr>
          <tr v-if="!categories.length && !catLoading">
            <td colspan="6" class="px-3 py-12 text-center text-slate-400" data-testid="cs-cat-empty">暂无分类</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- 文章管理 -->
    <section v-else data-testid="cs-faq-articles">
      <div class="mb-3 flex flex-wrap items-center gap-2 text-[13px]">
        <select v-model.number="artFilters.category_id" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" data-testid="cs-article-category-filter" @change="loadArticles(1)">
          <option :value="null">全部分类</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
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
            <th class="px-3 py-1.5">分类</th>
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
              <span v-if="a.is_hot" class="ml-1 rounded bg-[#fff1f0] px-1.5 py-0.5 text-[10px] text-[#ff4d4f]">热门</span>
            </td>
            <td class="px-3 py-1.5 text-black">{{ a.category?.name ?? '—' }}</td>
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
            <td colspan="6"><LoadingSpinner /></td>
          </tr>
          <tr v-if="!articles.length && !artLoading">
            <td colspan="6" class="px-3 py-12 text-center text-slate-400" data-testid="cs-article-empty">暂无文章</td>
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
    </section>

    <!-- 分类编辑弹窗 -->
    <div v-if="catEditor.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" data-testid="cs-category-editor" @click.self="catEditor.open = false">
      <div class="w-96 rounded-xl bg-white p-6">
        <h3 class="mb-3 text-sm font-semibold text-slate-800">{{ catEditor.id === null ? '新增分类' : '编辑分类' }}</h3>
        <label class="mb-2 block text-[13px]">
          <span class="mb-1 block text-slate-500">名称</span>
          <input v-model="catEditor.name" type="text" maxlength="64" class="w-full rounded-md border border-slate-300 px-3 py-2" data-testid="cs-category-name" />
        </label>
        <label class="mb-2 block text-[13px]">
          <span class="mb-1 block text-slate-500">排序</span>
          <input v-model.number="catEditor.sort" type="number" min="0" class="w-full rounded-md border border-slate-300 px-3 py-2" data-testid="cs-category-sort" />
        </label>
        <label class="mb-3 flex items-center gap-2 text-[13px] text-slate-600">
          <input v-model="catEditor.is_active" type="checkbox" data-testid="cs-category-active" /> 启用
        </label>
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
          <select v-model.number="articleEditor.form.category_id" class="w-full rounded-md border border-slate-300 px-3 py-2" data-testid="cs-article-form-category">
            <option :value="0" disabled>请选择分类</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <label class="mb-3 block text-[13px]">
          <span class="mb-1 block text-slate-500">标题</span>
          <input v-model="articleEditor.form.title" type="text" maxlength="191" class="w-full rounded-md border border-slate-300 px-3 py-2" data-testid="cs-article-form-title" />
        </label>
        <label class="mb-3 block text-[13px]">
          <span class="mb-1 block text-slate-500">摘要</span>
          <input v-model="articleEditor.form.summary" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-2" data-testid="cs-article-form-summary" />
        </label>
        <div class="mb-3 text-[13px]">
          <span class="mb-1 block text-slate-500">正文（Markdown）</span>
          <MdEditor
            v-model="articleEditor.form.content_md"
            language="zh-CN"
            :toolbars-exclude="['github', 'save']"
            :style="{ height: '420px' }"
            :on-upload-img="onUploadImg"
            data-testid="cs-article-form-content"
          />
          <span class="mt-1 block text-xs text-slate-400">
            支持标题、段落、列表、表格、图片与链接。工具栏的图片按钮会走后台统一上传接口；
            链接锚点可用标题生成的 id（如 <code>#content-小节标题</code>）。
            保存时后端会渲染并按白名单净化（脚本、内联样式等会被剥离）；
            以「预览」按钮看到的效果为准（与用户端同一份内容）。
          </span>
        </div>
        <div class="mb-4 flex items-center gap-4 text-[13px] text-slate-600">
          <label class="flex items-center gap-2"><input v-model="articleEditor.form.is_hot" type="checkbox" data-testid="cs-article-form-hot" /> 热门</label>
          <label class="flex items-center gap-2">排序 <input v-model.number="articleEditor.form.sort" type="number" min="0" class="w-20 rounded border border-slate-300 px-2 py-1" data-testid="cs-article-form-sort" /></label>
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

    <ConfirmDialog :open="!!deleteCatTarget" title="删除分类" message="确认删除该分类？" confirm-text="删除" danger @confirm="doDeleteCategory" @cancel="deleteCatTarget = null" />
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
