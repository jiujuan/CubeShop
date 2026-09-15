<script setup lang="ts">
import { reactive, ref } from 'vue'
import { KeyRound, SquareUser } from 'lucide-vue-next'
import { changePassword } from '@/api/auth'
import { getProfile, updateProfile } from '@/api/admin'
import { useAuthStore } from '@/stores/auth'

/**
 * 个人中心：基础信息展示与修改 + 修改密码（路线图 P1 前端任务）
 */
const auth = useAuthStore()
const toast = ref('')
const saving = ref(false)

const profile = ref<Partial<import('@/api/admin').Profile>>({})
const form = reactive({
  nickname: '',
  phone: '',
  email: '',
})

const pwdForm = reactive({
  old_password: '',
  password: '',
  password_confirmation: '',
})

async function load() {
  const res = await getProfile()
  profile.value = res.data.data
  form.nickname = res.data.data.nickname || ''
  form.phone = res.data.data.phone || ''
  form.email = res.data.data.email || ''
}

load()

async function saveProfile() {
  saving.value = true
  toast.value = ''
  try {
    await updateProfile({ ...form })
    await auth.fetchUser()
    toast.value = '资料已更新'
    await load()
  } catch (e) {
    toast.value = e instanceof Error ? e.message : '更新失败'
  } finally {
    saving.value = false
  }
}

async function savePassword() {
  if (pwdForm.password !== pwdForm.password_confirmation) {
    toast.value = '两次输入的新密码不一致'
    return
  }
  saving.value = true
  toast.value = ''
  try {
    await changePassword({ ...pwdForm })
    toast.value = '密码已修改，其他设备需重新登录'
    pwdForm.old_password = pwdForm.password = pwdForm.password_confirmation = ''
  } catch (e) {
    toast.value = e instanceof Error ? e.message : '修改失败'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-lg font-semibold">个人中心</h1>

    <p v-if="toast" class="rounded bg-blue-50 px-3 py-2 text-[13px] text-[#1677ff]">{{ toast }}</p>

    <!-- 基础信息 -->
    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-3 flex items-center gap-2 text-sm font-medium">
        <SquareUser class="h-4 w-4 text-[#1677ff]" />
        基础信息
      </h2>
      <div class="mb-4 grid grid-cols-2 gap-2 text-[13px] text-slate-500">
        <div>用户名：<span class="text-slate-700">{{ profile.username }}</span></div>
        <div>角色：<span class="text-slate-700">{{ profile.roles?.join('、') }}</span></div>
        <div>最近登录：{{ profile.last_login_at || '-' }}</div>
        <div>登录 IP：{{ profile.last_login_ip || '-' }}</div>
      </div>

      <form class="grid grid-cols-1 gap-3 sm:grid-cols-3" @submit.prevent="saveProfile">
        <label class="text-[13px] text-slate-500">
          昵称
          <input v-model="form.nickname" type="text" class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff]" />
        </label>
        <label class="text-[13px] text-slate-500">
          手机号
          <input v-model="form.phone" type="tel" class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff]" />
        </label>
        <label class="text-[13px] text-slate-500">
          邮箱
          <input v-model="form.email" type="email" class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff]" />
        </label>
        <div class="sm:col-span-3">
          <button type="submit" :disabled="saving" class="h-8 rounded bg-[#1677ff] px-4 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-60">保存资料</button>
        </div>
      </form>
    </section>

    <!-- 修改密码 -->
    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-3 flex items-center gap-2 text-sm font-medium">
        <KeyRound class="h-4 w-4 text-[#1677ff]" />
        修改密码
      </h2>
      <form class="grid grid-cols-1 gap-3 sm:grid-cols-3" @submit.prevent="savePassword">
        <label class="text-[13px] text-slate-500">
          原密码
          <input v-model="pwdForm.old_password" type="password" autocomplete="current-password" class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff]" />
        </label>
        <label class="text-[13px] text-slate-500">
          新密码
          <input v-model="pwdForm.password" type="password" autocomplete="new-password" class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff]" />
        </label>
        <label class="text-[13px] text-slate-500">
          确认新密码
          <input v-model="pwdForm.password_confirmation" type="password" autocomplete="new-password" class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff]" />
        </label>
        <div class="sm:col-span-3">
          <button type="submit" :disabled="saving" class="h-8 rounded bg-[#1677ff] px-4 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-60">修改密码</button>
        </div>
      </form>
    </section>
  </div>
</template>
