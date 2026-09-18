<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Check, Pencil, Plus, Trash2, X } from 'lucide-vue-next'
import {
  batchSaveAttributeValues, createAttribute, deleteAttribute,
  getAttributes, updateAttribute,
  type AttributeRow,
} from '@/api/attribute'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

/**
 * 属性库（V1.1 E01 / T-011）
 * 左：属性列表（类型/可筛筛选）；右：属性值管理（批量增删）
 */
const keyword = ref('')
const typeFilter = ref<'spec' | 'param' | ''>('')
const list = ref<AttributeRow[]>([])
const loading = ref(false)
const active = ref<AttributeRow | null>(null)

/** 右侧值编辑区：当前编辑中的值文本列表 */
const valueDraft = ref<string[]>([])
const newValue = ref('')
const savingValues = ref(false)
const message = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await getAttributes({
      keyword: keyword.value || undefined,
      type: typeFilter.value || undefined,
      page_size: 200,
    })
    list.value = data.data.list
    if (active.value) {
      active.value = list.value.find((a) => a.id === active.value!.id) ?? null
    }
    if (!active.value && list.value.length) select(list.value[0])
  } finally {
    loading.value = false
  }
}

onMounted(load)

function select(row: AttributeRow) {
  active.value = row
  valueDraft.value = row.values.map((v) => v.value)
  message.value = ''
}

// ---------- 属性 CRUD ----------
const dialogOpen = ref(false)
const editingId = ref<number | null>(null)
const form = ref<{ name: string; type: 'spec' | 'param'; is_filterable: boolean; is_multiple: boolean; allow_custom: boolean; sort: number }>({
  name: '', type: 'spec', is_filterable: false, is_multiple: false, allow_custom: false, sort: 0,
})
const formError = ref('')
const saving = ref(false)

function openCreate() {
  editingId.value = null
  form.value = { name: '', type: 'spec', is_filterable: false, is_multiple: false, allow_custom: false, sort: 0 }
  formError.value = ''
  dialogOpen.value = true
}

function openEdit(row: AttributeRow) {
  editingId.value = row.id
  form.value = {
    name: row.name, type: row.type, is_filterable: row.is_filterable,
    is_multiple: row.is_multiple, allow_custom: row.allow_custom, sort: row.sort,
  }
  formError.value = ''
  dialogOpen.value = true
}

async function submit() {
  formError.value = ''
  if (!form.value.name.trim()) {
    formError.value = '请输入属性名称'
    return
  }
  saving.value = true
  try {
    if (editingId.value) await updateAttribute(editingId.value, form.value)
    else await createAttribute(form.value)
    dialogOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

const confirmState = ref<{ title: string; message: string; run: () => Promise<void> } | null>(null)
const actionError = ref('')

function askDelete(row: AttributeRow) {
  actionError.value = ''
  confirmState.value = {
    title: '删除属性',
    message: `确定删除属性「${row.name}」？若已被分类模板 / 商品 / SKU 引用将无法删除。`,
    run: async () => {
      try {
        await deleteAttribute(row.id)
        if (active.value?.id === row.id) active.value = null
        await load()
      } catch (e) {
        actionError.value = e instanceof Error ? e.message : '删除失败'
      }
    },
  }
}

async function onConfirm() {
  const c = confirmState.value
  confirmState.value = null
  await c?.run()
}

// ---------- 属性值编辑 ----------
function addDraft() {
  const v = newValue.value.trim()
  if (!v || valueDraft.value.includes(v)) {
    newValue.value = ''
    return
  }
  valueDraft.value.push(v)
  newValue.value = ''
}

function removeDraft(i: number) {
  valueDraft.value.splice(i, 1)
}

async function saveValues() {
  if (!active.value) return
  savingValues.value = true
  message.value = ''
  try {
    const { data } = await batchSaveAttributeValues(active.value.id, valueDraft.value)
    const r = data.data
    message.value = `保存成功：新增 ${r.created}，删除 ${r.removed}${r.retained.length ? `，${r.retained.length} 个因被引用而保留` : ''}`
    await load()
  } catch (e) {
    message.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    savingValues.value = false
  }
}
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">属性库</h2>
      <span class="text-xs text-slate-400">左：属性列表　右：属性值管理</span>
    </div>

    <p v-if="actionError" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-500">{{ actionError }}</p>

    <div class="flex gap-4">
      <!-- 左：属性列表 -->
      <div class="w-64 shrink-0 rounded-md border border-slate-100">
        <div
          v-for="row in list" :key="row.id"
          class="flex cursor-pointer items-center justify-between border-b border-slate-50 px-3 py-2 text-[13px] last:border-0"
          :class="active?.id === row.id ? 'bg-[#e6f4ff]' : 'hover:bg-slate-50'"
          @click="select(row)"
        >
          <div class="min-w-0">
            <div class="truncate font-medium text-slate-700">{{ row.name }}</div>
            <div class="flex items-center gap-1 text-xs text-slate-400">
              <span class="rounded bg-slate-100 px-1">{{ row.type_label }}</span>
              <span v-if="row.is_filterable" class="rounded bg-[#e6f4ff] px-1 text-[#1677ff]">可筛</span>
            </div>
          </div>
          <div class="flex items-center gap-1">
            <button v-permission="'product.update'" class="text-slate-400 hover:text-[#1677ff]" @click.stop="openEdit(row)"><Pencil class="h-3.5 w-3.5" /></button>
            <button v-permission="'product.update'" class="text-slate-400 hover:text-red-500" @click.stop="askDelete(row)"><Trash2 class="h-3.5 w-3.5" /></button>
          </div>
        </div>
        <div v-if="!list.length && !loading" class="px-3 py-10 text-center text-[13px] text-slate-400">暂无属性</div>
      </div>

      <!-- 右：属性值管理（顶部吸附：搜索 / 新建 / 添加 / 保存 / 值展示 全部在右侧面板内、吸顶） -->
      <div class="min-w-0 flex-1">
        <div data-testid="attr-toolbar" class="sticky top-0 z-20 mb-3 rounded-md border border-slate-100 bg-white pb-3">
          <div class="flex flex-wrap items-center gap-2 p-3 pb-2 text-[13px]">
            <Button data-testid="attr-new-btn" v-permission="'product.update'" class="bg-[#1677ff] hover:bg-[#4096ff]" @click="openCreate">
              <Plus class="mr-0.5 h-4 w-4" /> 新建属性
            </Button>
            <div class="ml-auto flex flex-wrap items-center gap-2">
              <input v-model="keyword" type="text" placeholder="属性名称" class="w-40 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" @keyup.enter="load" />
              <select v-model="typeFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
                <option value="">全部类型</option>
                <option value="spec">规格</option>
                <option value="param">参数</option>
              </select>
              <Button data-testid="attr-search-btn" class="bg-[#1677ff] hover:bg-[#4096ff]" @click="load">搜索</Button>
            </div>
          </div>

          <template v-if="active">
            <div class="border-t border-slate-100 px-3 pb-2 pt-2 text-[13px] font-medium text-slate-700">
              「{{ active.name }}」属性值
              <span class="ml-1 text-xs font-normal text-slate-400">（保存为覆盖语义，被引用的值不会被删除）</span>
            </div>

            <div class="flex flex-wrap gap-2 px-3 pb-2">
              <span
                v-for="(v, i) in valueDraft" :key="v"
                class="flex items-center gap-1 rounded-full border border-slate-200 px-2.5 py-1 text-[13px] text-slate-600"
              >
                {{ v }}
                <button class="text-slate-400 hover:text-red-500" @click="removeDraft(i)"><X class="h-3 w-3" /></button>
              </span>
              <span v-if="!valueDraft.length" class="text-[13px] text-slate-400">暂无属性值</span>
            </div>

            <div class="flex items-center gap-2 px-3 pb-1 pt-1 text-[13px]">
              <input
                v-model="newValue" type="text" placeholder="输入属性值后回车添加"
                class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
                @keyup.enter="addDraft"
              />
              <Button variant="outline" @click="addDraft"><Plus class="h-4 w-4" /> 添加</Button>
              <Button v-permission="'product.update'" class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="savingValues" @click="saveValues">
                <Check class="h-4 w-4" /> {{ savingValues ? '保存中...' : '保存属性值' }}
              </Button>
            </div>

            <p v-if="message" class="mx-3 rounded-md bg-[#e6f4ff] px-3 py-2 text-[13px] text-[#1677ff]">{{ message }}</p>
          </template>
          <div v-else class="px-3 py-3 text-[13px] text-slate-400">请选择左侧属性</div>
        </div>
      </div>
    </div>

    <!-- 属性编辑弹窗 -->
    <div v-if="dialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-lg">
        <h3 class="mb-4 text-base font-semibold text-slate-800">{{ editingId ? '编辑属性' : '新建属性' }}</h3>
        <div class="space-y-3 text-[13px]">
          <div>
            <label class="mb-1 block text-slate-600">属性名称 <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
          </div>
          <div>
            <label class="mb-1 block text-slate-600">类型</label>
            <select v-model="form.type" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]">
              <option value="spec">规格（参与 SKU 组合）</option>
              <option value="param">参数（商品属性展示）</option>
            </select>
          </div>
          <div class="flex flex-wrap gap-4">
            <label class="flex items-center gap-1.5"><input v-model="form.is_filterable" type="checkbox" /> 可用于筛选</label>
            <label class="flex items-center gap-1.5"><input v-model="form.is_multiple" type="checkbox" /> 多选</label>
            <label class="flex items-center gap-1.5"><input v-model="form.allow_custom" type="checkbox" /> 允许自定义值</label>
          </div>
          <div>
            <label class="mb-1 block text-slate-600">排序</label>
            <input v-model.number="form.sort" type="number" class="w-28 rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
          </div>
          <p v-if="formError" class="rounded-md bg-red-50 px-3 py-2 text-red-500">{{ formError }}</p>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <Button variant="outline" @click="dialogOpen = false">取消</Button>
          <Button class="bg-[#1677ff] hover:bg-[#4096ff]" :disabled="saving" @click="submit">{{ saving ? '保存中...' : '保存' }}</Button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title"
      :message="confirmState?.message"
      confirm-text="删除"
      danger
      @confirm="onConfirm"
      @cancel="confirmState = null"
    />
  </div>
</template>
