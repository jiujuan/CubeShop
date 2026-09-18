<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Search } from 'lucide-vue-next'
import {
  getUsers,
  getUser,
  getUserAddresses,
  updateAdminAddress,
  updateUser,
  updateUserStatus,
  type AdminUser,
  type AdminUserAddress,
  type AdminUserDetail,
} from '@/api/user'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 用户管理（Roadmap P7+ / 权限 user.manage）：
 * 前台注册用户的查看、搜索、资料编辑与启用/禁用
 */
const loading = ref(true)
const list = ref<AdminUser[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })

// 筛选条件
const keyword = ref('')
const statusFilter = ref<'' | 0 | 1>('')
const startDate = ref('')
const endDate = ref('')

// 详情 / 编辑 / 状态切换
const detailUser = ref<AdminUserDetail | null>(null)
const detailLoading = ref(false)
const detailAddresses = ref<AdminUserAddress[] | null>(null)
const editTarget = ref<AdminUser | null>(null)
const editForm = ref({ nickname: '', phone: '', email: '' })
const saving = ref(false)
const statusTarget = ref<AdminUser | null>(null)

// 地址代改（设计文档 CubeShop_Address_Design_v1.0：只读查看 + 受限代改）
const addrEditTarget = ref<AdminUserAddress | null>(null)
const addrForm = ref({ contact_name: '', contact_phone: '', province: '', city: '', district: '', detail_address: '' })
const addrSaving = ref(false)
const addrConfirm = ref<AdminUserAddress | null>(null)

const statusTabs: Array<{ value: '' | 0 | 1; label: string }> = [
  { value: '', label: '全部' },
  { value: 1, label: '正常' },
  { value: 0, label: '禁用' },
]

const queryParams = computed(() => ({
  keyword: keyword.value.trim() || undefined,
  status: statusFilter.value === '' ? undefined : statusFilter.value,
  start_time: startDate.value ? `${startDate.value} 00:00:00` : undefined,
  end_time: endDate.value ? `${endDate.value} 23:59:59` : undefined,
  page_size: pagination.value.page_size,
}))

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getUsers({ ...queryParams.value, page })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

/** 顶部胶囊快捷筛选：点击立即按状态加载第一页 */
function filterStatus(status: '' | 0 | 1) {
  statusFilter.value = status
  load(1)
}

/** 是否受保护账号（超级管理员不允许编辑/禁用） */
function isProtected(user: AdminUser): boolean {
  return user.roles.includes('super_admin')
}

async function openDetail(user: AdminUser) {
  detailLoading.value = true
  detailUser.value = { ...user, recent_orders: [] }
  detailAddresses.value = null
  try {
    // 详情与地址并行加载；地址接口挂 address.view，无权限时详情仍可看
    const [detailRes, addrRes] = await Promise.allSettled([getUser(user.id), getUserAddresses(user.id)])
    if (detailRes.status === 'fulfilled') {
      detailUser.value = detailRes.value.data.data
    }
    if (addrRes.status === 'fulfilled') {
      detailAddresses.value = addrRes.value.data.data
    } else {
      // 无 address.view 权限或网络异常：置空展示，不阻塞详情
      detailAddresses.value = []
    }
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  detailUser.value = null
  detailAddresses.value = null
  addrEditTarget.value = null
  addrConfirm.value = null
}

/** 地址展示用完整文本 */
function fullAddr(addr: AdminUserAddress): string {
  return [addr.province, addr.city, addr.district, addr.detail_address].filter(Boolean).join(' ')
}

function openAddrEdit(addr: AdminUserAddress) {
  addrEditTarget.value = addr
  addrForm.value = {
    contact_name: addr.contact_name,
    contact_phone: addr.contact_phone_full,
    province: addr.province ?? '',
    city: addr.city ?? '',
    district: addr.district ?? '',
    detail_address: addr.detail_address,
  }
}

function requestAddrSave() {
  if (!addrFormValid.value) return
  // 二次确认：提示快照不可变
  addrConfirm.value = addrEditTarget.value
}

/** 收货人 / 手机号 / 详细地址为必填，手机号与后台同一正则 */
const addrFormValid = computed(() => {
  const form = addrForm.value
  return form.contact_name.trim() !== ''
    && /^1[3-9]\d{9}$/.test(form.contact_phone.trim())
    && form.detail_address.trim() !== ''
})

async function doAddrSave() {
  if (!addrConfirm.value) return
  const target = addrConfirm.value
  const form = addrForm.value
  addrSaving.value = true
  try {
    const { data } = await updateAdminAddress(target.id, {
      contact_name: form.contact_name.trim(),
      contact_phone: form.contact_phone.trim(),
      province: form.province.trim() || undefined,
      city: form.city.trim() || undefined,
      district: form.district.trim() || undefined,
      detail_address: form.detail_address.trim(),
    })
    addrConfirm.value = null
    addrEditTarget.value = null
    // 就地更新地址区块
    if (detailAddresses.value) {
      detailAddresses.value = detailAddresses.value.map((a) => (a.id === data.data.id ? data.data : a))
    }
  } catch {
    // 错误提示由全局拦截器统一处理
  } finally {
    addrSaving.value = false
  }
}

function openEdit(user: AdminUser) {
  editTarget.value = user
  editForm.value = { nickname: user.nickname ?? '', phone: user.phone ?? '', email: user.email ?? '' }
}

async function doSave() {
  if (!editTarget.value) return
  saving.value = true
  try {
    const { data } = await updateUser(editTarget.value.id, {
      nickname: editForm.value.nickname.trim() || undefined,
      phone: editForm.value.phone.trim() || undefined,
      email: editForm.value.email.trim() || undefined,
    })
    editTarget.value = null
    if (detailUser.value?.id === data.data.id) {
      detailUser.value = data.data
    }
    await load(pagination.value.page)
  } finally {
    saving.value = false
  }
}

async function doToggleStatus() {
  if (!statusTarget.value) return
  const target = statusTarget.value
  const next = target.status === 1 ? 0 : 1
  try {
    const { data } = await updateUserStatus(target.id, next)
    statusTarget.value = null
    if (detailUser.value?.id === data.data.id) {
      detailUser.value = { ...detailUser.value, ...data.data }
    }
    await load(pagination.value.page)
  } catch {
    // 错误提示由全局拦截器统一处理
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
    <!-- 标题 -->
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">用户管理</h2>
      <span class="text-xs text-slate-400">共 {{ pagination.total }} 位用户</span>
    </div>

    <!-- 筛选区 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="keyword" type="text" placeholder="用户名 / 昵称 / 手机号 / 邮箱"
        class="w-56 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <input v-model="startDate" type="date" class="outline-none" />
        <span class="text-slate-300">–</span>
        <input v-model="endDate" type="date" class="outline-none" />
      </div>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="search"><Search class="mr-1 h-4 w-4" /> 搜索</Button>
    </div>

    <!-- 状态快捷筛选（胶囊标签排） -->
    <div class="mb-4 flex flex-wrap gap-2">
      <button
        v-for="tab in statusTabs"
        :key="String(tab.value)"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="statusFilter === tab.value ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
        @click="filterStatus(tab.value)"
      >{{ tab.label }}</button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="w-14 px-3 py-1.5">ID</th>
          <th class="px-3 py-1.5">用户</th>
          <th class="w-32 px-3 py-1.5">手机号</th>
          <th class="w-44 px-3 py-1.5">邮箱</th>
          <th class="w-20 px-3 py-1.5">订单数</th>
          <th class="w-24 px-3 py-1.5">累计消费</th>
          <th class="w-20 px-3 py-1.5">状态</th>
          <th class="w-40 px-3 py-1.5">注册时间</th>
          <th class="w-40 px-3 py-1.5">最后登录</th>
          <th class="w-36 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="user in list" :key="user.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ user.id }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-2">
              <img v-if="user.avatar" :src="user.avatar" alt="" class="h-7 w-7 rounded-full object-cover" />
              <span
                v-else
                class="flex h-7 w-7 items-center justify-center rounded-full bg-[#e6f4ff] text-xs text-[#1677ff]"
              >{{ (user.nickname || user.username).slice(0, 1).toUpperCase() }}</span>
              <div class="min-w-0 leading-tight">
                <p class="truncate text-black">{{ user.nickname || '-' }}</p>
                <p class="truncate text-xs text-slate-400">{{ user.username }}</p>
              </div>
            </div>
          </td>
          <td class="px-3 py-1.5 text-black">{{ user.phone || '-' }}</td>
          <td class="max-w-44 truncate px-3 py-1.5 text-black" :title="user.email ?? ''">{{ user.email || '-' }}</td>
          <td class="px-3 py-1.5 text-black">{{ user.order_count }}</td>
          <td class="px-3 py-1.5 font-medium text-black">¥{{ user.total_paid }}</td>
          <td class="px-3 py-1.5">
            <span
              class="rounded px-2 py-0.5 text-xs"
              :class="user.status === 1 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'"
            >{{ user.status === 1 ? '正常' : '禁用' }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ user.created_at }}</td>
          <td class="px-3 py-1.5 text-black">
            <template v-if="user.last_login_at">{{ user.last_login_at }}</template>
            <template v-else>-</template>
          </td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button class="hover:underline" @click="openDetail(user)">详情</button>
              <template v-if="!isProtected(user)">
                <span class="text-slate-200">|</span>
                <button class="hover:underline" @click="openEdit(user)">编辑</button>
                <span class="text-slate-200">|</span>
                <button :class="user.status === 1 ? 'text-red-500' : 'text-green-600'" class="hover:underline" @click="statusTarget = user">
                  {{ user.status === 1 ? '禁用' : '启用' }}
                </button>
              </template>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="10"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="10" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 详情弹窗 -->
    <div v-if="detailUser" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="closeDetail">
      <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-base font-semibold">用户详情</h2>
          <button class="text-slate-400 hover:text-slate-600" @click="closeDetail">✕</button>
        </div>
        <LoadingSpinner v-if="detailLoading" />

        <template v-else>
          <div class="mb-4 flex items-center gap-3">
            <img v-if="detailUser.avatar" :src="detailUser.avatar" alt="" class="h-12 w-12 rounded-full object-cover" />
            <span
              v-else
              class="flex h-12 w-12 items-center justify-center rounded-full bg-[#e6f4ff] text-lg text-[#1677ff]"
            >{{ (detailUser.nickname || detailUser.username).slice(0, 1).toUpperCase() }}</span>
            <div>
              <p class="font-medium text-slate-800">{{ detailUser.nickname || '-' }} <span class="text-xs text-slate-400">@{{ detailUser.username }}</span></p>
              <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-400">
                <span
                  class="rounded px-1.5 py-0.5"
                  :class="detailUser.status === 1 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'"
                >{{ detailUser.status === 1 ? '正常' : '禁用' }}</span>
                <span v-for="role in detailUser.roles" :key="role" class="rounded bg-slate-100 px-1.5 py-0.5">{{ role }}</span>
              </p>
            </div>
          </div>

          <div class="mb-4 grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg bg-slate-50 p-3 text-[13px] text-slate-600">
            <p>手机号：{{ detailUser.phone || '—' }}</p>
            <p>邮箱：{{ detailUser.email || '—' }}</p>
            <p>注册时间：{{ detailUser.created_at }}</p>
            <p>最后登录：{{ detailUser.last_login_at || '从未登录' }}</p>
            <p>最后登录 IP：{{ detailUser.last_login_ip || '—' }}</p>
            <p>累计消费：<span class="font-semibold text-[#ff4d4f]">¥{{ detailUser.total_paid }}</span>（{{ detailUser.order_count }} 笔有效订单）</p>
          </div>

          <h3 class="mb-2 text-[13px] font-semibold text-slate-700">最近订单</h3>
          <table class="mb-4 w-full text-[13px]">
            <thead>
              <tr class="bg-slate-50 text-left text-slate-400">
                <th class="px-3 py-1.5 font-normal">订单号</th>
                <th class="px-3 py-1.5 font-normal">状态</th>
                <th class="px-3 py-1.5 font-normal">实付金额</th>
                <th class="px-3 py-1.5 font-normal">下单时间</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in detailUser.recent_orders" :key="order.id" class="border-t border-slate-50">
                <td class="px-3 py-1.5 font-mono text-slate-600">{{ order.order_no }}</td>
                <td class="px-3 py-1.5 text-slate-500">{{ order.status_label }}</td>
                <td class="px-3 py-1.5 text-slate-700">¥{{ order.pay_amount }}</td>
                <td class="px-3 py-1.5 text-slate-500">{{ order.created_at }}</td>
              </tr>
              <tr v-if="!detailUser.recent_orders.length">
                <td colspan="4" class="px-3 py-6 text-center text-slate-400">暂无订单</td>
              </tr>
            </tbody>
          </table>

          <h3 class="mb-2 text-[13px] font-semibold text-slate-700">收货地址</h3>
          <LoadingSpinner v-if="detailAddresses === null && !detailLoading" />
          <div v-else-if="detailAddresses !== null && !detailAddresses.length" class="px-3 py-4 text-center text-[13px] text-slate-400">
            该用户暂无收货地址
          </div>
          <div v-else-if="detailAddresses" class="space-y-2">
            <div
              v-for="addr in detailAddresses"
              :key="addr.id"
              class="flex items-start justify-between gap-3 rounded-lg border border-slate-100 p-2.5 text-[13px]"
            >
              <div class="min-w-0">
                <p class="text-black">
                  {{ addr.contact_name }}　<span class="font-mono">{{ addr.contact_phone }}</span>
                  <span v-if="addr.is_default" class="ml-1 rounded bg-[#e6f4ff] px-1.5 py-0.5 text-xs text-[#1677ff]">默认</span>
                </p>
                <p class="mt-0.5 truncate text-slate-500" :title="fullAddr(addr)">{{ fullAddr(addr) }}</p>
              </div>
              <button
                v-permission="'address.manage'"
                class="shrink-0 text-[#1677ff] hover:underline"
                @click="openAddrEdit(addr)"
              >代改</button>
            </div>
            <p class="text-xs text-slate-400">地址修改仅对用户后续下单生效，历史订单收货信息以下单快照为准。</p>
          </div>
        </template>
      </div>
    </div>

    <!-- 编辑弹窗 -->
    <div v-if="editTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="editTarget = null">
      <div class="w-full max-w-sm rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">编辑用户资料</h3>
        <p class="mt-1 text-xs text-slate-400">用户：{{ editTarget.nickname || editTarget.username }}（ID #{{ editTarget.id }}）</p>
        <div class="mt-4 space-y-3 text-[13px]">
          <label class="block">
            <span class="mb-1 block text-slate-500">昵称</span>
            <input
              v-model="editForm.nickname" type="text" maxlength="64" placeholder="用户昵称"
              class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-slate-500">手机号</span>
            <input
              v-model="editForm.phone" type="text" maxlength="20" placeholder="手机号"
              class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-slate-500">邮箱</span>
            <input
              v-model="editForm.email" type="email" maxlength="128" placeholder="邮箱"
              class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
            />
          </label>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="editTarget = null">取消</button>
          <button
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="saving"
            @click="doSave"
          >{{ saving ? '保存中…' : '保存' }}</button>
        </div>
      </div>
    </div>

    <!-- 地址代改弹窗（仅 address.manage 可见，入口按钮已由 v-permission 控制） -->
    <div v-if="addrEditTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="addrEditTarget = null">
      <div class="w-full max-w-md rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">代为修改收货地址</h3>
        <p class="mt-1 text-xs text-slate-400">地址 #{{ addrEditTarget.id }} · 修改仅对用户后续下单生效</p>
        <div class="mt-4 space-y-3 text-[13px]">
          <label class="block">
            <span class="mb-1 block text-slate-500">收货人 <span class="text-red-500">*</span></span>
            <input
              v-model="addrForm.contact_name" type="text" maxlength="64" placeholder="收货人姓名"
              class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-slate-500">手机号 <span class="text-red-500">*</span></span>
            <input
              v-model="addrForm.contact_phone" type="text" maxlength="11" placeholder="11 位手机号"
              class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
            />
          </label>
          <div class="grid grid-cols-3 gap-2">
            <label class="block">
              <span class="mb-1 block text-slate-500">省</span>
              <input
                v-model="addrForm.province" type="text" maxlength="64"
                class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">市</span>
              <input
                v-model="addrForm.city" type="text" maxlength="64"
                class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">区</span>
              <input
                v-model="addrForm.district" type="text" maxlength="64"
                class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              />
            </label>
          </div>
          <label class="block">
            <span class="mb-1 block text-slate-500">详细地址 <span class="text-red-500">*</span></span>
            <input
              v-model="addrForm.detail_address" type="text" maxlength="255" placeholder="街道、门牌号等"
              class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
            />
          </label>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="addrEditTarget = null">取消</button>
          <button
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="!addrFormValid || addrSaving"
            @click="requestAddrSave"
          >保存</button>
        </div>
      </div>
    </div>

    <!-- 禁用 / 启用确认 -->
    <ConfirmDialog
      :open="!!statusTarget"
      :title="statusTarget?.status === 1 ? '确认禁用该用户？' : '确认启用该用户？'"
      :message="statusTarget?.status === 1
        ? `禁用后用户 ${statusTarget?.username} 将无法登录，已登录会话立即下线。可在列表中重新启用。`
        : `启用后用户 ${statusTarget?.username} 可正常登录下单。`"
      :confirm-text="statusTarget?.status === 1 ? '确认禁用' : '确认启用'"
      :danger="statusTarget?.status === 1"
      @confirm="doToggleStatus"
      @cancel="statusTarget = null"
    />

    <!-- 地址代改确认：快照不可变提示 -->
    <ConfirmDialog
      :open="!!addrConfirm"
      title="确认代为修改该地址？"
      message="修改仅对用户后续下单生效；历史订单收货信息以下单时刻快照为准，不会变化。本次操作将记录操作日志。"
      confirm-text="确认修改"
      @confirm="doAddrSave"
      @cancel="addrConfirm = null"
    />
  </div>
</template>
