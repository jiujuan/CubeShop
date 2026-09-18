<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Search } from 'lucide-vue-next'
import {
  createAccount, getAccounts, resetAccountPassword, setAccountStatus, updateAccount,
  ROLE_LABELS, type AccountRow,
} from '@/api/account'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useAuthStore } from '@/stores/auth'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 管理员账号管理（V1.1 F04 / T-023）
 * 权限：account.manage（超管专属）。
 * 自我保护：当前登录账号不可禁用/编辑角色。
 */
const auth = useAuthStore()

const loading = ref(true)
const list = ref<AccountRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })

const keyword = ref('')
const roleFilter = ref('')
const statusFilter = ref<'' | 0 | 1>('')

/** 角色选项直接来自共享标签表，新增后台角色时无需再改本页 */
const roleOptions = Object.entries(ROLE_LABELS).map(([value, label]) => ({ value, label }))

const statusTabs: Array<{ value: '' | 0 | 1; label: string }> = [
  { value: '', label: '全部' },
  { value: 1, label: '正常' },
  { value: 0, label: '禁用' },
]

// 弹窗状态
const formOpen = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formTarget = ref<AccountRow | null>(null)
const form = ref({ username: '', password: '', nickname: '', email: '', phone: '', roles: [] as string[] })
const formError = ref('')
const saving = ref(false)

const statusTarget = ref<AccountRow | null>(null)
const resetTarget = ref<AccountRow | null>(null)
const resetForm = ref({ password: '', confirm: '' })
const resetError = ref('')
const resetResult = ref<string | null>(null)
const copied = ref(false)

const selfId = computed(() => auth.user?.id ?? null)

function isSelf(row: AccountRow): boolean {
  return selfId.value !== null && row.id === selfId.value
}

/** 密码强度校验（与后端一致：8~32 位含字母与数字） */
function passwordValid(pwd: string): boolean {
  return /^(?=.*[A-Za-z])(?=.*\d).+$/.test(pwd) && pwd.length >= 8 && pwd.length <= 32
}

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getAccounts({
      keyword: keyword.value.trim() || undefined,
      role: roleFilter.value || undefined,
      status: statusFilter.value === '' ? undefined : statusFilter.value,
      page,
      page_size: pagination.value.page_size,
    })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

function filterStatus(s: '' | 0 | 1) {
  statusFilter.value = s
  load(1)
}

function openCreate() {
  formMode.value = 'create'
  formTarget.value = null
  form.value = { username: '', password: '', nickname: '', email: '', phone: '', roles: ['operator'] }
  formError.value = ''
  formOpen.value = true
}

function openEdit(row: AccountRow) {
  formMode.value = 'edit'
  formTarget.value = row
  form.value = {
    username: row.username,
    password: '',
    nickname: row.nickname ?? '',
    email: row.email ?? '',
    phone: row.phone ?? '',
    roles: [...row.roles],
  }
  formError.value = ''
  formOpen.value = true
}

function toggleRole(name: string) {
  const idx = form.value.roles.indexOf(name)
  if (idx >= 0) form.value.roles.splice(idx, 1)
  else form.value.roles.push(name)
}

async function doSave() {
  formError.value = ''
  if (!form.value.roles.length) {
    formError.value = '请至少选择一个角色'
    return
  }
  if (formMode.value === 'create') {
    if (!form.value.username.trim()) { formError.value = '请输入用户名'; return }
    if (!passwordValid(form.value.password)) { formError.value = '密码需 8~32 位且同时包含字母与数字'; return }
  }
  saving.value = true
  try {
    if (formMode.value === 'create') {
      await createAccount({
        username: form.value.username.trim(),
        password: form.value.password,
        nickname: form.value.nickname.trim() || undefined,
        email: form.value.email.trim() || undefined,
        phone: form.value.phone.trim() || undefined,
        roles: form.value.roles,
      })
    } else if (formTarget.value) {
      await updateAccount(formTarget.value.id, {
        nickname: form.value.nickname.trim() || undefined,
        email: form.value.email.trim() || undefined,
        phone: form.value.phone.trim() || undefined,
        roles: form.value.roles,
      })
    }
    formOpen.value = false
    await load(pagination.value.page)
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

async function doToggleStatus() {
  if (!statusTarget.value) return
  const target = statusTarget.value
  const next = target.status === 1 ? 0 : 1
  try {
    await setAccountStatus(target.id, next)
    statusTarget.value = null
    await load(pagination.value.page)
  } catch {
    // 全局拦截器已提示
  }
}

function openReset(row: AccountRow) {
  resetTarget.value = row
  resetForm.value = { password: '', confirm: '' }
  resetError.value = ''
  resetResult.value = null
  copied.value = false
}

async function doReset() {
  resetError.value = ''
  if (!passwordValid(resetForm.value.password)) {
    resetError.value = '密码需 8~32 位且同时包含字母与数字'
    return
  }
  if (resetForm.value.password !== resetForm.value.confirm) {
    resetError.value = '两次输入的密码不一致'
    return
  }
  if (!resetTarget.value) return
  saving.value = true
  try {
    await resetAccountPassword(resetTarget.value.id, resetForm.value.password)
    // 一次性展示初始密码
    resetResult.value = resetForm.value.password
  } catch (e) {
    resetError.value = e instanceof Error ? e.message : '重置失败'
  } finally {
    saving.value = false
  }
}

async function copyPassword() {
  if (!resetResult.value) return
  try {
    await navigator.clipboard.writeText(resetResult.value)
    copied.value = true
  } catch {
    copied.value = false
  }
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">管理员账号</h2>
      <button
        class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff]"
        data-testid="account-create-btn"
        @click="openCreate"
      >新增账号</button>
    </div>

    <!-- 筛选 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="keyword" type="text" placeholder="用户名 / 昵称 / 邮箱"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <select v-model="roleFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">全部角色</option>
        <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <button class="flex items-center gap-1 rounded-md bg-[#1677ff] px-4 py-1.5 text-white hover:bg-[#4096ff]" @click="search">
        <Search class="h-3.5 w-3.5" /> 搜索
      </button>
    </div>

    <div class="mb-4 flex gap-2">
      <button
        v-for="tab in statusTabs" :key="String(tab.value)"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="statusFilter === tab.value ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
        @click="filterStatus(tab.value)"
      >{{ tab.label }}</button>
    </div>

    <!-- 列表 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-14 px-3 py-1.5">ID</th>
          <th class="px-3 py-1.5">账号</th>
          <th class="w-40 px-3 py-1.5">角色</th>
          <th class="w-20 px-3 py-1.5">状态</th>
          <th class="w-44 px-3 py-1.5">最后登录</th>
          <th class="w-56 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50" :data-testid="`account-row-${row.id}`">
          <td class="px-3 py-1.5 font-mono text-slate-600">{{ row.id }}</td>
          <td class="px-3 py-1.5">
            <p class="text-black">{{ row.nickname || '-' }}</p>
            <p class="text-xs text-slate-400">@{{ row.username }}</p>
          </td>
          <td class="px-3 py-1.5">
            <span
              v-for="r in row.roles" :key="r"
              class="mr-1 rounded bg-[#e6f4ff] px-1.5 py-0.5 text-xs text-[#1677ff]"
            >{{ ROLE_LABELS[r] ?? r }}</span>
          </td>
          <td class="px-3 py-1.5">
            <span
              class="rounded px-2 py-0.5 text-xs"
              :class="row.status === 1 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'"
            >{{ row.status === 1 ? '正常' : '禁用' }}</span>
          </td>
          <td class="px-3 py-1.5 text-slate-600">{{ row.last_login_at || '从未登录' }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button class="hover:underline" @click="openEdit(row)">编辑</button>
              <span class="text-slate-200">|</span>
              <button class="hover:underline" @click="openReset(row)">重置密码</button>
              <span class="text-slate-200">|</span>
              <template v-if="isSelf(row)">
                <span class="cursor-not-allowed text-slate-300" :title="'不能禁用自己的账号'" data-testid="self-disable-guard">禁用</span>
              </template>
              <button
                v-else
                :class="row.status === 1 ? 'text-red-500' : 'text-green-600'"
                class="hover:underline"
                @click="statusTarget = row"
              >{{ row.status === 1 ? '禁用' : '启用' }}</button>
            </div>
          </td>
        </tr>
        <tr v-if="loading"><td colspan="6"><LoadingSpinner /></td></tr>
        <tr v-if="!list.length && !loading"><td colspan="6" class="px-3 py-12 text-center text-slate-400">暂无数据</td></tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <TablePagination :pagination="pagination" :total-text="`共 ${pagination.total} 个账号`" @change="goPage" />

    <!-- 新增/编辑弹窗 -->
    <div v-if="formOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="formOpen = false">
      <div class="w-full max-w-md rounded-xl bg-white p-6" data-testid="account-form">
        <h3 class="text-sm font-semibold text-slate-800">{{ formMode === 'create' ? '新增管理员账号' : '编辑账号' }}</h3>
        <div class="mt-4 space-y-3 text-[13px]">
          <label class="block">
            <span class="mb-1 block text-slate-500">用户名 <span class="text-red-500">*</span></span>
            <input v-model="form.username" type="text" :disabled="formMode === 'edit'" data-testid="account-username" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff] disabled:bg-slate-50" />
          </label>
          <label v-if="formMode === 'create'" class="block">
            <span class="mb-1 block text-slate-500">初始密码 <span class="text-red-500">*</span></span>
            <input v-model="form.password" type="password" data-testid="account-password" placeholder="8~32 位，含字母与数字" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" />
          </label>
          <label class="block">
            <span class="mb-1 block text-slate-500">昵称</span>
            <input v-model="form.nickname" type="text" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" />
          </label>
          <div class="grid grid-cols-2 gap-2">
            <label class="block">
              <span class="mb-1 block text-slate-500">邮箱</span>
              <input v-model="form.email" type="email" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">手机号</span>
              <input v-model="form.phone" type="text" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" />
            </label>
          </div>
          <div>
            <span class="mb-1 block text-slate-500">角色 <span class="text-red-500">*</span></span>
            <div class="flex gap-4" data-testid="role-picker">
              <label v-for="opt in roleOptions" :key="opt.value" class="flex items-center gap-1.5">
                <input
                  type="checkbox" :value="opt.value"
                  :checked="form.roles.includes(opt.value)"
                  :data-testid="`role-checkbox-${opt.value}`"
                  @change="toggleRole(opt.value)"
                />
                {{ opt.label }}
              </label>
            </div>
          </div>
          <p v-if="formError" class="text-xs text-red-500" data-testid="form-error">{{ formError }}</p>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="formOpen = false">取消</button>
          <button class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50" :disabled="saving" @click="doSave">{{ saving ? '保存中…' : '保存' }}</button>
        </div>
      </div>
    </div>

    <!-- 重置密码弹窗 -->
    <div v-if="resetTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="resetTarget = null">
      <div class="w-full max-w-sm rounded-xl bg-white p-6" data-testid="reset-form">
        <h3 class="text-sm font-semibold text-slate-800">重置密码</h3>
        <p class="mt-1 text-xs text-slate-400">账号：{{ resetTarget.username }}（重置后该账号需重新登录）</p>

        <template v-if="!resetResult">
          <div class="mt-4 space-y-3 text-[13px]">
            <label class="block">
              <span class="mb-1 block text-slate-500">新密码</span>
              <input v-model="resetForm.password" type="password" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" data-testid="reset-password" />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">确认密码</span>
              <input v-model="resetForm.confirm" type="password" class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" data-testid="reset-confirm" />
            </label>
            <p v-if="resetError" class="text-xs text-red-500" data-testid="reset-error">{{ resetError }}</p>
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="resetTarget = null">取消</button>
            <button class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50" :disabled="saving" @click="doReset">{{ saving ? '提交中…' : '确认重置' }}</button>
          </div>
        </template>

        <template v-else>
          <div class="mt-4 rounded-lg bg-amber-50 p-3 text-[13px] text-amber-700" data-testid="reset-result">
            <p class="mb-2">初始密码仅展示一次，请立即复制并转交本人：</p>
            <div class="flex items-center gap-2">
              <code class="flex-1 rounded bg-white px-2 py-1 font-mono">{{ resetResult }}</code>
              <button class="rounded border border-amber-300 px-2 py-1 text-xs" data-testid="copy-password" @click="copyPassword">{{ copied ? '已复制' : '复制' }}</button>
            </div>
          </div>
          <div class="mt-4 flex justify-end">
            <button class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff]" @click="resetTarget = null">我已复制，关闭</button>
          </div>
        </template>
      </div>
    </div>

    <!-- 禁用/启用确认 -->
    <ConfirmDialog
      :open="!!statusTarget"
      :title="statusTarget?.status === 1 ? '确认禁用该账号？' : '确认启用该账号？'"
      :message="statusTarget?.status === 1
        ? `禁用后账号 ${statusTarget?.username} 将立即下线并无法登录。`
        : `启用后账号 ${statusTarget?.username} 可正常登录。`"
      :confirm-text="statusTarget?.status === 1 ? '确认禁用' : '确认启用'"
      :danger="statusTarget?.status === 1"
      @confirm="doToggleStatus"
      @cancel="statusTarget = null"
    />
  </div>
</template>
