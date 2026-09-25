<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { CAPTCHA_LENGTH, getCaptcha, sendSmsCode, type Captcha, type SmsScene } from '@/api/auth'
import { MessageSquareCode, Phone, ShieldCheck } from 'lucide-vue-next'

/**
 * 短信验证码字段（注册 / 登录 / 重置密码共用）
 *
 * 三件事都收在这里，避免三个页面各写一遍、行为漂移：
 * 1. **手机号 + 短信验证码**输入；
 * 2. **图形验证码**（发码的防刷闸门，短信按条计费，不能省）；
 * 3. **发送 + 60 秒倒计时**，失败时刷新图形码。
 *
 * ⚠️ 后端在系统不就绪（没配短信 / 没配模板 / 无渠道）时会返回 `sent: false` 而不是报错，
 *    此时抛出 `fallback` 事件让父级切回图形验证码——注册登录不能被配置问题打断。
 */
const props = defineProps<{
  scene: SmsScene
  phone: string
  code: string
  /** 短信验证码位数（由后端 verify-mode 下发，图形码是 5 位、短信码是 6 位） */
  codeLength: number
}>()

const emit = defineEmits<{
  'update:phone': [string]
  'update:code': [string]
  fallback: []
}>()

const captcha = ref<Captcha | null>(null)
const captchaCode = ref('')
const sending = ref(false)
const countdown = ref(0)
const errorMsg = ref('')
let timer: number | undefined

const phoneValue = computed({
  get: () => props.phone,
  set: (v: string) => emit('update:phone', v),
})

const codeValue = computed({
  get: () => props.code,
  set: (v: string) => emit('update:code', v),
})

const sendLabel = computed(() => {
  if (countdown.value > 0) return `${countdown.value} 秒后重发`
  return sending.value ? '发送中...' : '发送验证码'
})

async function refreshCaptcha() {
  captchaCode.value = ''
  try {
    const { data } = await getCaptcha()
    captcha.value = data.data
  } catch {
    captcha.value = null
  }
}

function startCountdown(seconds: number) {
  countdown.value = seconds
  window.clearInterval(timer)
  timer = window.setInterval(() => {
    if (countdown.value <= 1) {
      countdown.value = 0
      window.clearInterval(timer)
      return
    }
    countdown.value -= 1
  }, 1000)
}

async function send() {
  if (!/^1[3-9]\d{9}$/.test(phoneValue.value.trim())) {
    errorMsg.value = '请输入正确的手机号'
    return
  }
  if (captchaCode.value.trim().length !== CAPTCHA_LENGTH) {
    errorMsg.value = `请输入 ${CAPTCHA_LENGTH} 位图形验证码`
    return
  }
  if (!captcha.value) {
    errorMsg.value = '图形验证码未加载成功，请点击图片刷新后再试'
    return
  }

  sending.value = true
  errorMsg.value = ''

  try {
    const { data } = await sendSmsCode({
      scene: props.scene,
      phone: phoneValue.value.trim(),
      captcha_id: captcha.value.captcha_id,
      captcha_code: captchaCode.value.trim().toUpperCase(),
    })

    const res = data.data

    if (!res.sent) {
      // 后端判定短信不可用：切回图形验证码，并把这个原因如实告诉用户
      emit('fallback')
      errorMsg.value = '短信验证码暂不可用，请改用图形验证码'
      return
    }

    startCountdown(res.resend_after ?? 60)
    await refreshCaptcha()
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '验证码发送失败，请稍后再试'
    await refreshCaptcha()
  } finally {
    sending.value = false
  }
}

onMounted(refreshCaptcha)
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<template>
  <div class="space-y-4" data-testid="sms-code-field">
    <div class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 focus-within:border-[#1677ff]">
      <Phone class="h-4 w-4 text-slate-400" />
      <input
        v-model="phoneValue" type="tel" maxlength="11" placeholder="手机号"
        data-testid="sms-phone"
        class="h-11 flex-1 outline-none"
      />
    </div>

    <div class="flex gap-2">
      <div class="flex h-11 w-28 items-center gap-2 rounded-lg border border-slate-200 px-3 focus-within:border-[#1677ff]">
        <ShieldCheck class="h-4 w-4 text-slate-400" />
        <input
          v-model="captchaCode" type="text" :maxlength="CAPTCHA_LENGTH" placeholder="图形验证码"
          data-testid="sms-captcha-code"
          class="w-full flex-1 text-center tracking-widest outline-none"
        />
      </div>
      <button
        type="button" data-testid="sms-captcha-image"
        class="flex h-11 w-[120px] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-50 hover:opacity-80"
        title="点击刷新验证码"
        @click="refreshCaptcha"
      >
        <img v-if="captcha" :src="captcha.image" alt="验证码" class="h-full w-auto" />
        <span v-else class="text-xs text-slate-400">加载中...</span>
      </button>
    </div>

    <div class="flex gap-2">
      <div class="flex h-11 flex-1 items-center gap-2 rounded-lg border border-slate-200 px-3 focus-within:border-[#1677ff]">
        <MessageSquareCode class="h-4 w-4 text-slate-400" />
        <input
          v-model="codeValue" type="text" :maxlength="codeLength" placeholder="短信验证码"
          data-testid="sms-code"
          class="w-full flex-1 text-center tracking-widest outline-none"
        />
      </div>
      <button
        type="button" data-testid="sms-send" :disabled="sending || countdown > 0"
        class="h-11 w-[120px] shrink-0 rounded-lg border border-[#1677ff] text-[#1677ff] transition-colors hover:bg-[#e6f4ff] disabled:opacity-60"
        @click="send"
      >{{ sendLabel }}</button>
    </div>

    <p v-if="errorMsg" data-testid="sms-error" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ errorMsg }}</p>
  </div>
</template>
