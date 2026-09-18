<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Plus } from 'lucide-vue-next'
import {
  createCsQuickReply, deleteCsQuickReply, getCsQuickReplies, getCsTicketTypes, updateCsQuickReply,
  type CsQuickReplyPayload, type CsQuickReplyRow, type CsTicketTypeOption,
} from '@/api/cs'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

/**
 * 快捷回复模板管理（CS-204）
 *
 * 列表按「通用 / 各工单类型」分组渲染；支持新增、编辑、删除（二次确认）、排序调整。
 * 权限：cs.faq.manage（菜单已隐藏无权限角色入口；接口层再次校验）。
 */
const loading = ref(true)
const list = ref<CsQuickReplyRow[]>([])
const types = ref<CsTicketTypeOption[]>([])
const error = ref('')

// 类型筛选：null=全部；0=通用；其余为类型 id
const filterType = ref<number | null>(null)

// 编辑弹窗
const editing = ref<CsQuickReplyRow | null>(null)
const formVisible = ref(false)
const form = ref<CsQuickReplyPayload & { id?: number }>({ title: '', content: '', type_id: null, sort: 0 })
const saving = ref(false)
const formError = ref('')

// 删除确认
const deleteTarget = ref<CsQuickReplyRow | null>(null)
const deleting = ref(false)

const sortedList = computed(() => [...list.value].sort((a, b) => a.sort - b.sort || a.id - b.id))

/** 分组：通用组 + 各类型组（按 types 顺序）；filterType 命中时只保留该组 */
const groups = computed(() => {
  const result: Array<{ key: number; name: string; items: CsQuickReplyRow[] }> = []
  const general = sortedList.value.filter((r) => r.type_id === null || r.type_id === 0)
  if (filterType.value === null || filterType.value === 0) {
    result.push({ key: 0, name: '通用', items: general })
  }
  for (const t of types.value) {
    if (filterType.value !== null && filterType.value !== t.id) continue
    result.push({ key: t.id, name: t.name, items: sortedList.value.filter((r) => r.type_id === t.id) })
  }
  return result.filter((g) => g.items.length > 0 || filterType.value !== null)
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [replies, typeRes] = await Promise.all([getCsQuickReplies(), getCsTicketTypes()])
    list.value = replies.data.data
    types.value = typeRes.data.data
  } catch (e) {
    error.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = { title: '', content: '', type_id: null, sort: 0 }
  formError.value = ''
  formVisible.value = true
}

function openEdit(row: CsQuickReplyRow) {
  editing.value = row
  form.value = { id: row.id, title: row.title, content: row.content, type_id: row.type_id, sort: row.sort }
  formError.value = ''
  formVisible.value = true
}

async function save() {
  if (!form.value.title.trim() || !form.value.content.trim()) {
    formError.value = '标题与内容为必填'
    return
  }
  saving.value = true
  formError.value = ''
  try {
    if (editing.value) {
      await updateCsQuickReply(editing.value.id, {
        title: form.value.title.trim(),
        content: form.value.content,
        type_id: form.value.type_id ?? null,
        sort: form.value.sort ?? 0,
      })
    } else {
      await createCsQuickReply({
        title: form.value.title.trim(),
        content: form.value.content,
        type_id: form.value.type_id ?? null,
        sort: form.value.sort ?? 0,
      })
    }
    formVisible.value = false
    editing.value = null
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

function askDelete(row: CsQuickReplyRow) {
  deleteTarget.value = row
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await deleteCsQuickReply(deleteTarget.value.id)
    deleteTarget.value = null
    await load()
  } finally {
    deleting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-base font-semibold text-slate-800">快捷回复模板</h2>

    <!-- 筛选行 -->
    <div class="mb-3 flex flex-wrap items-center gap-2 text-[13px]">
      <label class="text-slate-500">适用类型</label>
      <select v-model="filterType" class="rounded-md border border-slate-300 px-2 py-1 text-[13px] outline-none focus:border-[#1677ff]" data-testid="cs-qr-filter">
        <option :value="null">全部</option>
        <option :value="0">通用</option>
        <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
      </select>
      <Button class="ml-auto" data-testid="cs-qr-create" @click="openCreate">
        <Plus class="mr-1 h-4 w-4" /> 新增模板
      </Button>
    </div>

    <LoadingSpinner v-if="loading" />

    <p v-else-if="error" class="py-6 text-center text-sm text-red-500" data-testid="cs-qr-error">{{ error }}</p>

    <p v-else-if="list.length === 0" class="py-10 text-center text-sm text-slate-400" data-testid="cs-qr-empty">
      暂无模板，点击右上角「新增模板」创建
    </p>

    <template v-else>
      <div v-for="g in groups" :key="g.key" class="mb-4" :data-testid="`cs-qr-group-${g.key}`">
        <div class="mb-1 flex items-center gap-2 border-b border-slate-100 pb-1">
          <span class="text-xs font-semibold text-slate-500">{{ g.name }}</span>
          <span class="text-[11px] text-slate-300">{{ g.items.length }} 条</span>
        </div>
        <table class="w-full text-[13px]">
          <tbody>
            <tr
              v-for="row in g.items" :key="row.id"
              class="border-b border-slate-100 hover:bg-slate-50"
              :data-testid="`cs-qr-row-${row.id}`"
            >
              <td class="w-40 px-3 py-2 font-medium text-slate-700">{{ row.title }}</td>
              <td class="px-3 py-2 text-slate-500">
                <span class="line-clamp-1">{{ row.content }}</span>
              </td>
              <td class="w-16 px-3 py-2 text-slate-400">{{ row.sort }}</td>
              <td class="w-32 px-3 py-2 text-right">
                <button class="text-[#1677ff] hover:underline" data-testid="cs-qr-edit" @click="openEdit(row)">编辑</button>
                <button class="ml-3 text-red-500 hover:underline" data-testid="cs-qr-delete" @click="askDelete(row)">删除</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- 新增 / 编辑弹窗 -->
    <div v-if="formVisible" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40" data-testid="cs-qr-modal" @click.self="formVisible = false">
      <div class="w-[480px] rounded-lg bg-white p-5 shadow-xl">
        <h3 class="mb-3 text-base font-semibold text-slate-800">{{ editing ? '编辑模板' : '新增模板' }}</h3>
        <div class="space-y-3 text-[13px]">
          <div>
            <label class="mb-1 block text-slate-500">标题</label>
            <input v-model="form.title" maxlength="64" placeholder="列表/按钮上显示的名称" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-qr-form-title" />
          </div>
          <div>
            <label class="mb-1 block text-slate-500">内容</label>
            <textarea v-model="form.content" rows="4" maxlength="2000" placeholder="支持变量：{user_nickname} {ticket_no} {order_no}" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-qr-form-content"></textarea>
          </div>
          <div class="flex gap-3">
            <div class="flex-1">
              <label class="mb-1 block text-slate-500">适用类型</label>
              <select v-model="form.type_id" class="w-full rounded-md border border-slate-300 px-2 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-qr-form-type">
                <option :value="null">通用</option>
                <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </div>
            <div class="w-24">
              <label class="mb-1 block text-slate-500">排序</label>
              <input v-model.number="form.sort" type="number" min="0" class="w-full rounded-md border border-slate-300 px-2 py-2 outline-none focus:border-[#1677ff]" data-testid="cs-qr-form-sort" />
            </div>
          </div>
        </div>
        <p v-if="formError" class="mt-2 text-xs text-red-500" data-testid="cs-qr-form-error">{{ formError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <Button variant="outline" data-testid="cs-qr-form-cancel" @click="formVisible = false">取消</Button>
          <Button :disabled="saving" data-testid="cs-qr-form-save" @click="save">{{ saving ? '保存中…' : '保存' }}</Button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :open="deleteTarget !== null"
      title="删除快捷回复"
      :message="`确认删除模板「${deleteTarget?.title ?? ''}」？该操作不可恢复。`"
      danger
      :confirm-text="deleting ? '删除中…' : '删除'"
      @confirm="confirmDelete"
      @cancel="deleteTarget = null"
    />
  </div>
</template>
