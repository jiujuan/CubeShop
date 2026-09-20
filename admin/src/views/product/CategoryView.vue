<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronDown, ChevronRight, Pencil, Plus, Trash2 } from 'lucide-vue-next'
import {
  createCategory, deleteCategory, getCategories, updateCategory,
  type CategoryNode,
} from '@/api/product'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

/**
 * 分类管理（原型：左侧菜单「分类管理」，两级分类表格）
 */
interface FlatRow extends CategoryNode {
  depth: 0 | 1
  rootId: number
  hasChildren: boolean
}

const loading = ref(false)
const tree = ref<CategoryNode[]>([])
const expanded = ref<Set<number>>(new Set())

const rows = computed<FlatRow[]>(() => {
  const list: FlatRow[] = []
  for (const root of tree.value) {
    const children = root.children ?? []
    list.push({ ...root, depth: 0, rootId: root.id, hasChildren: children.length > 0, children: [] })
    if (expanded.value.has(root.id)) {
      for (const c of children) {
        list.push({ ...c, depth: 1, rootId: root.id, hasChildren: false, children: [] })
      }
    }
  }
  return list
})

async function load() {
  loading.value = true
  try {
    const { data } = await getCategories()
    tree.value = data.data
    // 默认展开全部
    expanded.value = new Set(tree.value.map((n) => n.id))
  } finally {
    loading.value = false
  }
}

onMounted(load)

function toggle(id: number) {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

// ---------- 弹窗 ----------
const dialogOpen = ref(false)
const editing = ref<CategoryNode | null>(null)
const form = ref({ parent_id: 0, name: '', sort: 0, status: 1 })

function openCreate(parent?: CategoryNode) {
  editing.value = null
  form.value = { parent_id: parent?.id ?? 0, name: '', sort: 0, status: 1 }
  dialogOpen.value = true
}

function openEdit(row: CategoryNode) {
  editing.value = row
  form.value = { parent_id: row.parent_id, name: row.name, sort: row.sort, status: row.status }
  dialogOpen.value = true
}

/** 内联提示条：请求层的 toast handler 未注册，失败必须在这里显式呈现，否则点了没反应 */
const tip = ref('')
const tipOk = ref(false)
function notify(ok: boolean, text: string) {
  tipOk.value = ok
  tip.value = text
}

async function submit() {
  if (!form.value.name.trim()) return
  try {
    if (editing.value) {
      await updateCategory(editing.value.id, form.value)
    } else {
      await createCategory(form.value)
    }
    dialogOpen.value = false
    notify(true, '已保存')
    await load()
  } catch (e) {
    notify(false, e instanceof Error ? e.message : '保存失败')
  }
}

async function remove(row: CategoryNode) {
  askConfirm('删除分类', `确定删除分类「${row.name}」？删除后不可恢复。`, async () => {
    try {
      await deleteCategory(row.id)
      notify(true, '已删除')
      await load()
    } catch (e) {
      notify(false, e instanceof Error ? e.message : '删除失败')
    }
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
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">分类管理</h2>
      <Button class="bg-[#1677ff] hover:bg-[#4096ff]" @click="openCreate()">
        <Plus class="mr-1 h-4 w-4" /> 新建一级分类
      </Button>
    </div>

    <p
      v-if="tip"
      class="mb-3 rounded-md px-3 py-2 text-xs"
      :class="tipOk ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500'"
      data-testid="category-tip"
    >{{ tip }}</p>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-10 px-3 py-2"></th>
          <th class="px-3 py-2">分类名称</th>
          <th class="w-24 px-3 py-2">排序</th>
          <th class="w-24 px-3 py-2">状态</th>
          <th class="w-48 px-3 py-2">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-2.5">
            <button v-if="row.depth === 0 && row.hasChildren" class="text-slate-400" @click="toggle(row.id)">
              <ChevronDown v-if="expanded.has(row.id)" class="h-4 w-4" />
              <ChevronRight v-else class="h-4 w-4" />
            </button>
          </td>
          <td class="px-3 py-2.5 text-black" :class="row.depth === 1 ? 'pl-10' : 'font-medium'">
            {{ row.name }}
          </td>
          <td class="px-3 py-2.5 text-black">{{ row.sort }}</td>
          <td class="px-3 py-2.5">
            <span
              class="rounded px-2 py-0.5 text-xs"
              :class="row.status === 1 ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-slate-100 text-slate-400'"
            >{{ row.status === 1 ? '启用' : '禁用' }}</span>
          </td>
          <td class="px-3 py-2.5">
            <div class="flex items-center gap-3 text-[#1677ff]">
              <button v-if="row.depth === 0" class="inline-flex items-center gap-0.5 hover:underline" @click="openCreate(row)">
                <Plus class="h-3.5 w-3.5" /> 子分类
              </button>
              <button class="inline-flex items-center gap-0.5 hover:underline" @click="openEdit(row)">
                <Pencil class="h-3.5 w-3.5" /> 编辑
              </button>
              <button
                class="inline-flex items-center gap-0.5 text-red-500 hover:underline"
                data-testid="category-delete"
                @click="remove(row)"
              >
                <Trash2 class="h-3.5 w-3.5" /> 删除
              </button>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="5"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!rows.length && !loading">
          <td colspan="5" class="px-3 py-10 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 新建/编辑弹窗 -->
    <Teleport to="body">
      <div v-if="dialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="dialogOpen = false">
        <div class="w-96 rounded-xl bg-white p-6 shadow-xl">
          <h3 class="mb-4 text-base font-semibold text-slate-800">{{ editing ? '编辑分类' : (form.parent_id ? '新建子分类' : '新建一级分类') }}</h3>

          <div class="space-y-3 text-[13px]">
            <div>
              <label class="mb-1 block text-slate-600">分类名称 <span class="text-red-500">*</span></label>
              <input
                v-model="form.name" type="text" maxlength="20" placeholder="请输入分类名称"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
              />
            </div>
            <div>
              <label class="mb-1 block text-slate-600">排序（越大越靠前）</label>
              <input
                v-model.number="form.sort" type="number"
                class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]"
              />
            </div>
            <div>
              <label class="mb-1 block text-slate-600">状态</label>
              <div class="flex gap-4">
                <label class="flex items-center gap-1"><input v-model.number="form.status" type="radio" :value="1" /> 启用</label>
                <label class="flex items-center gap-1"><input v-model.number="form.status" type="radio" :value="0" /> 禁用</label>
              </div>
            </div>
          </div>

          <div class="mt-5 flex justify-end gap-2">
            <Button variant="outline" @click="dialogOpen = false">取消</Button>
            <Button class="bg-[#1677ff] hover:bg-[#4096ff]" @click="submit">保存</Button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

<!-- 删除确认弹层 -->
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
