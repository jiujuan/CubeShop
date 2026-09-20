<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Eye, EyeOff, Lock, Package, UserRound } from 'lucide-vue-next'
import { CAPTCHA_LENGTH, getCaptcha, login, type Captcha } from '@/api/auth'
import { ApiBusinessError } from '@/api/request'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

/**
 * 用户端登录页（与管理端同风格：蓝色系）
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const site = useSiteStore()

const username = ref('')
const password = ref('')
const captchaCode = ref('')
const captcha = ref<Captcha | null>(null)
const showPassword = ref(false)
const loading = ref(false)
const errorMsg = ref('')

async function refreshCaptcha() {
  captchaCode.value = ''
  try {
    const { data } = await getCaptcha()
    captcha.value = data.data
  } catch (e) {
    // 验证码接口失败（含 429 限流）时置空并提示，避免用户拿着空 captcha_id 提交
    captcha.value = null
    errorMsg.value = e instanceof Error ? e.message : '验证码加载失败，请点击图片重试'
  }
}

onMounted(refreshCaptcha)

async function submit() {
  if (!username.value.trim() || !password.value || !captchaCode.value) {
    errorMsg.value = '请填写完整登录信息'
    return
  }
  if (captchaCode.value.trim().length !== CAPTCHA_LENGTH) {
    errorMsg.value = `请输入 ${CAPTCHA_LENGTH} 位验证码`
    return
  }
  if (!captcha.value) {
    errorMsg.value = '验证码未加载成功，请点击验证码图片刷新后再试'
    return
  }
  loading.value = true
  errorMsg.value = ''
  try {
    const { data } = await login({
      username: username.value.trim(),
      password: password.value,
      captcha_id: captcha.value.captcha_id,
      captcha_code: captchaCode.value.trim().toUpperCase(),
    })
    auth.setToken(data.data.token)
    await auth.fetchUser().catch(() => null)
    router.replace((route.query.redirect as string) || '/')
  } catch (e) {
    if (e instanceof ApiBusinessError) {
      // 422 时 message 只是「参数校验失败」，真正原因在 errors 里
      const first = Object.values(e.errors ?? {}).find((list) => list?.length)
      errorMsg.value = first?.[0] ?? e.message
    } else {
      errorMsg.value = e instanceof Error ? e.message : '登录失败'
    }
    await refreshCaptcha()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-[#e6f4ff] via-[#f5faff] to-white px-4">
    <div class="mb-6 flex items-center gap-3">
      <img
        v-if="site.brandLogo"
        :src="site.brandLogo"
        alt="站点 logo"
        class="h-12 object-contain"
        data-testid="login-site-logo"
      />
      <span v-else class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#1677ff]">
        <Package class="h-7 w-7 text-white" />
      </span>
      <div>
        <div class="text-2xl font-bold text-slate-800">{{ site.name }}</div>
        <div class="text-xs text-slate-400">品质好物 · 购物无</div>
      </div>
    </div>

    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-lg">
      <h1 class="mb-6 text-center text-lg font-semibold text-slate-800">账号登录</h1>

      <div class="space-y-4 text-sm">
        <div class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 focus-within:border-[#1677ff]">
          <UserRound class="h-4 w-4 text-slate-400" />
          <input v-model="username" type="text" placeholder="用户名 / 手机号" class="h-11 flex-1 outline-none" @keyup.enter="submit" />
        </div>
        <div class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 focus-within:border-[#1677ff]">
          <Lock class="h-4 w-4 text-slate-400" />
          <input
            v-model="password" :type="showPassword ? 'text' : 'password'" placeholder="密码"
            class="h-11 flex-1 outline-none" @keyup.enter="submit"
          />
          <button class="text-slate-400 hover:text-slate-600" @click="showPassword = !showPassword">
            <EyeOff v-if="showPassword" class="h-4 w-4" />
            <Eye v-else class="h-4 w-4" />
          </button>
        </div>
        <div class="flex gap-2">
          <input
            v-model="captchaCode" type="text" maxlength="5" placeholder="验证码"
            class="h-11 w-36 rounded-lg border border-slate-200 px-3 text-center tracking-widest outline-none focus:border-[#1677ff]"
            @keyup.enter="submit"
          />
          <button
            class="flex h-11 w-[140px] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-50 hover:opacity-80"
            title="点击刷新验证码"
            @click="refreshCaptcha"
          >
            <img v-if="captcha" :src="captcha.image" alt="验证码" class="h-full w-auto" />
            <span v-else class="text-xs text-slate-400">加载中...</span>
          </button>
        </div>

        <p v-if="errorMsg" data-testid="login-error" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ errorMsg }}</p>

        <button
          class="h-11 w-full rounded-lg bg-[#1677ff] font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-60"
          :disabled="loading"
          @click="submit"
        >{{ loading ? '登录中...' : '登 录' }}</button>
      </div>

      <p class="mt-4 text-center text-xs text-slate-400">
        还没有账号？
        <RouterLink to="/register" class="text-[#1677ff] hover:underline">立即注册</RouterLink>
        <span class="mx-1 text-slate-200">|</span>
        <RouterLink to="/" class="text-slate-400 hover:underline">返回首页</RouterLink>
      </p>
    </div>
  </div>
</template>
