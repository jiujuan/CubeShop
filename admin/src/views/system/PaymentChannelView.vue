<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RefreshCw, Save, Plug, PlugZap } from 'lucide-vue-next'
import {
  getPaymentChannels,
  togglePaymentChannel,
  testPaymentChannel,
  updatePaymentChannel,
  type PaymentChannelCode,
  type PaymentChannelRow,
  type TestResult,
} from '@/api/payment'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 支付渠道配置（§5，权限 payment.channel.manage，仅超管）
 * 列表脱敏展示，按渠道编辑参数（敏感字段留空不覆盖），支持启停与连接测试。
 */
const loading = ref(true)
const list = ref<PaymentChannelRow[]>([])
const error = ref('')

const editing = ref<PaymentChannelRow | null>(null)
const form = reactive({
  name: '',
  enabled: true,
  sandbox: false,
  notify_url: '',
  return_url: '',
  config: {} as Record<string, string>,
})
const saving = ref(false)
const saveError = ref('')

const testing = ref(false)
const testResult = ref<TestResult | null>(null)

interface FieldDef {
  key: string
  label: string
  sensitive: boolean
  type: 'text' | 'textarea'
}
const CHANNEL_FIELDS: Record<string, FieldDef[]> = {
  wechat: [
    { key: 'app_id', label: 'AppID', sensitive: false, type: 'text' },
    { key: 'mch_id', label: '商户号 MchID', sensitive: false, type: 'text' },
    { key: 'api_v3_key', label: 'APIv3 密钥', sensitive: true, type: 'textarea' },
    { key: 'merchant_private_key', label: '商户私钥 (PEM)', sensitive: true, type: 'textarea' },
    { key: 'merchant_cert_serial_no', label: '证书序列号', sensitive: false, type: 'text' },
    { key: 'wechatpay_public_key', label: '微信支付公钥 (PEM)', sensitive: true, type: 'textarea' },
  ],
  alipay: [
    { key: 'app_id', label: 'AppID', sensitive: false, type: 'text' },
    { key: 'private_key', label: '应用私钥 (PEM)', sensitive: true, type: 'textarea' },
    { key: 'alipay_public_key', label: '支付宝公钥 (PEM)', sensitive: true, type: 'textarea' },
  ],
}

const editingFields = computed<FieldDef[]>(() => {
  if (!editing.value) return []
  return CHANNEL_FIELDS[editing.value.channel] ?? []
})

function maskHint(key: string): string {
  if (!editing.value) return ''
  const v = editing.value.config[key]
  const has = editing.value.config[`has_${key}`]
  if (has) return v ? '（已配置，留空表示不修改）' : ''
  return '（未配置）'
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getPaymentChannels()
    list.value = data.data.list
  } catch (e) {
    error.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

function openEditor(row: PaymentChannelRow) {
  editing.value = row
  form.name = row.name
  form.enabled = row.enabled
  form.sandbox = row.sandbox
  form.notify_url = row.notify_url || ''
  form.return_url = row.return_url || ''
  form.config = { ...(row.config as Record<string, string>) }
  saveError.value = ''
  testResult.value = null
}

function closeEditor() {
  editing.value = null
}

async function save() {
  if (!editing.value) return
  saving.value = true
  saveError.value = ''
  try {
    const { data } = await updatePaymentChannel(editing.value.channel, {
      name: form.name,
      enabled: form.enabled,
      sandbox: form.sandbox,
      notify_url: form.notify_url || undefined,
      return_url: form.return_url || undefined,
      config: { ...form.config },
    })
    editing.value = data.data
    await load()
  } catch (e) {
    saveError.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

async function toggle(row: PaymentChannelRow) {
  try {
    await togglePaymentChannel(row.channel, !row.enabled)
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : '操作失败'
  }
}

async function test(row: PaymentChannelRow) {
  testing.value = true
  testResult.value = null
  try {
    const { data } = await testPaymentChannel(row.channel)
    testResult.value = data.data
  } catch (e) {
    testResult.value = { ok: false, message: e instanceof Error ? e.message : '连接测试失败' }
  } finally {
    testing.value = false
  }
}

const channelName = (c: PaymentChannelCode) =>
  ({ wechat: '微信支付', alipay: '支付宝', balance: '余额支付', offline: '线下转账', mock: '本地模拟' })[c] ?? c

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">支付渠道配置</h2>
      <Button class="bg-[#1677ff] px-4 hover:bg-[#4096ff]" :disabled="loading" @click="load">
        <RefreshCw class="mr-1 h-4 w-4" /> 刷新
      </Button>
    </div>

    <p v-if="error" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-500">{{ error }}</p>

    <LoadingSpinner v-if="loading" />

    <div v-else class="space-y-3">
      <div
        v-for="row in list"
        :key="row.channel"
        class="flex flex-wrap items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-4 py-3"
        data-testid="channel-card"
      >
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="font-medium text-black">{{ row.name }}</span>
            <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[11px] text-slate-500">{{ channelName(row.channel) }}</span>
            <span
              v-if="row.enabled"
              class="rounded bg-green-100 px-1.5 py-0.5 text-[11px] text-green-600"
            >已启用</span>
            <span v-else class="rounded bg-slate-200 px-1.5 py-0.5 text-[11px] text-slate-400">已停用</span>
            <span
              v-if="row.is_online"
              class="rounded px-1.5 py-0.5 text-[11px]"
              :class="row.is_configured ? 'bg-blue-100 text-blue-600' : 'bg-orange-100 text-orange-500'"
            >{{ row.is_configured ? '已配置' : '未配置' }}</span>
            <span v-if="row.sandbox" class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] text-amber-600">沙箱</span>
          </div>
          <p class="mt-1 truncate font-mono text-[12px] text-slate-400">{{ row.notify_url || '无回调地址' }}</p>
        </div>
        <div class="flex items-center gap-2">
          <Button variant="outline" :disabled="testing" @click="test(row)">
            <PlugZap class="mr-1 h-4 w-4" /> 测试连接
          </Button>
          <Button variant="outline" @click="toggle(row)" v-permission="'payment.channel.manage'">
            {{ row.enabled ? '停用' : '启用' }}
          </Button>
          <Button class="bg-[#1677ff] px-4 hover:bg-[#4096ff]" v-permission="'payment.channel.manage'" @click="openEditor(row)">
            编辑
          </Button>
        </div>
      </div>
    </div>

    <!-- 编辑弹窗 -->
    <div
      v-if="editing"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6"
      data-testid="channel-edit"
      @click.self="closeEditor"
    >
      <div class="max-h-[88vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
          <h3 class="text-base font-semibold">编辑渠道：{{ editing.name }}</h3>
          <button class="text-slate-400 hover:text-slate-600" @click="closeEditor">✕</button>
        </div>

        <div class="space-y-3 text-[13px]">
          <label class="flex items-center justify-between">
            <span class="text-slate-500">渠道名称</span>
            <input v-model="form.name" class="w-64 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" />
          </label>
          <label v-if="editing.is_online" class="flex items-center justify-between">
            <span class="text-slate-500">沙箱模式</span>
            <input v-model="form.sandbox" type="checkbox" class="h-4 w-4" />
          </label>
          <label class="flex items-center justify-between">
            <span class="text-slate-500">启用该渠道</span>
            <input v-model="form.enabled" type="checkbox" class="h-4 w-4" />
          </label>
          <label class="flex items-center justify-between">
            <span class="text-slate-500">回调地址</span>
            <input v-model="form.notify_url" placeholder="可留空使用系统默认" class="w-64 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]" />
          </label>

          <div class="border-t border-slate-100 pt-3">
            <p class="mb-2 text-[12px] text-slate-400">渠道参数（敏感字段留空表示不修改，已脱敏显示）</p>
            <div v-for="f in editingFields" :key="f.key" class="mb-2">
              <label class="mb-1 block text-slate-500">
                {{ f.label }}
                <span v-if="f.sensitive" class="text-orange-400">· 敏感</span>
                <span class="text-slate-300">{{ maskHint(f.key) }}</span>
              </label>
              <textarea
                v-if="f.type === 'textarea'"
                v-model="form.config[f.key]"
                rows="3"
                class="w-full rounded-md border border-slate-300 px-3 py-1.5 font-mono text-[12px] outline-none focus:border-[#1677ff]"
              />
              <input
                v-else
                v-model="form.config[f.key]"
                class="w-full rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
              />
            </div>
            <p v-if="!editingFields.length" class="text-slate-400">该渠道无需额外参数。</p>
          </div>
        </div>

        <p v-if="testResult" class="mt-3 rounded-md px-3 py-2 text-[13px]" :class="testResult.ok ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500'">
          <Plug class="mr-1 inline h-4 w-4" /> 连接测试：{{ testResult.message }}
        </p>
        <p v-if="saveError" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-500">{{ saveError }}</p>

        <div class="mt-5 flex justify-end gap-2">
          <Button variant="outline" @click="closeEditor">取消</Button>
          <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" :disabled="saving" @click="save">
            <Save class="mr-1 h-4 w-4" /> {{ saving ? '保存中…' : '保存' }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
