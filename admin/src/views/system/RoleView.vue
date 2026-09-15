<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronRight } from 'lucide-vue-next'
import {
  createRole, deleteRole, getRoles, updateRole,
  type PermissionGroup, type RoleRow,
} from '@/api/account'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 角色权限配置（V1.1 F04 / T-023）
 * 权限：role.manage（超管专属）。
 * 权限树：按模块分组，支持全选/半选，保存前二次确认并展示变更摘要。
 */
const loading = ref(true)
const roles = ref<RoleRow[]>([])
const groups = ref<PermissionGroup[]>([])
const activeRoleId = ref<number | null>(null)

/** 勾选中的权限码集合 */
const checked = ref<Set<string>>(new Set())
/** 初始（保存前）的权限码，用于计算变更摘要 */
const initial = ref<Set<string>>(new Set())

const saving = ref(false)
const error = ref('')

// 新建角色（标识 + 中文名 + 权限，一页完成）
const createOpen = ref(false)
const createName = ref('')
const createDisplayName = ref('')
const createError = ref('')
const createChecked = ref<Set<string>>(new Set())
const createExpand = ref<Set<string>>(new Set())

// 编辑中的角色中文名 + 初始快照（用于判断是否有改动）
const displayNameInput = ref('')
const initialDisplayName = ref('')

// 保存确认 & 删除确认
const saveConfirm = ref(false)
const saveNameChanged = ref(false)
const saveSummary = ref<{ added: string[]; removed: string[] }>({ added: [], removed: [] })
const deleteTarget = ref<RoleRow | null>(null)
const expandGroups = ref<Set<string>>(new Set())

const activeRole = computed(() => roles.value.find((r) => r.id === activeRoleId.value) ?? null)

/** 模块分组：全选 / 半选状态（source 可指定为新建弹窗的勾选集合） */
function groupState(group: PermissionGroup, source: Set<string> = checked.value): 'all' | 'none' | 'partial' {
  const hit = group.permissions.filter((p) => source.has(p)).length
  if (hit === 0) return 'none'
  if (hit === group.permissions.length) return 'all'
  return 'partial'
}

function toggleGroup(group: PermissionGroup) {
  const state = groupState(group)
  const next = new Set(checked.value)
  if (state === 'all') {
    group.permissions.forEach((p) => next.delete(p))
  } else {
    group.permissions.forEach((p) => next.add(p))
  }
  checked.value = next
}

function togglePermission(code: string) {
  const next = new Set(checked.value)
  if (next.has(code)) next.delete(code)
  else next.add(code)
  checked.value = next
}

async function load(keepActive = true) {
  loading.value = true
  try {
    const { data } = await getRoles()
    roles.value = data.data.roles
    groups.value = data.data.permission_groups
    if (!keepActive || activeRoleId.value === null) {
      activeRoleId.value = roles.value[0]?.id ?? null
    }
    syncChecked()
  } finally {
    loading.value = false
  }
}

/** 将当前角色权限同步到勾选集合 */
function syncChecked() {
  const role = activeRole.value
  checked.value = new Set(role?.permissions ?? [])
  initial.value = new Set(role?.permissions ?? [])
  displayNameInput.value = role?.display_name ?? ''
  initialDisplayName.value = role?.display_name ?? ''
}

function selectRole(role: RoleRow) {
  activeRoleId.value = role.id
  saveConfirm.value = false
  syncChecked()
}

function openCreate() {
  createName.value = ''
  createDisplayName.value = ''
  createError.value = ''
  createChecked.value = new Set()
  // 新建时默认展开全部分组，权限一览无余
  createExpand.value = new Set(groups.value.map((g) => g.module))
  createOpen.value = true
}

/** 新建弹窗内勾选/取消单个权限 */
function toggleCreatePermission(code: string) {
  const next = new Set(createChecked.value)
  if (next.has(code)) next.delete(code)
  else next.add(code)
  createChecked.value = next
}

/** 新建弹窗内整组勾选/取消 */
function toggleCreateGroup(group: PermissionGroup) {
  const next = new Set(createChecked.value)
  if (groupState(group, createChecked.value) === 'all') {
    group.permissions.forEach((p) => next.delete(p))
  } else {
    group.permissions.forEach((p) => next.add(p))
  }
  createChecked.value = next
}

function toggleCreateExpand(module: string) {
  const next = new Set(createExpand.value)
  if (next.has(module)) next.delete(module)
  else next.add(module)
  createExpand.value = next
}

async function doCreate() {
  createError.value = ''
  const name = createName.value.trim()
  const displayName = createDisplayName.value.trim()
  if (!/^[a-zA-Z0-9_-]{2,32}$/.test(name)) {
    createError.value = '角色标识需 2~32 位字母、数字、下划线或中划线'
    return
  }
  if (!displayName) {
    createError.value = '请填写角色中文名'
    return
  }
  saving.value = true
  try {
    const { data } = await createRole({
      name,
      display_name: displayName,
      permissions: [...createChecked.value],
    })
    createOpen.value = false
    await load(false)
    activeRoleId.value = data.data.id
    syncChecked()
  } catch (e) {
    createError.value = e instanceof Error ? e.message : '创建失败'
  } finally {
    saving.value = false
  }
}

/** 点击保存：先算变更摘要（权限 + 中文名），再二次确认 */
function requestSave() {
  error.value = ''
  const added = [...checked.value].filter((p) => !initial.value.has(p)).sort()
  const removed = [...initial.value].filter((p) => !checked.value.has(p)).sort()
  const nameChanged = (displayNameInput.value || '') !== (initialDisplayName.value || '')
  if (nameChanged && !displayNameInput.value.trim()) {
    error.value = '角色中文名不能为空'
    return
  }
  saveSummary.value = { added, removed }
  saveNameChanged.value = nameChanged
  if (!added.length && !removed.length && !nameChanged) {
    error.value = '权限与中文名均未发生变化'
    return
  }
  saveConfirm.value = true
}

async function doSave() {
  if (!activeRole.value) return
  saving.value = true
  try {
    const payload: { permissions: string[]; display_name?: string } = { permissions: [...checked.value] }
    if (saveNameChanged.value) payload.display_name = displayNameInput.value.trim()
    await updateRole(activeRole.value.id, payload)
    saveConfirm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

async function doDelete() {
  if (!deleteTarget.value) return
  try {
    await deleteRole(deleteTarget.value.id)
    deleteTarget.value = null
    await load(false)
    activeRoleId.value = roles.value[0]?.id ?? null
    syncChecked()
  } catch {
    // 全局拦截器提示
  }
}

function toggleExpand(module: string) {
  const next = new Set(expandGroups.value)
  if (next.has(module)) next.delete(module)
  else next.add(module)
  expandGroups.value = next
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">角色权限</h2>
      <button class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff]" data-testid="role-create-btn" @click="openCreate">新增角色</button>
    </div>

    <div v-if="loading" class="py-10"><LoadingSpinner /></div>

    <div v-else class="flex gap-4">
      <!-- 角色列表 -->
      <aside class="w-56 shrink-0 space-y-1" data-testid="role-list">
        <button
          v-for="role in roles" :key="role.id"
          class="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-[13px] transition-colors"
          :class="activeRoleId === role.id ? 'bg-[#e6f4ff] text-[#1677ff]' : 'text-slate-600 hover:bg-slate-50'"
          :data-testid="`role-item-${role.name}`"
          @click="selectRole(role)"
        >
          <span>
            {{ role.label }}
            <span v-if="role.builtin" class="ml-1 rounded bg-slate-100 px-1 py-0.5 text-[10px] text-slate-400">内置</span>
          </span>
          <span class="text-xs text-slate-400">{{ role.user_count }}</span>
        </button>
      </aside>

      <!-- 权限树 -->
      <div class="min-w-0 flex-1" data-testid="permission-tree">
        <div v-if="activeRole" class="mb-3 flex items-center justify-between border-b border-slate-100 pb-3">
          <div>
            <div class="flex items-center gap-2">
              <p class="text-sm font-medium text-slate-800">{{ activeRole.name }}</p>
              <input
                v-model="displayNameInput"
                type="text" maxlength="64" placeholder="角色中文名"
                class="w-40 rounded-md border border-slate-300 px-2 py-1 text-[13px] outline-none focus:border-[#1677ff]"
                data-testid="role-display-name-input"
              />
            </div>
            <p class="text-xs text-slate-400">已选 {{ checked.size }} 项权限 · 该角色下 {{ activeRole.user_count }} 个账号</p>
          </div>
          <div class="flex items-center gap-2">
            <button
              v-if="!activeRole.builtin"
              class="rounded-md border border-red-200 px-3 py-1.5 text-[13px] text-red-500 hover:bg-red-50"
              data-testid="role-delete-btn"
              @click="deleteTarget = activeRole"
            >删除角色</button>
            <button
              class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
              :disabled="saving" data-testid="role-save-btn"
              @click="requestSave"
            >保存权限</button>
          </div>
        </div>

        <p v-if="error" class="mb-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-600" data-testid="role-error">{{ error }}</p>

        <div class="space-y-3">
          <div v-for="group in groups" :key="group.module" class="rounded-lg border border-slate-100">
            <div class="flex items-center gap-2 border-b border-slate-50 px-3 py-2">
              <input
                type="checkbox"
                :checked="groupState(group) === 'all'"
                :indeterminate="groupState(group) === 'partial'"
                :data-testid="`group-checkbox-${group.module}`"
                @change="toggleGroup(group)"
              />
              <button class="flex items-center gap-1 text-[13px] font-medium text-slate-700" @click="toggleExpand(group.module)">
                <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="expandGroups.has(group.module) ? 'rotate-90' : ''" />
                {{ group.label }}
              </button>
              <span
                v-if="groupState(group) === 'partial'"
                class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-[#1677ff]"
                :data-testid="`group-partial-${group.module}`"
              >部分选中</span>
            </div>
            <div v-show="expandGroups.has(group.module)" class="flex flex-wrap gap-x-4 gap-y-2 p-3">
              <label v-for="code in group.permissions" :key="code" class="flex items-center gap-1.5 text-[13px] text-slate-600">
                <input
                  type="checkbox" :checked="checked.has(code)"
                  :data-testid="`perm-${code}`"
                  @change="togglePermission(code)"
                />
                <code class="rounded bg-slate-50 px-1.5 py-0.5 text-xs">{{ code }}</code>
              </label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 新建角色（标识 + 中文名 + 权限，一页完成） -->
    <div v-if="createOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="createOpen = false">
      <div class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-xl bg-white p-6" data-testid="role-create-form">
        <h3 class="text-sm font-semibold text-slate-800">新增角色</h3>

        <div class="mt-4 grid grid-cols-2 gap-4 text-[13px]">
          <label class="block">
            <span class="mb-1 block text-slate-500">角色标识（英文，唯一）</span>
            <input v-model="createName" type="text" placeholder="如 customer_service" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" data-testid="role-name-input" />
          </label>
          <label class="block">
            <span class="mb-1 block text-slate-500">角色中文名（展示用）</span>
            <input v-model="createDisplayName" type="text" maxlength="64" placeholder="如 客服" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" data-testid="role-display-name-create-input" />
          </label>
        </div>
        <p v-if="createError" class="mt-2 text-xs text-red-500" data-testid="role-create-error">{{ createError }}</p>

        <!-- 权限选择 -->
        <div class="mt-4 min-h-0 flex-1 overflow-y-auto" data-testid="role-create-permissions">
          <div class="mb-2 flex items-center justify-between">
            <p class="text-[13px] font-medium text-slate-700">配置权限</p>
            <p class="text-xs text-slate-400">已选 {{ createChecked.size }} 项</p>
          </div>
          <div class="space-y-2">
            <div v-for="group in groups" :key="group.module" class="rounded-lg border border-slate-100">
              <div class="flex items-center gap-2 border-b border-slate-50 px-3 py-1.5">
                <input
                  type="checkbox"
                  :checked="groupState(group, createChecked) === 'all'"
                  :indeterminate="groupState(group, createChecked) === 'partial'"
                  :data-testid="`create-group-checkbox-${group.module}`"
                  @change="toggleCreateGroup(group)"
                />
                <button class="flex items-center gap-1 text-[13px] font-medium text-slate-700" @click="toggleCreateExpand(group.module)">
                  <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="createExpand.has(group.module) ? 'rotate-90' : ''" />
                  {{ group.label }}
                </button>
              </div>
              <div v-show="createExpand.has(group.module)" class="flex flex-wrap gap-x-4 gap-y-2 p-3">
                <label v-for="code in group.permissions" :key="code" class="flex items-center gap-1.5 text-[13px] text-slate-600">
                  <input
                    type="checkbox" :checked="createChecked.has(code)"
                    :data-testid="`create-perm-${code}`"
                    @change="toggleCreatePermission(code)"
                  />
                  <code class="rounded bg-slate-50 px-1.5 py-0.5 text-xs">{{ code }}</code>
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="createOpen = false">取消</button>
          <button class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50" :disabled="saving" data-testid="role-create-submit" @click="doCreate">{{ saving ? '创建中…' : '创建角色' }}</button>
        </div>
      </div>
    </div>

    <!-- 保存确认（含变更摘要） -->
    <ConfirmDialog
      :open="saveConfirm"
      title="确认保存变更？"
      :message="`新增 ${saveSummary.added.length} 项、移除 ${saveSummary.removed.length} 项权限${saveNameChanged ? '；同时更新角色中文名' : ''}。保存后该角色下账号权限将在其刷新后生效。`"
      confirm-text="确认保存"
      @confirm="doSave"
      @cancel="saveConfirm = false"
    />

    <!-- 删除确认 -->
    <ConfirmDialog
      :open="!!deleteTarget"
      :title="`确认删除角色「${deleteTarget?.label ?? ''}」？`"
      :message="'删除后不可恢复；角色下仍有账号时将被拒绝。'"
      confirm-text="确认删除"
      danger
      @confirm="doDelete"
      @cancel="deleteTarget = null"
    />
  </div>
</template>
