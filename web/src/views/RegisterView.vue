<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Package } from 'lucide-vue-next'
import { getCaptcha, register, type Captcha } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

/**
 * 用户端注册页
 */
const router = useRouter()
const auth = useAuthStore()

const username = ref('')
const password = ref('')
const confirm = ref('')
const captchaCode = ref('')
const captcha = ref<Captcha | null>(null)
const loading = ref(false)
const errorMsg = ref('')

async function refreshCaptcha() {
  captchaCode.value = ''
  const { data } = await getCaptcha()
  captcha.value = data.data
}

onMounted(refreshCaptcha)

async function submit() {
  if (!username.value.trim() || !password.value || !captchaCode.value) {
    errorMsg.value = '请填写完整注册信息'
    return
  }
  if (password.value.length < 8) {
    errorMsg.value = '密码至少 8 位'
    return
  }
  if (password.value !== confirm.value) {
    errorMsg.value = '两次输入的密码不一致'
    return
  }
  loading.value = true
  errorMsg.value = ''
  try {
    const { data } = await register({
      username: username.value.trim(),
      password: password.value,
      password_confirmation: confirm.value,
      captcha_id: captcha.value!.captcha_id,
      code: captchaCode.value.trim().toUpperCase(),
    })
    auth.setToken(data.data.token)
    await auth.fetchUser().catch(() => null)
    router.replace('/')
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '注册失败'
    await refreshCaptcha()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-[#e6f4ff] via-[#f5faff] to-white px-4">
    <div class="mb-6 flex items-center gap-3">
      <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#1677ff]">
        <Package class="h-7 w-7 text-white" />
      </span>
      <div>
        <div class="text-2xl font-bold text-slate-800">CubeShop</div>
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
          v-model="password" type="password" placeholder="密码（至少 8 位）"
          class="h-11 w-full rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]"
        />
        <input
          v-model="confirm" type="password" placeholder="确认密码"
          class="h-11 w-full rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]"
        />
        <div class="flex gap-2">
          <input
            v-model="captchaCode" type="text" maxlength="4" placeholder="验证码"
            class="h-11 w-28 rounded-lg border border-slate-200 px-3 text-center tracking-widest outline-none focus:border-[#1677ff]"
          />
          <button class="flex h-11 flex-1 items-center justify-center overflow-hidden rounded-lg bg-slate-50 hover:opacity-80" @click="refreshCaptcha">
            <span v-if="captcha" class="text-xl font-bold italic tracking-[0.4em] text-[#1677ff]" v-html="captcha.image" />
            <span v-else class="text-xs text-slate-400">加载中...</span>
          </button>
        </div>

        <p v-if="errorMsg" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ errorMsg }}</p>

        <button
          class="h-11 w-full rounded-lg bg-[#1677ff] font-medium text-white transition-colors hover:bg-[#4096ff] disabled:opacity-60"
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
