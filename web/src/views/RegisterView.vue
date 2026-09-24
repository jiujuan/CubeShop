<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Package } from 'lucide-vue-next'
import {
  CAPTCHA_LENGTH, SMS_CODE_LENGTH, getCaptcha, getVerifyMode, register, type Captcha,
} from '@/api/auth'
import { ApiBusinessError } from '@/api/request'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import SmsCodeField from '@/components/SmsCodeField.vue'

/**
 * 用户端注册页
 *
 * 密码规则与后端保持一致（SEC-05：≥8 位且同时含字母与数字 + 弱口令黑名单），
 * 提交前先本地拦截，避免用户只看到一句「参数校验失败」却不知道错在哪个字段。
 *
 * 验证码有两种形态，由后端 `/auth/verify-mode` 决定（后台配好短信即走短信验证码）：
 * - 图形验证码（5 位）：默认形态，也是**发送短信验证码的防刷闸门**，不会被移除；
 * - 短信验证码（6 位）：后台启用且就绪时使用，位数由后端下发，不写死。
 */
const router = useRouter()
const auth = useAuthStore()
const site = useSiteStore()

/** ≥8 位，且同时含字母与数字 */
const PASSWORD_RULE = /^(?=.*[A-Za-z])(?=.*\d).{8,}$/

/** 后端 422 字段名 → 展示优先级（验证码相关排前，避免用户误判） */
const ERROR_FIELD_ORDER = ['sms_code', 'code', 'phone', 'captcha_id', 'password', 'account', 'username', 'email']

const username = ref('')
const password = ref('')
const confirm = ref('')
const phone = ref('')
const captchaCode = ref('')
const captcha = ref<Captcha | null>(null)
const smsCode = ref('')
const verifyMode = ref<'sms' | 'captcha'>('captcha')
const codeLength = ref(CAPTCHA_LENGTH)
const loading = ref(false)
const errorMsg = ref('')

const isSmsMode = computed(() => verifyMode.value === 'sms')

async function refreshCaptcha() {
  captchaCode.value = ''
  try {
    const { data } = await getCaptcha()
    captcha.value = data.data
  } catch (e) {
    // 验证码接口本身失败（含 429 限流）时置空并提示，避免用户拿着空 captcha_id 提交
    captcha.value = null
    errorMsg.value = e instanceof Error ? e.message : '验证码加载失败，请点击图片重试'
  }
}

/** 拉取当前场景该用哪种验证码；失败不阻断注册（默认退回图形验证码） */
async function loadVerifyMode() {
  try {
    const { data } = await getVerifyMode('register')
    verifyMode.value = data.data.mode
    codeLength.value = data.data.code_length
  } catch {
    verifyMode.value = 'captcha'
    codeLength.value = CAPTCHA_LENGTH
  }
}

/** 后端判定短信不可用时切回图形验证码，保证注册流程不中断 */
async function onSmsFallback() {
  verifyMode.value = 'captcha'
  codeLength.value = CAPTCHA_LENGTH
  await refreshCaptcha()
}

onMounted(async () => {
  await loadVerifyMode()

  // 短信模式下图形验证码由 SmsCodeField 自带（用于发码防刷），这里不必再取一张
  if (!isSmsMode.value) {
    await refreshCaptcha()
  }
})

/** 取后端字段级错误的第一条（按字段优先级），没有则回退整体 message */
function firstFieldError(errors?: Record<string, string[]>): string | null {
  if (!errors) return null
  for (const field of ERROR_FIELD_ORDER) {
    const msg = errors[field]?.[0]
    if (msg) return msg
  }
  const rest = Object.values(errors).find((list) => list?.length)
  return rest?.[0] ?? null
}

async function submit() {
  if (!username.value.trim() || !password.value) {
    errorMsg.value = '请填写完整注册信息'
    return
  }
  if (password.value !== confirm.value) {
    errorMsg.value = '两次输入的密码不一致'
    return
  }
  if (!PASSWORD_RULE.test(password.value)) {
    errorMsg.value = '密码至少 8 位，且需同时包含字母和数字'
    return
  }

  const payload: Record<string, string> = {
    username: username.value.trim(),
    password: password.value,
    password_confirmation: confirm.value,
  }

  if (isSmsMode.value) {
    if (!/^1[3-9]\d{9}$/.test(phone.value.trim())) {
      errorMsg.value = '请输入正确的手机号'
      return
    }
    if (smsCode.value.trim().length !== codeLength.value) {
      errorMsg.value = `请输入 ${codeLength.value} 位短信验证码`
      return
    }
    payload.phone = phone.value.trim()
    payload.sms_code = smsCode.value.trim()
  } else {
    if (captchaCode.value.trim().length !== CAPTCHA_LENGTH) {
      errorMsg.value = `请输入 ${CAPTCHA_LENGTH} 位验证码`
      return
    }
    if (!captcha.value) {
      errorMsg.value = '验证码未加载成功，请点击验证码图片刷新后再试'
      return
    }
    payload.captcha_id = captcha.value.captcha_id
    payload.code = captchaCode.value.trim().toUpperCase()
  }

  loading.value = true
  errorMsg.value = ''
  try {
    const { data } = await register(payload)
    auth.setToken(data.data.token)
    await auth.fetchUser().catch(() => null)
    router.replace('/')
  } catch (e) {
    if (e instanceof ApiBusinessError) {
      // 422 时 message 只是「参数校验失败」，真正的字段原因在 errors 里
      errorMsg.value = firstFieldError(e.errors) ?? e.message
    } else {
      errorMsg.value = e instanceof Error ? e.message : '注册失败'
    }
    if (!isSmsMode.value) {
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
        data-testid="register-site-logo"
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
      <h1 class="mb-6 text-center text-lg font-semibold text-slate-800">注册账号</h1>

      <div class="space-y-4 text-sm">
        <input
          v-model="username" type="text" placeholder="用户名 / 手机号"
          class="h-11 w-full rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]"
        />
        <input
          v-model="password" type="password" placeholder="密码（≥8 位，含字母和数字）"
          class="h-11 w-full rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]"
        />
        <input
          v-model="confirm" type="password" placeholder="确认密码"
          class="h-11 w-full rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]"
        />

        <SmsCodeField
          v-if="isSmsMode"
          v-model:phone="phone"
          v-model:code="smsCode"
          scene="register"
          :code-length="codeLength"
          data-testid="register-sms-field"
          @fallback="onSmsFallback"
        />

        <div v-else class="flex gap-2">
          <input
            v-model="captchaCode" type="text" :maxlength="CAPTCHA_LENGTH" placeholder="验证码"
            data-testid="register-captcha-code"
            class="h-11 w-36 rounded-lg border border-slate-200 px-3 text-center tracking-widest outline-none focus:border-[#1677ff]"
          />
          <button
            type="button" data-testid="register-captcha-image"
            class="flex h-11 w-[140px] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-50 hover:opacity-80"
            @click="refreshCaptcha"
          >
            <img v-if="captcha" :src="captcha.image" alt="验证码" class="h-full w-auto" />
            <span v-else class="text-xs text-slate-400">加载中...</span>
          </button>
        </div>

        <p v-if="errorMsg" data-testid="register-error" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ errorMsg }}</p>

        <button
          class="h-11 w-full rounded-lg bg-[#1677ff] font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-60"
          data-testid="register-submit"
          :disabled="loading"
          @click="submit"
        >{{ loading ? '注册中...' : '注 册' }}</button>
      </div>

      <p class="mt-4 text-center text-xs text-slate-400">
        已有账号？
        <RouterLink to="/login" class="text-[#1677ff] hover:underline">去登录</RouterLink>
      </p>
    </div>
  </div>
</template>
