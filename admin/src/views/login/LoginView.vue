<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Eye, EyeOff, Lock, RefreshCw, ShieldCheck, SquareUser, Store } from 'lucide-vue-next'
import { CAPTCHA_LENGTH, getCaptcha, login, type Captcha } from '@/api/auth'
import { landingPath } from '@/router'
import { useAuthStore } from '@/stores/auth'

/**
 * 登录页（按产品原型：CubeShop 电商管理后台）
 * 用户名/手机号 + 密码 + 图形验证码 + 记住我 + 忘记密码
 */
const router = useRouter()
const auth = useAuthStore()

const form = ref({
  username: localStorage.getItem('cubeshop_last_username') || '',
  password: '',
  captcha_code: '',
})
const showPassword = ref(false)
const rememberMe = ref(false)
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

async function handleLogin() {
  if (!form.value.username || !form.value.password) {
    errorMsg.value = '请输入用户名和密码'
    return
  }
  if (!form.value.captcha_code) {
    errorMsg.value = '请输入验证码'
    return
  }
  if (form.value.captcha_code.trim().length !== CAPTCHA_LENGTH) {
    errorMsg.value = `请输入 ${CAPTCHA_LENGTH} 位验证码`
    return
  }
  if (!captcha.value) {
    errorMsg.value = '验证码未加载，请点击刷新'
    return
  }

  loading.value = true
  errorMsg.value = ''
  try {
    const res = await login({
      username: form.value.username,
      password: form.value.password,
      captcha_id: captcha.value.captcha_id,
      captcha_code: form.value.captcha_code,
    })

    auth.setToken(res.data.data.token, rememberMe.value)
    if (rememberMe.value) {
      localStorage.setItem('cubeshop_last_username', form.value.username)
    } else {
      localStorage.removeItem('cubeshop_last_username')
    }

    const redirect = router.currentRoute.value.query.redirect as string | undefined
    // 兜底落地页按当前账号权限计算（客服无 dashboard.view，登录后直接进客服工作台）
    router.replace(redirect || landingPath())
  } catch (e) {
    errorMsg.value = e instanceof Error && e.message ? e.message : '登录失败，请稍后重试'
    form.value.captcha_code = ''
    await refreshCaptcha()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-br from-[#e8f3ff] via-[#f4f9ff] to-[#dbeafe] p-4">
    <!-- 装饰点阵 -->
    <div class="pointer-events-none absolute inset-0 opacity-40" aria-hidden="true">
      <div class="dot-grid absolute left-[8%] top-[12%]" />
      <div class="dot-grid absolute right-[10%] top-[18%]" />
      <div class="dot-grid absolute bottom-[10%] left-[12%]" />
      <div class="dot-grid absolute bottom-[14%] right-[8%]" />
      <div class="absolute -left-32 top-1/3 h-96 w-96 rounded-full bg-[#dbeafe]/60 blur-2xl" />
      <div class="absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-[#e0edff]/50 blur-2xl" />
    </div>

    <!-- 登录卡片 -->
    <div class="relative w-full max-w-md rounded-2xl bg-white px-10 py-9 shadow-[0_12px_40px_rgba(22,119,255,0.12)]">
      <!-- Logo -->
      <div class="mb-7 flex items-center justify-center gap-2.5">
        <Store class="h-10 w-10 text-[#1677ff]" />
        <div>
          <div class="text-3xl font-bold leading-none text-[#002c8c]">CubeShop</div>
          <div class="mt-1 text-center text-sm text-slate-500">电商管理后台</div>
        </div>
      </div>

      <form class="space-y-4" @submit.prevent="handleLogin">
        <!-- 用户名 / 手机号 -->
        <div>
          <label class="mb-1.5 block text-sm text-slate-600">用户名 / 手机号</label>
          <div class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 transition-colors focus-within:border-[#1677ff]">
            <SquareUser class="h-4.5 w-4.5 shrink-0 text-slate-400" />
            <input
              v-model="form.username"
              type="text"
              placeholder="请输入用户名或手机号"
              autocomplete="username"
              class="h-10 w-full bg-transparent text-sm outline-none placeholder:text-slate-300"
            />
          </div>
        </div>

        <!-- 密码 -->
        <div>
          <label class="mb-1.5 block text-sm text-slate-600">密码</label>
          <div class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 transition-colors focus-within:border-[#1677ff]">
            <Lock class="h-4.5 w-4.5 shrink-0 text-slate-400" />
            <input
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="请输入密码"
              autocomplete="current-password"
              class="h-10 w-full bg-transparent text-sm outline-none placeholder:text-slate-300"
            />
            <button type="button" tabindex="-1" class="text-slate-400 hover:text-[#1677ff]" @click="showPassword = !showPassword">
              <EyeOff v-if="showPassword" class="h-4.5 w-4.5" />
              <Eye v-else class="h-4.5 w-4.5" />
            </button>
          </div>
        </div>

        <!-- 验证码 -->
        <div>
          <label class="mb-1.5 block text-sm text-slate-600">验证码</label>
          <div class="flex gap-2">
            <div class="flex flex-1 items-center gap-2 rounded-lg border border-slate-200 px-3 transition-colors focus-within:border-[#1677ff]">
              <ShieldCheck class="h-4.5 w-4.5 shrink-0 text-slate-400" />
              <input
                v-model="form.captcha_code"
                type="text"
                placeholder="请输入验证码"
                maxlength="5"
                class="h-10 w-full bg-transparent text-sm outline-none placeholder:text-slate-300"
                @keyup.enter="handleLogin"
              />
            </div>
            <button
              type="button"
              title="点击刷新验证码"
              class="relative h-10 w-[140px] shrink-0 overflow-hidden rounded-lg border border-dashed border-slate-300 bg-slate-50 transition-colors hover:border-[#1677ff]"
              @click="refreshCaptcha"
            >
              <img v-if="captcha" :src="captcha.image" alt="验证码" class="h-full w-full" />
              <RefreshCw v-else class="absolute inset-0 m-auto h-4 w-4 animate-spin text-slate-400" />
            </button>
          </div>
        </div>

        <!-- 记住我 / 忘记密码 -->
        <div class="flex items-center justify-between text-sm">
          <label class="flex cursor-pointer items-center gap-1.5 text-slate-600">
            <input v-model="rememberMe" type="checkbox" class="h-4 w-4 accent-[#1677ff]" />
            记住我
          </label>
          <a href="javascript:void(0)" class="text-[#1677ff] hover:underline">忘记密码？</a>
        </div>

        <!-- 错误提示 -->
        <p v-if="errorMsg" class="rounded bg-red-50 px-3 py-2 text-[13px] text-red-500">{{ errorMsg }}</p>

        <!-- 登录按钮 -->
        <button
          type="submit"
          :disabled="loading"
          class="h-11 w-full rounded-lg bg-[#1677ff] text-base font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-60"
        >
          {{ loading ? '登录中…' : '登　录' }}
        </button>

        <p class="text-center text-sm text-slate-500">
          还没有账号？
          <RouterLink to="/register" class="text-[#1677ff] hover:underline">立即注册</RouterLink>
        </p>
      </form>
    </div>

    <!-- 页脚 -->
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
