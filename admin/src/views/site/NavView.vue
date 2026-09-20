<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Link2, Pencil, Plus, Tags, Trash2 } from 'lucide-vue-next'
import {
  createNavItem, deleteNavItem, getNavItems, updateNavItem,
  type NavItemPayload, type NavItemRow, type NavItemType,
} from '@/api/nav'
import { getCategories, type CategoryNode } from '@/api/product'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

/**
 * 前台顶部导航管理
 *
 * 后台存的是**编排**：每条要么是「商品分类引用」（存 category_id，标题/链接由分类派生，
 * 改名自动跟随），要么是「自定义链接」（可指站内任意路径或站外地址）。位置由 sort 决定
 * （越大越靠前，与分类管理体例一致），所以「新闻中心」可以插在两个分类中间。
 *
 * ⚠️ 与分类是引用而非拷贝：这里**不编辑分类的展示名**，要改名去分类管理。
 * ⚠️ 分类被删除或禁用时，条目保留（标「已失效」），公开接口自动跳过 ——
 *    分类恢复后位置还在，运营不用重排。
 */
const loading = ref(false)
const items = ref<NavItemRow[]>([])
const roots = ref<CategoryNode[]>([])

/** 已被登记的分类 id（后端有唯一约束，这里做前端置灰避免点了才报错） */
const usedCategoryIds = computed(() => new Set(
  items.value.map((i) => i.category_id).filter((id): id is number => id !== null),
))

async function load() {
  loading.value = true
  try {
    const [navRes, catRes] = await Promise.all([getNavItems(), getCategories()])
    items.value = navRes.data.data
    roots.value = catRes.data.data
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ---------- 弹窗 ----------

const dialogOpen = ref(false)
const editing = ref<NavItemRow | null>(null)
const form = ref<{
  type: NavItemType
  category_id: number | null
  title: string
  url: string
  target: '_self' | '_blank'
  sort: number
  is_active: boolean
}>(blankForm())

function blankForm() {
  return { type: 'category' as NavItemType, category_id: null, title: '', url: '', target: '_self' as const, sort: 0, is_active: true }
}

function openCreate() {
  editing.value = null
  form.value = blankForm()
  dialogOpen.value = true
}

function openEdit(row: NavItemRow) {
  editing.value = row
  form.value = {
    type: row.type,
    category_id: row.category_id,
    title: row.title ?? '',
    url: row.url ?? '',
    target: row.target,
    sort: row.sort,
    is_active: row.is_active,
  }
  dialogOpen.value = true
}

const isCategory = computed(() => form.value.type === 'category')

/** 切换类型时清掉另一类字段的残留值，避免提交体里混着无关字段 */
function switchType(type: NavItemType) {
  if (form.value.type === type) return
  form.value.type = type
  if (type === 'category') {
    form.value.title = ''
    form.value.url = ''
    form.value.target = '_self'
  } else {
    form.value.category_id = null
  }
}

function canSubmit(): boolean {
  return isCategory.value ? form.value.category_id !== null : form.value.title.trim() !== '' && form.value.url.trim() !== ''
}

function payload(): NavItemPayload {
  return isCategory.value
    ? { type: 'category', category_id: form.value.category_id, sort: form.value.sort, is_active: form.value.is_active }
    : {
      type: 'custom',
      title: form.value.title.trim(),
      url: form.value.url.trim(),
      target: form.value.target,
      sort: form.value.sort,
      is_active: form.value.is_active,
    }
}

async function submit() {
  if (!canSubmit()) return
  if (editing.value) {
    await updateNavItem(editing.value.id, payload())
  } else {
    await createNavItem(payload())
  }
  dialogOpen.value = false
  await load()
}

async function toggleActive(row: NavItemRow) {
  await updateNavItem(row.id, { is_active: !row.is_active })
  await load()
}

async function remove(row: NavItemRow) {
  askConfirm('删除导航条目', `确定从导航中移除「${row.title ?? row.category_name ?? '该条目'}」？只移除导航编排，不会删除分类本身。`, async () => {
    await deleteNavItem(row.id)
    await load()
  })
}

/** 危险操作二次确认 */
const confirmState = ref<{ title: string; message: string; run: () => Promise<void> } | null>(null)

function askConfirm(title: string, message: string, run: () => Promise<void>) {
  confirmState.value = { title, message, run }
}

async function onConfirm() {
  const c = confirmState.value
  confirmState.value = null
  await c?.run()
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-1 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">导航管理</h2>
      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" data-testid="nav-create" @click="openCreate">
        <Plus class="mr-1 h-4 w-4" /> 新增导航项
      </Button>
    </div>
    <p class="mb-4 text-[12px] text-slate-500">
      编排前台顶部导航（首页之后的横排项）。排序越大越靠前；「商品分类」类的名称与链接由分类派生，改名会自动跟随。
    </p>

    <table class="w-full text-[13px]" data-testid="nav-table">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-28 px-3 py-2">类型</th>
          <th class="px-3 py-2">名称</th>
          <th class="px-3 py-2">链接</th>
          <th class="w-20 px-3 py-2">排序</th>
          <th class="w-20 px-3 py-2">状态</th>
          <th class="w-40 px-3 py-2">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in items" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`nav-row-${row.id}`">
          <td class="px-3 py-2.5">
            <span
              class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs"
              :class="row.type === 'category' ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-[#e6f4ff] text-[#1677ff]'"
            >
              <Tags v-if="row.type === 'category'" class="h-3 w-3" />
              <Link2 v-else class="h-3 w-3" />
              {{ row.type_label }}
            </span>
          </td>
          <td class="px-3 py-2.5 font-medium text-black" :data-testid="`nav-title-${row.id}`">
            {{ row.type === 'category' ? (row.category_name ?? '（分类已删除）') : row.title }}
            <span v-if="row.category_missing" class="ml-1 rounded bg-[#fff1f0] px-1.5 py-0.5 text-[10px] text-[#ff4d4f]">已失效</span>
          </td>
          <td class="px-3 py-2.5 text-slate-500" :data-testid="`nav-url-${row.id}`">
            {{ row.type === 'category' ? `/category/${row.category_id}` : row.url }}
          </td>
          <td class="px-3 py-2.5 text-black">{{ row.sort }}</td>
          <td class="px-3 py-2.5">
            <button
              class="rounded px-2 py-0.5 text-xs"
              :class="row.is_active ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-slate-100 text-slate-400'"
              :data-testid="`nav-toggle-${row.id}`"
              @click="toggleActive(row)"
            >{{ row.is_active ? '启用' : '停用' }}</button>
          </td>
          <td class="px-3 py-2.5">
            <div class="flex items-center gap-3 text-[#1677ff]">
              <button class="inline-flex items-center gap-0.5 hover:underline" data-testid="nav-edit" @click="openEdit(row)">
                <Pencil class="h-3.5 w-3.5" /> 编辑
              </button>
              <button class="inline-flex items-center gap-0.5 text-red-500 hover:underline" data-testid="nav-delete" @click="remove(row)">
                <Trash2 class="h-3.5 w-3.5" /> 删除
              </button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="6"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!items.length && !loading">
          <td colspan="6" class="px-3 py-10 text-center text-slate-400" data-testid="nav-empty">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 新增/编辑弹窗 -->
    <Teleport to="body">
      <div v-if="dialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" data-testid="nav-dialog" @click.self="dialogOpen = false">
        <div class="w-96 rounded-xl bg-white p-6 shadow-xl">
          <h3 class="mb-4 text-base font-semibold text-slate-800">{{ editing ? '编辑导航项' : '新增导航项' }}</h3>

          <div class="space-y-3 text-[13px]">
            <div>
              <label class="mb-1 block text-slate-600">类型</label>
              <div class="flex gap-4">
                <label class="flex items-center gap-1">
                  <input v-model="form.type" type="radio" value="category" data-testid="nav-type-category" @change="switchType('category')" /> 商品分类
                </label>
                <label class="flex items-center gap-1">
                  <input v-model="form.type" type="radio" value="custom" data-testid="nav-type-custom" @change="switchType('custom')" /> 自定义链接
                </label>
              </div>
            </div>

            <div v-if="isCategory">
              <label class="mb-1 block text-slate-600">选择分类（一级） <span class="text-red-500">*</span></label>
              <select
                v-model.number="form.category_id"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                data-testid="nav-category-select"
              >
                <option :value="null">请选择</option>
                <option
                  v-for="c in roots" :key="c.id" :value="c.id"
                  :disabled="usedCategoryIds.has(c.id)"
                  :data-testid="`nav-category-option-${c.id}`"
                >
                  {{ c.name }}{{ usedCategoryIds.has(c.id) ? '（已在导航中）' : '' }}{{ c.status === 0 ? '（已禁用）' : '' }}
                </option>
              </select>
              <p class="mt-1 text-[12px] text-slate-400">名称与链接取自分类本身，改名后导航自动跟随。</p>
            </div>

            <template v-else>
              <div>
                <label class="mb-1 block text-slate-600">显示名称 <span class="text-red-500">*</span></label>
                <input
                  v-model="form.title" type="text" maxlength="64" placeholder="如：新闻中心"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                  data-testid="nav-title-input"
                />
              </div>
              <div>
                <label class="mb-1 block text-slate-600">链接地址 <span class="text-red-500">*</span></label>
                <input
                  v-model="form.url" type="text" maxlength="255" placeholder="站内 /news 或站外 https://…"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                  data-testid="nav-url-input"
                />
                <p class="mt-1 text-[12px] text-slate-400">以 http 开头的地址会在新窗口打开。</p>
              </div>
              <div>
                <label class="mb-1 block text-slate-600">打开方式</label>
                <select
                  v-model="form.target"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                  data-testid="nav-target-select"
                >
                  <option value="_self">当前窗口</option>
                  <option value="_blank">新窗口</option>
                </select>
              </div>
            </template>

            <div>
              <label class="mb-1 block text-slate-600">排序（越大越靠前）</label>
              <input
                v-model.number="form.sort" type="number"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
                data-testid="nav-sort-input"
              />
            </div>
            <div>
              <label class="mb-1 block text-slate-600">状态</label>
              <div class="flex gap-4">
                <label class="flex items-center gap-1"><input v-model="form.is_active" type="radio" :value="true" /> 启用</label>
                <label class="flex items-center gap-1"><input v-model="form.is_active" type="radio" :value="false" /> 停用</label>
              </div>
            </div>
          </div>

          <div class="mt-5 flex justify-end gap-2">
            <Button variant="outline" @click="dialogOpen = false">取消</Button>
            <Button class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="!canSubmit()" data-testid="nav-submit" @click="submit">保存</Button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

  <ConfirmDialog
    :open="!!confirmState"
    :title="confirmState?.title"
    :message="confirmState?.message"
    confirm-text="确定删除"
    danger
    @confirm="onConfirm"
    @cancel="confirmState = null"
  />
</template>
