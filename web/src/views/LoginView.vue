<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Eye, EyeOff, Lock, Package, UserRound } from 'lucide-vue-next'
import {
  CAPTCHA_LENGTH, getCaptcha, getVerifyMode, login, loginBySmsCode, type Captcha,
} from '@/api/auth'
import { ApiBusinessError } from '@/api/request'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import SmsCodeField from '@/components/SmsCodeField.vue'

/**
 * 用户端登录页（与管理端同风格：蓝色系）
 *
 * 两种登录方式，由后端 `/auth/verify-mode` 决定默认选中哪个：
 * - 密码登录：始终可用（短信配不配都不受影响）；
 * - 短信验证码登录：后台配好短信且就绪时才出现，并作为默认选中（「优先走短信」）。
 *
 * ⚠️ 图形验证码在两种方式里都保留：密码登录作为人机校验，短信登录作为**发码的防刷闸门**。
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

const phone = ref('')
const smsCode = ref('')
const verifyMode = ref<'sms' | 'captcha'>('captcha')
const codeLength = ref(CAPTCHA_LENGTH)
const activeTab = ref<'password' | 'sms'>('password')

const smsAvailable = computed(() => verifyMode.value === 'sms')
const isSmsTab = computed(() => smsAvailable.value && activeTab.value === 'sms')

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

/** 拉取当前场景该用哪种验证码；失败不影响登录（退回密码 + 图形验证码） */
async function loadVerifyMode() {
  try {
    const { data } = await getVerifyMode('login')
    verifyMode.value = data.data.mode
    codeLength.value = data.data.code_length

    // 短信就绪时默认落在「验证码登录」（后台设了短信就优先走短信）
    if (data.data.mode === 'sms') {
      activeTab.value = 'sms'
    }
  } catch {
    verifyMode.value = 'captcha'
    codeLength.value = CAPTCHA_LENGTH
  }
}

/** 后端判定短信不可用时切回密码登录，保证登录流程不中断 */
async function onSmsFallback() {
  verifyMode.value = 'captcha'
  codeLength.value = CAPTCHA_LENGTH
  activeTab.value = 'password'
  await refreshCaptcha()
}

onMounted(async () => {
  await loadVerifyMode()
  await refreshCaptcha()
})

async function submitByPassword() {
  if (!username.value.trim() || !password.value || !captchaCode.value) {
    errorMsg.value = '请填写完整登录信息'
    return false
  }
  if (captchaCode.value.trim().length !== CAPTCHA_LENGTH) {
    errorMsg.value = `请输入 ${CAPTCHA_LENGTH} 位验证码`
    return false
  }
  if (!captcha.value) {
    errorMsg.value = '验证码未加载成功，请点击验证码图片刷新后再试'
    return false
  }

  const { data } = await login({
    username: username.value.trim(),
    password: password.value,
    captcha_id: captcha.value.captcha_id,
    captcha_code: captchaCode.value.trim().toUpperCase(),
  })

  return data.data.token
}

async function submitBySmsCode() {
  if (!/^1[3-9]\d{9}$/.test(phone.value.trim())) {
    errorMsg.value = '请输入正确的手机号'
    return false
  }
  if (smsCode.value.trim().length !== codeLength.value) {
    errorMsg.value = `请输入 ${codeLength.value} 位短信验证码`
    return false
  }

  const { data } = await loginBySmsCode({
    phone: phone.value.trim(),
    sms_code: smsCode.value.trim(),
  })

  return data.data.token
}

async function submit() {
  loading.value = true
  errorMsg.value = ''
  try {
    const token = isSmsTab.value ? await submitBySmsCode() : await submitByPassword()

    if (token === false) {
      return
    }

    auth.setToken(token)
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
    if (!isSmsTab.value) {
      await refreshCaptcha()
    }
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

      <div v-if="smsAvailable" class="mb-5 grid grid-cols-2 gap-2 rounded-lg bg-slate-100 p-1" data-testid="login-tabs">
        <button
          type="button" data-testid="login-tab-password"
          class="h-9 rounded-md text-sm transition-colors"
          :class="!isSmsTab ? 'bg-white font-medium text-slate-800 shadow-sm' : 'text-slate-500'"
          @click="activeTab = 'password'"
        >密码登录</button>
        <button
          type="button" data-testid="login-tab-sms"
          class="h-9 rounded-md text-sm transition-colors"
          :class="isSmsTab ? 'bg-white font-medium text-slate-800 shadow-sm' : 'text-slate-500'"
          @click="activeTab = 'sms'"
        >验证码登录</button>
      </div>

      <div class="space-y-4 text-sm">
        <SmsCodeField
          v-if="isSmsTab"
          v-model:phone="phone"
          v-model:code="smsCode"
          scene="login"
          :code-length="codeLength"
          data-testid="login-sms-field"
          @fallback="onSmsFallback"
        />

        <template v-else>
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
              v-model="captchaCode" type="text" :maxlength="CAPTCHA_LENGTH" placeholder="验证码"
              class="h-11 w-36 rounded-lg border border-slate-200 px-3 text-center tracking-widest outline-none focus:border-[#1677ff]"
              @keyup.enter="submit"
            />
            <button
              type="button" data-testid="login-captcha-image"
              class="flex h-11 w-[140px] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-50 hover:opacity-80"
              title="点击刷新验证码"
              @click="refreshCaptcha"
            >
              <img v-if="captcha" :src="captcha.image" alt="验证码" class="h-full w-auto" />
              <span v-else class="text-xs text-slate-400">加载中...</span>
            </button>
          </div>
        </template>

        <p v-if="errorMsg" data-testid="login-error" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ errorMsg }}</p>

        <button
          class="h-11 w-full rounded-lg bg-[#1677ff] font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-60"
          data-testid="login-submit"
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
