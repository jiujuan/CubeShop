<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Eye, EyeOff, Lock, RefreshCw, ShieldCheck, SquareUser, Store } from 'lucide-vue-next'
import { getCaptcha, register, type Captcha } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

/**
 * 注册页：与登录页同风格；验证码 V1.0 用图形验证码降级（无短信网关）
 */
const router = useRouter()
const auth = useAuthStore()

const form = ref({
  username: '',
  phone: '',
  password: '',
  password_confirmation: '',
  captcha_code: '',
})
const showPassword = ref(false)
const loading = ref(false)
const captcha = ref<Captcha | null>(null)
const errorMsg = ref('')

async function refreshCaptcha() {
  try {
    const res = await getCaptcha()
    captcha.value = res.data.data
  } catch {
    errorMsg.value = '获取验证码失败，请检查服务是否可用'
  }
}

refreshCaptcha()

async function handleRegister() {
  const f = form.value
  if (!f.username || !f.password) {
    errorMsg.value = '请输入用户名和密码'
    return
  }
  if (f.password !== f.password_confirmation) {
    errorMsg.value = '两次输入的密码不一致'
    return
  }
  if (!f.captcha_code || !captcha.value) {
    errorMsg.value = '请输入验证码'
    return
  }

  loading.value = true
  errorMsg.value = ''
  try {
    const res = await register({
      username: f.username,
      password: f.password,
      password_confirmation: f.password_confirmation,
      phone: f.phone || undefined,
      captcha_id: captcha.value.captcha_id,
      code: f.captcha_code,
    })
    auth.setToken(res.data.data.token)
    router.replace('/dashboard')
  } catch (e) {
    errorMsg.value = e instanceof Error && e.message ? e.message : '注册失败，请稍后重试'
    form.value.captcha_code = ''
    await refreshCaptcha()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-br from-[#e8f3ff] via-[#f4f9ff] to-[#dbeafe] p-4">
    <div class="pointer-events-none absolute inset-0 opacity-40" aria-hidden="true">
      <div class="dot-grid absolute left-[8%] top-[12%]" />
      <div class="dot-grid absolute right-[10%] top-[18%]" />
      <div class="dot-grid absolute bottom-[10%] left-[12%]" />
      <div class="dot-grid absolute bottom-[14%] right-[8%]" />
    </div>

    <div class="relative w-full max-w-md rounded-2xl bg-white px-10 py-9 shadow-[0_12px_40px_rgba(22,119,255,0.12)]">
      <div class="mb-7 flex items-center justify-center gap-2.5">
        <Store class="h-10 w-10 text-[#1677ff]" />
        <div>
          <div class="text-3xl font-bold leading-none text-[#002c8c]">CubeShop</div>
          <div class="mt-1 text-center text-sm text-slate-500">创建账号</div>
        </div>
      </div>

      <form class="space-y-4" @submit.prevent="handleRegister">
        <div>
          <label class="mb-1.5 block text-sm text-slate-600">用户名</label>
          <div class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 transition-colors focus-within:border-[#1677ff]">
            <SquareUser class="h-4.5 w-4.5 shrink-0 text-slate-400" />
            <input v-model="form.username" type="text" placeholder="用户名或手机号" class="h-10 w-full bg-transparent text-sm outline-none placeholder:text-slate-300" />
          </div>
        </div>

        <div>
          <label class="mb-1.5 block text-sm text-slate-600">手机号（选填）</label>
          <input v-model="form.phone" type="tel" maxlength="20" placeholder="请输入手机号" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none transition-colors focus:border-[#1677ff] placeholder:text-slate-300" />
        </div>

        <div>
          <label class="mb-1.5 block text-sm text-slate-600">密码</label>
          <div class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 transition-colors focus-within:border-[#1677ff]">
            <Lock class="h-4.5 w-4.5 shrink-0 text-slate-400" />
            <input v-model="form.password" :type="showPassword ? 'text' : 'password'" placeholder="至少 6 位" autocomplete="new-password" class="h-10 w-full bg-transparent text-sm outline-none placeholder:text-slate-300" />
            <button type="button" tabindex="-1" class="text-slate-400 hover:text-[#1677ff]" @click="showPassword = !showPassword">
              <EyeOff v-if="showPassword" class="h-4.5 w-4.5" />
              <Eye v-else class="h-4.5 w-4.5" />
            </button>
          </div>
        </div>

        <div>
          <label class="mb-1.5 block text-sm text-slate-600">确认密码</label>
          <input v-model="form.password_confirmation" type="password" placeholder="再次输入密码" autocomplete="new-password" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none transition-colors focus:border-[#1677ff] placeholder:text-slate-300" />
        </div>

        <div>
          <label class="mb-1.5 block text-sm text-slate-600">验证码</label>
          <div class="flex gap-2">
            <div class="flex flex-1 items-center gap-2 rounded-lg border border-slate-200 px-3 transition-colors focus-within:border-[#1677ff]">
              <ShieldCheck class="h-4.5 w-4.5 shrink-0 text-slate-400" />
              <input v-model="form.captcha_code" type="text" placeholder="请输入验证码" maxlength="4" class="h-10 w-full bg-transparent text-sm outline-none placeholder:text-slate-300" />
            </div>
            <button type="button" title="点击刷新验证码" class="relative h-10 w-[120px] shrink-0 overflow-hidden rounded-lg border border-dashed border-slate-300 bg-slate-50 hover:border-[#1677ff]" @click="refreshCaptcha">
              <img v-if="captcha" :src="captcha.image" alt="验证码" class="h-full w-full" />
              <RefreshCw v-else class="absolute inset-0 m-auto h-4 w-4 animate-spin text-slate-400" />
            </button>
          </div>
        </div>

        <p v-if="errorMsg" class="rounded bg-red-50 px-3 py-2 text-[13px] text-red-500">{{ errorMsg }}</p>

        <button type="submit" :disabled="loading" class="h-11 w-full rounded-lg bg-[#1677ff] text-base font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-60">
          {{ loading ? '注册中…' : '注　册' }}
        </button>

        <p class="text-center text-sm text-slate-500">
          已有账号？
          <RouterLink to="/login" class="text-[#1677ff] hover:underline">去登录</RouterLink>
        </p>
      </form>
    </div>

    <p class="absolute bottom-6 w-full text-center text-sm text-slate-400">CubeShop v1.0 · 内部管理系统</p>
  </div>
</template>

<style scoped>
.dot-grid {
  width: 80px;
  height: 80px;
  background-image: radial-gradient(circle, #bfdbfe 1.5px, transparent 1.5px);
  background-size: 12px 12px;
}
</style>
