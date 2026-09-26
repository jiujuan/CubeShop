<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  Bell, CalendarCheck, ChevronRight, Clock, Coins, Heart, KeyRound, LifeBuoy, LogOut, MapPin, PackageCheck,
  ClipboardList, ShieldCheck, Ticket, UserRound, Wallet,
} from 'lucide-vue-next'
import { changePassword, getProfile, updateProfile, uploadImage, type UserProfile } from '@/api/user'
import {
  getBalance, getRecharges, getBalanceLogs,
  type BalanceAccount, type RechargeRecord, type BalanceLogItem,
} from '@/api/balance'
import { getUnreadCount } from '@/api/notification'
import {
  getCheckinStatus, postCheckin,
  type CheckinStatus,
} from '@/api/points'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 个人中心（V1.1 E05-A / T-026）
 *
 * V1.0 缺失的「挂载点」页面：订单快捷入口、权益入口（收藏/足迹/地址/通知 + 二期三期占位）、
 * 资料编辑、账号安全（改密 T-027）。后续二期「我的券」、三期「积分」直接挂在此页。
 */
const router = useRouter()
const auth = useAuthStore()

type Tab = 'overview' | 'balance' | 'profile' | 'security'
const tab = ref<Tab>('overview')

const loading = ref(true)
const profile = ref<UserProfile | null>(null)
const unread = ref(0)
const tip = ref('')
const tipType = ref<'ok' | 'err'>('ok')

// 余额与流水（收银台方案 §9.4 / Roadmap P6）
const balance = ref<BalanceAccount | null>(null)
const recharges = ref<RechargeRecord[]>([])
const rechargeTotal = ref(0)
const rechargePage = ref(1)
const rechargeLoading = ref(false)
const logs = ref<BalanceLogItem[]>([])
const logTotal = ref(0)
const logPage = ref(1)
const logLoading = ref(false)
const balanceLoaded = ref(false)

// 签到（会员成长计划 S2）
const checkin = ref<CheckinStatus | null>(null)
const checkinSubmitting = ref(false)

// 资料编辑
const nickname = ref('')
const avatarInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const savingProfile = ref(false)

// 改密
const pwd = ref({ old_password: '', password: '', confirm: '' })
const pwdError = ref('')
const pwdSaving = ref(false)
const logoutConfirm = ref(false)

const tabs: Array<{ key: Tab; label: string }> = [
  { key: 'overview', label: '概览' },
  { key: 'balance', label: '余额' },
  { key: 'profile', label: '资料' },
  { key: 'security', label: '安全' },
]

/** 订单快捷入口（跳 OrderListView 对应 tab） */
const orderShortcuts = [
  { key: 'all', label: '全部订单', icon: ClipboardList, tab: 'all' },
  { key: 'pending_payment', label: '待付款', icon: Wallet, tab: 'pending_payment' },
  { key: 'pending_receive', label: '待收货', icon: PackageCheck, tab: 'pending_receive' },
  { key: 'pending_review', label: '待评价', icon: ShieldCheck, tab: 'pending_review' },
]

/** 权益与功能入口 */
const entries = computed(() => [
  { key: 'coupons', label: '我的优惠券', icon: Ticket, path: '/coupons/mine' },
  { key: 'coupon-center', label: '领券中心', icon: Ticket, path: '/coupons/center' },
  { key: 'favorites', label: '我的收藏', icon: Heart, path: '/account/favorites' },
  { key: 'histories', label: '浏览足迹', icon: Clock, path: '/account/histories' },
  { key: 'addresses', label: '收货地址', icon: MapPin, path: '/account/addresses' },
  { key: 'points', label: '我的积分', icon: Coins, path: '/points' },
  { key: 'notifications', label: '消息通知', icon: Bell, path: '/notifications', badge: unread.value },
  { key: 'service-center', label: '客户服务', icon: LifeBuoy, path: '/service-center' },
])

const maskedPhone = computed(() => {
  const p = profile.value?.phone
  return p && p.length === 11 ? `${p.slice(0, 3)}****${p.slice(-4)}` : (p || '未绑定')
})

function notify(type: 'ok' | 'err', text: string) {
  tipType.value = type
  tip.value = text
}

async function load() {
  loading.value = true
  try {
    const { data } = await getProfile()
    profile.value = data.data
    nickname.value = data.data.nickname ?? ''
    try {
      unread.value = (await getUnreadCount()).data.data.count
    } catch {
      unread.value = 0
    }
    try {
      balance.value = (await getBalance()).data.data
    } catch {
      balance.value = null
    }
    try {
      checkin.value = (await getCheckinStatus()).data.data
    } catch {
      checkin.value = null
    }
  } finally {
    loading.value = false
  }
}

/** 签到：成功后用返回结果刷新卡片（含实发积分与最新连续天数/余额），无需再拉一次状态 */
async function doCheckin() {
  if (!checkin.value || checkin.value.checked || checkinSubmitting.value) return
  checkinSubmitting.value = true
  try {
    const { data } = await postCheckin()
    checkin.value = data.data
    notify('ok', `签到成功，获得 ${data.data.points} 积分`)
  } catch (err) {
    // 重复签到（409 / 40009）属正常状态，刷新卡片同步即可
    const msg = err instanceof Error ? err.message : '签到失败'
    try {
      checkin.value = (await getCheckinStatus()).data.data
    } catch {
      /* 忽略 */
    }
    notify('err', msg)
  } finally {
    checkinSubmitting.value = false
  }
}

function goOrders(tabKey: string) {
  router.push({ path: '/orders', query: tabKey === 'all' ? {} : { tab: tabKey } })
}

/** 切换 Tab：首次进入「余额」Tab 时按需加载充值记录与流水 */
async function switchTab(key: Tab) {
  tab.value = key
  if (key === 'balance' && !balanceLoaded.value) {
    balanceLoaded.value = true
    await Promise.all([loadRecharges(1), loadLogs(1)])
  }
}

async function loadRecharges(page: number) {
  rechargeLoading.value = true
  try {
    const { data } = await getRecharges({ page, page_size: 5 })
    recharges.value = data.data.list
    rechargeTotal.value = data.data.pagination.total
    rechargePage.value = page
  } catch {
    /* 忽略 */
  } finally {
    rechargeLoading.value = false
  }
}

async function loadLogs(page: number) {
  logLoading.value = true
  try {
    const { data } = await getBalanceLogs({ page, page_size: 5 })
    logs.value = data.data.list
    logTotal.value = data.data.pagination.total
    logPage.value = page
  } catch {
    /* 忽略 */
  } finally {
    logLoading.value = false
  }
}

function goRecharge() {
  router.push('/balance/recharge')
}

/** 充值状态配色 */
function rechargeStatusClass(status: string): string {
  const map: Record<string, string> = {
    success: 'text-[#52c41a]',
    reviewing: 'text-[#1677ff]',
    pending: 'text-amber-500',
    failed: 'text-[#ff4d4f]',
    closed: 'text-slate-400',
  }
  return map[status] ?? 'text-slate-400'
}

async function onAvatarPicked(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  uploading.value = true
  try {
    const { data } = await uploadImage(file)
    await updateProfile({ avatar: data.data.url })
    if (profile.value) profile.value.avatar = data.data.url
    notify('ok', '头像已更新')
  } catch (err) {
    notify('err', err instanceof Error ? err.message : '上传失败')
  } finally {
    uploading.value = false
  }
}

async function saveProfile() {
  savingProfile.value = true
  try {
    const { data } = await updateProfile({ nickname: nickname.value.trim() })
    profile.value = { ...(profile.value as UserProfile), ...data.data }
    notify('ok', '资料已保存')
  } catch (e) {
    notify('err', e instanceof Error ? e.message : '保存失败')
  } finally {
    savingProfile.value = false
  }
}

/** 密码强度：8~32 位含字母与数字 */
function pwdValid(p: string): boolean {
  return /^(?=.*[A-Za-z])(?=.*\d).+$/.test(p) && p.length >= 8 && p.length <= 32
}

async function doChangePassword() {
  pwdError.value = ''
  if (!pwd.value.old_password) { pwdError.value = '请输入当前密码'; return }
  if (!pwdValid(pwd.value.password)) { pwdError.value = '新密码需 8~32 位，且同时包含字母与数字'; return }
  if (pwd.value.password !== pwd.value.confirm) { pwdError.value = '两次输入的密码不一致'; return }

  pwdSaving.value = true
  try {
    const { data } = await changePassword({
      old_password: pwd.value.old_password,
      password: pwd.value.password,
      password_confirmation: pwd.value.confirm,
    })
    const n = data.data.revoked_tokens
    pwd.value = { old_password: '', password: '', confirm: '' }
    notify('ok', n > 0 ? `密码已修改，${n} 台其他设备已退出登录` : '密码已修改')
  } catch (e) {
    pwdError.value = e instanceof Error ? e.message : '修改失败'
  } finally {
    pwdSaving.value = false
  }
}

async function doLogout() {
  logoutConfirm.value = false
  await auth.logout()
  router.replace('/login')
}

onMounted(() => {
  if (auth.token) load()
  else loading.value = false
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />
    <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-6" data-testid="account-center">
      <LoadingSpinner v-if="loading" />

      <template v-else-if="profile">
        <!-- 头部 -->
        <div class="mb-4 flex items-center gap-4 rounded-xl border border-slate-100 bg-white p-5">
          <div class="relative">
            <img v-if="profile.avatar" :src="profile.avatar" class="h-16 w-16 rounded-full object-cover" alt="" />
            <span v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-[#e6f4ff] text-[#1677ff]">
              <UserRound class="h-8 w-8" />
            </span>
            <button
              class="absolute -bottom-1 -right-1 rounded-full bg-[#1677ff] px-2 py-0.5 text-[10px] text-white"
              :disabled="uploading" data-testid="avatar-upload-btn"
              @click="avatarInput?.click()"
            >{{ uploading ? '…' : '改' }}</button>
            <input ref="avatarInput" type="file" accept="image/*" class="hidden" data-testid="avatar-input" @change="onAvatarPicked" />
          </div>
          <div class="min-w-0">
            <p class="text-base font-semibold text-slate-800">{{ profile.nickname || profile.username }}</p>
            <p class="mt-0.5 text-xs text-slate-400">@{{ profile.username }} · {{ maskedPhone }}</p>
          </div>
        </div>

        <!-- Tab -->
        <div class="mb-4 flex gap-2 overflow-x-auto" data-testid="account-tabs">
          <button
            v-for="t in tabs" :key="t.key"
            class="shrink-0 rounded-full px-4 py-1.5 text-[13px] transition-colors"
            :class="tab === t.key ? 'bg-[#1677ff] text-white' : 'border border-slate-200 bg-white text-slate-600 hover:text-[#1677ff]'"
            :data-testid="`account-tab-${t.key}`"
            @click="switchTab(t.key)"
          >{{ t.label }}</button>
        </div>

        <p v-if="tip" class="mb-3 rounded-md px-3 py-2 text-[13px]" :class="tipType === 'ok' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500'" data-testid="account-tip">{{ tip }}</p>

        <!-- 概览 -->
        <template v-if="tab === 'overview'">
          <!-- 我的余额（收银台方案 §9.4 / P6） -->
          <section class="mb-4 rounded-xl border border-slate-100 bg-white p-5" data-testid="balance-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="flex items-center gap-1.5 text-sm font-semibold text-slate-700"><Wallet class="h-4 w-4 text-[#1677ff]" /> 我的余额</p>
                <p class="mt-2 text-2xl font-bold text-[#ff4d4f]" data-testid="balance-value">¥{{ balance?.balance ?? '0.00' }}</p>
                <p class="mt-1 text-xs text-slate-400">
                  累计充值 ¥{{ balance?.total_recharge ?? '0.00' }} · 累计消费 ¥{{ balance?.total_consume ?? '0.00' }}
                </p>
              </div>
              <div class="flex gap-2">
                <button class="rounded-full border border-slate-200 px-4 py-1.5 text-[13px] text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]" data-testid="balance-detail-btn" @click="switchTab('balance')">明细</button>
                <button class="rounded-full bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff]" data-testid="recharge-btn" @click="goRecharge">充值</button>
              </div>
            </div>
          </section>

          <!-- 每日签到（会员成长计划 S2） -->
          <section v-if="checkin" class="mb-4 rounded-xl border border-slate-100 bg-white p-5" data-testid="checkin-card">
            <template v-if="checkin.available">
              <div class="flex items-center justify-between">
                <div>
                  <p class="flex items-center gap-1.5 text-sm font-semibold text-slate-700"><CalendarCheck class="h-4 w-4 text-[#1677ff]" /> 每日签到</p>
                  <p class="mt-2 text-2xl font-bold text-[#1677ff]" data-testid="checkin-streak">已连续 {{ checkin.streak }} 天</p>
                  <p class="mt-1 text-xs text-slate-400">
                    今日可得 <span class="font-medium text-[#52c41a]">+{{ checkin.today_points }}</span>
                    · 明日可得 +{{ checkin.next_points }}
                    · 累计 {{ checkin.total_days }} 天
                  </p>
                  <p class="mt-0.5 text-xs text-slate-400">可用积分 {{ checkin.balance }}</p>
                </div>
                <button
                  class="shrink-0 rounded-full px-5 py-2 text-[13px] text-white transition-colors"
                  :class="checkin.checked ? 'cursor-not-allowed bg-slate-300' : 'bg-[#1677ff] hover:bg-[#4096ff]'"
                  :disabled="checkin.checked || checkinSubmitting"
                  data-testid="checkin-btn"
                  @click="doCheckin"
                >{{ checkin.checked ? '今日已签' : (checkinSubmitting ? '签到中…' : '签到') }}</button>
              </div>
            </template>
            <p v-else class="text-sm text-slate-400" data-testid="checkin-disabled">每日签到功能暂未开放</p>
          </section>

          <section class="mb-4 rounded-xl border border-slate-100 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">我的订单</h2>
            <div class="grid grid-cols-4 gap-2">
              <button
                v-for="s in orderShortcuts" :key="s.key"
                class="flex flex-col items-center gap-1.5 rounded-lg py-3 text-slate-600 transition-colors hover:bg-slate-50 hover:text-[#1677ff]"
                :data-testid="`shortcut-${s.key}`"
                @click="goOrders(s.tab)"
              >
                <component :is="s.icon" class="h-5 w-5" />
                <span class="text-xs">{{ s.label }}</span>
              </button>
            </div>
          </section>

          <section class="rounded-xl border border-slate-100 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">我的服务</h2>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <button
                v-for="e in entries" :key="e.key"
                class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-3 text-[13px] text-slate-700 transition-colors hover:border-[#1677ff] hover:text-[#1677ff]"
                :data-testid="`entry-${e.key}`"
                @click="router.push(e.path)"
              >
                <span class="flex items-center gap-2">
                  <component :is="e.icon" class="h-4 w-4" /> {{ e.label }}
                  <span v-if="e.badge" class="rounded-full bg-[#ff4d4f] px-1.5 text-[10px] text-white" data-testid="entry-badge">{{ e.badge }}</span>
                </span>
                <ChevronRight class="h-3.5 w-3.5 text-slate-300" />
              </button>
            </div>
          </section>
        </template>

        <!-- 余额（充值记录 + 余额流水，P6） -->
        <template v-else-if="tab === 'balance'">
          <section class="mb-4 rounded-xl border border-slate-100 bg-white p-5" data-testid="balance-panel">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">充值记录</h2>
            <p v-if="rechargeLoading" class="py-6 text-center text-xs text-slate-400">加载中…</p>
            <p v-else-if="!recharges.length" class="py-6 text-center text-xs text-slate-400" data-testid="recharge-empty">暂无充值记录</p>
            <ul v-else class="divide-y divide-slate-100">
              <li v-for="r in recharges" :key="r.id" class="flex items-center justify-between py-3 text-[13px]" data-testid="recharge-row">
                <div class="min-w-0">
                  <p class="text-slate-700">
                    {{ r.channel_label }} · ¥{{ r.amount }}
                    <span v-if="Number(r.gift_amount) > 0" class="text-amber-500">（赠 ¥{{ r.gift_amount }}）</span>
                  </p>
                  <p class="mt-0.5 truncate text-xs text-slate-400">{{ r.recharge_no }} · {{ r.created_at }}</p>
                </div>
                <span class="shrink-0 text-xs" :class="rechargeStatusClass(r.status)">{{ r.status_label }}</span>
              </li>
            </ul>
            <div v-if="rechargeTotal > 5" class="mt-3 flex items-center justify-center gap-3 text-xs text-slate-500">
              <button class="rounded border border-slate-200 px-3 py-1 disabled:opacity-40" :disabled="rechargePage <= 1" data-testid="recharge-prev" @click="loadRecharges(rechargePage - 1)">上一页</button>
              <span>{{ rechargePage }} / {{ Math.ceil(rechargeTotal / 5) }}</span>
              <button class="rounded border border-slate-200 px-3 py-1 disabled:opacity-40" :disabled="rechargePage >= Math.ceil(rechargeTotal / 5)" data-testid="recharge-next" @click="loadRecharges(rechargePage + 1)">下一页</button>
            </div>
          </section>

          <section class="rounded-xl border border-slate-100 bg-white p-5" data-testid="balance-logs-panel">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">余额流水</h2>
            <p v-if="logLoading" class="py-6 text-center text-xs text-slate-400">加载中…</p>
            <p v-else-if="!logs.length" class="py-6 text-center text-xs text-slate-400" data-testid="log-empty">暂无余额流水</p>
            <ul v-else class="divide-y divide-slate-100">
              <li v-for="l in logs" :key="l.id" class="flex items-center justify-between py-3 text-[13px]" data-testid="log-row">
                <div class="min-w-0">
                  <p class="text-slate-700">{{ l.type_label }}<span v-if="l.remark" class="text-slate-400"> · {{ l.remark }}</span></p>
                  <p class="mt-0.5 text-xs text-slate-400">{{ l.created_at }} · 余额 ¥{{ l.balance_after }}</p>
                </div>
                <span class="shrink-0 font-medium" :class="Number(l.amount) >= 0 ? 'text-[#52c41a]' : 'text-[#ff4d4f]'">{{ Number(l.amount) >= 0 ? '+' : '' }}{{ l.amount }}</span>
              </li>
            </ul>
            <div v-if="logTotal > 5" class="mt-3 flex items-center justify-center gap-3 text-xs text-slate-500">
              <button class="rounded border border-slate-200 px-3 py-1 disabled:opacity-40" :disabled="logPage <= 1" data-testid="log-prev" @click="loadLogs(logPage - 1)">上一页</button>
              <span>{{ logPage }} / {{ Math.ceil(logTotal / 5) }}</span>
              <button class="rounded border border-slate-200 px-3 py-1 disabled:opacity-40" :disabled="logPage >= Math.ceil(logTotal / 5)" data-testid="log-next" @click="loadLogs(logPage + 1)">下一页</button>
            </div>
          </section>
        </template>

        <!-- 资料 -->
        <section v-else-if="tab === 'profile'" class="rounded-xl border border-slate-100 bg-white p-5" data-testid="profile-panel">
          <div class="max-w-sm space-y-3 text-[13px]">
            <label class="block">
              <span class="mb-1 block text-slate-500">昵称</span>
              <input v-model="nickname" type="text" maxlength="64" data-testid="nickname-input" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
            </label>
            <p class="text-slate-500">手机号：{{ maskedPhone }}<span class="ml-2 text-xs text-slate-400">（换绑功能将在 V1.1 二期开放）</span></p>
            <p class="text-slate-500">邮箱：{{ profile.email || '未绑定' }}</p>
            <button
              class="rounded-md bg-[#1677ff] px-5 py-2 text-white hover:bg-[#4096ff] disabled:opacity-50"
              :disabled="savingProfile" data-testid="save-profile-btn"
              @click="saveProfile"
            >{{ savingProfile ? '保存中…' : '保存资料' }}</button>
          </div>
        </section>

        <!-- 安全 -->
        <section v-else class="rounded-xl border border-slate-100 bg-white p-5" data-testid="security-panel">
          <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700">
            <KeyRound class="h-4 w-4" /> 修改密码
          </h2>
          <div class="max-w-sm space-y-3 text-[13px]">
            <label class="block">
              <span class="mb-1 block text-slate-500">当前密码</span>
              <input v-model="pwd.old_password" type="password" data-testid="old-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">新密码</span>
              <input v-model="pwd.password" type="password" placeholder="8~32 位，含字母与数字" data-testid="new-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
            </label>
            <label class="block">
              <span class="mb-1 block text-slate-500">确认新密码</span>
              <input v-model="pwd.confirm" type="password" data-testid="confirm-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" />
            </label>
            <p v-if="pwdError" class="text-xs text-red-500" data-testid="pwd-error">{{ pwdError }}</p>
            <p class="text-xs text-slate-400">修改成功后，其他设备的登录状态将自动失效。</p>
            <button
              class="rounded-md bg-[#1677ff] px-5 py-2 text-white hover:bg-[#4096ff] disabled:opacity-50"
              :disabled="pwdSaving" data-testid="change-password-btn"
              @click="doChangePassword"
            >{{ pwdSaving ? '提交中…' : '确认修改' }}</button>
          </div>

          <hr class="my-5 border-slate-100" />
          <div class="flex items-center justify-between text-[13px]">
            <div>
              <p class="text-slate-700">退出登录</p>
              <p class="text-xs text-slate-400">退出后需要重新输入密码</p>
            </div>
            <button class="flex items-center gap-1 rounded-md border border-slate-200 px-3 py-1.5 text-slate-500 hover:border-red-300 hover:text-red-500" data-testid="logout-btn" @click="logoutConfirm = true">
              <LogOut class="h-3.5 w-3.5" /> 退出登录
            </button>
          </div>
        </section>
      </template>
    </main>
    <ShopFooter />

    <ConfirmDialog
      v-model="logoutConfirm"
      title="确认退出登录？"
      content="退出后需重新输入账号密码登录。"
      confirm-text="确认退出"
      @confirm="doLogout"
      @cancel="logoutConfirm = false"
    />
  </div>
</template>
