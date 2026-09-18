<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Check, Copy, FlaskConical, Info, Loader2 } from 'lucide-vue-next'

import {
  getWmsConfig,
  saveWmsConfig,
  testWmsConnection,
  WMS_API_ENV_LABELS,
  WMS_MAPPING_MODE_LABELS,
  WMS_PROVIDER_LABELS,
  type WmsApiEnv,
  type WmsConfigPayload,
  type WmsMappingMode,
  type WmsProvider,
  type WmsTestResult,
} from '@/api/wms'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * WMS 对接配置（WMS 计划 P0 / F2～F5，权限 wms.config.manage）
 *
 * 关键约定：
 * - AppSecret / access_token **只写不读**：编辑态不回填明文，留空 = 不修改（后端同语义）；
 * - 回调地址只读 + 一键复制（供粘贴到菜鸟开放平台后台）；
 * - 「测试连通性」在没有真实凭证时走 Mock，结果行内展示并标明 Mock（避免误以为已联调）。
 */
const route = useRoute()
const router = useRouter()
const warehouseId = Number(route.params.id)

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const testResult = ref<WmsTestResult | null>(null)
const copied = ref(false)

/** 已配置：决定 AppSecret 占位提示（`••••••` vs `未配置`） */
const configured = ref(false)
const hasAppSecret = ref(false)
const hasAccessToken = ref(false)
const appSecretMasked = ref<string | null>(null)
const accessTokenMasked = ref<string | null>(null)
const callbackUrl = ref<string | null>(null)

interface ConfigForm {
  provider: WmsProvider
  enabled: boolean
  auto_push: boolean
  auto_push_return: boolean
  push_retry_times: number
  sku_mapping_mode: WmsMappingMode
  app_key: string
  app_secret: string
  access_token: string
  customer_id: string
  owner_no: string
  warehouse_code: string
  warehouse_no: string
  api_env: WmsApiEnv
  remark: string
}

const form = reactive<ConfigForm>({
  provider: 'cainiao',
  enabled: false,
  auto_push: true,
  auto_push_return: true,
  push_retry_times: 3,
  sku_mapping_mode: 'same',
  app_key: '',
  app_secret: '',
  access_token: '',
  customer_id: '',
  owner_no: '',
  warehouse_code: '',
  warehouse_no: '',
  api_env: 'sandbox',
  remark: '',
})

const providers: { value: WmsProvider; disabled: boolean; hint: string }[] = [
  { value: 'cainiao', disabled: false, hint: '奇门仓配（本期支持）' },
  { value: 'jd_cloud', disabled: true, hint: 'P8 支持，敬请期待' },
]

/** 用带类型的选项数组（而非直接遍历 Record），避免 v-model 在模板中退化为 string */
const apiEnvOptions: { value: WmsApiEnv; label: string }[] = [
  { value: 'prod', label: WMS_API_ENV_LABELS.prod },
  { value: 'sandbox', label: WMS_API_ENV_LABELS.sandbox },
]

const mappingModeOptions: { value: WmsMappingMode; label: string }[] = [
  { value: 'same', label: WMS_MAPPING_MODE_LABELS.same },
  { value: 'manual', label: WMS_MAPPING_MODE_LABELS.manual },
]

/** 凭证是否走 Mock 兜底（沙箱 / 未配置真实凭证）——用于按钮与提示文案 */
const willUseMock = computed(() => form.api_env !== 'prod' || !form.app_key || (!hasAppSecret.value && !form.app_secret))
const providerUnavailable = computed(() => form.provider === 'jd_cloud')

async function load() {
  loading.value = true
  try {
    const { data } = await getWmsConfig(warehouseId)
    const d = data.data
    configured.value = d.configured
    hasAppSecret.value = d.has_app_secret
    hasAccessToken.value = d.has_access_token
    appSecretMasked.value = d.app_secret_masked
    accessTokenMasked.value = d.access_token_masked
    callbackUrl.value = d.callback_url

    Object.assign(form, {
      provider: d.provider,
      enabled: d.enabled,
      auto_push: d.auto_push,
      auto_push_return: d.auto_push_return,
      push_retry_times: d.push_retry_times,
      sku_mapping_mode: d.sku_mapping_mode,
      app_key: d.app_key ?? '',
      app_secret: '',
      access_token: '',
      customer_id: d.customer_id ?? '',
      owner_no: d.owner_no ?? '',
      warehouse_code: d.warehouse_code ?? '',
      warehouse_no: d.warehouse_no ?? '',
      api_env: d.api_env,
      remark: d.remark ?? '',
    })
  } finally {
    loading.value = false
  }
}

async function doSave() {
  if (saving.value || providerUnavailable.value) return
  saving.value = true
  try {
    const payload: WmsConfigPayload = {
      provider: form.provider,
      enabled: form.enabled,
      auto_push: form.auto_push,
      auto_push_return: form.auto_push_return,
      push_retry_times: form.push_retry_times,
      sku_mapping_mode: form.sku_mapping_mode,
      app_key: form.app_key || null,
      api_env: form.api_env,
      customer_id: form.customer_id || null,
      owner_no: form.owner_no || null,
      warehouse_code: form.warehouse_code || null,
      warehouse_no: form.warehouse_no || null,
      remark: form.remark || null,
    }
    // 留空 = 不修改：非空才提交，避免把空串写成新密钥
    if (form.app_secret) payload.app_secret = form.app_secret
    if (form.access_token) payload.access_token = form.access_token

    const { data } = await saveWmsConfig(warehouseId, payload)
    const d = data.data
    configured.value = d.configured
    hasAppSecret.value = d.has_app_secret
    hasAccessToken.value = d.has_access_token
    appSecretMasked.value = d.app_secret_masked
    accessTokenMasked.value = d.access_token_masked
    callbackUrl.value = d.callback_url
    form.app_secret = ''
    form.access_token = ''
    testResult.value = null
  } catch {
    // 拦截器已提示
  } finally {
    saving.value = false
  }
}

async function doTest() {
  if (testing.value) return
  testing.value = true
  testResult.value = null
  try {
    const { data } = await testWmsConnection(warehouseId)
    testResult.value = data.data
  } catch {
    // 拦截器已提示
  } finally {
    testing.value = false
  }
}

/** 复制回调地址：优先 Clipboard API，非安全上下文回退 execCommand（两条路径都必须容错） */
async function copyCallback() {
  if (!callbackUrl.value) return

  let ok = false
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(callbackUrl.value)
      ok = true
    }
  } catch {
    ok = false
  }

  if (!ok) {
    try {
      const el = document.createElement('textarea')
      el.value = callbackUrl.value
      document.body.appendChild(el)
      el.select()
      ok = document.execCommand('copy')
      document.body.removeChild(el)
    } catch {
      ok = false
    }
  }

  copied.value = ok
  if (ok) window.setTimeout(() => (copied.value = false), 2000)
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <button class="flex items-center gap-1 text-[13px] text-slate-500 hover:text-[#1677ff]" data-testid="wms-config-back" @click="router.push('/wms/warehouses')">
          <ArrowLeft class="h-4 w-4" /> 返回仓库列表
        </button>
        <h2 class="text-lg font-semibold text-slate-800">WMS 对接配置</h2>
        <span v-if="configured" class="rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-600">已配置</span>
        <span v-else class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">未配置</span>
      </div>
      <div class="flex items-center gap-2">
        <Button
          class="border border-slate-300 bg-white text-slate-600 hover:bg-slate-50"
          :disabled="testing || !configured"
          data-testid="wms-test-connection"
          @click="doTest"
        >
          <Loader2 v-if="testing" class="mr-1 h-4 w-4 animate-spin" />
          <FlaskConical v-else class="mr-1 h-4 w-4" />
          {{ testing ? '测试中…' : '测试连通性' }}
        </Button>
        <Button
          class="bg-[#1677ff] hover:bg-[#4096ff]"
          :disabled="saving || providerUnavailable"
          data-testid="wms-config-save"
          @click="doSave"
        >{{ saving ? '保存中…' : '保存配置' }}</Button>
      </div>
    </div>

    <LoadingSpinner v-if="loading" />

    <template v-else>
      <!-- 连通性测试结果（行内徽标） -->
      <div
        v-if="testResult"
        class="mb-4 flex items-center gap-2 rounded-lg px-3 py-2 text-[13px]"
        :class="testResult.success ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600'"
        data-testid="wms-test-result"
      >
        <Check v-if="testResult.success" class="h-4 w-4" />
        <span>{{ testResult.message }}</span>
        <span v-if="testResult.mock" class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700">Mock 模式，未真正联调</span>
        <span v-if="testResult.request_id" class="text-xs text-slate-400">request_id: {{ testResult.request_id }}</span>
      </div>

      <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <!-- 左：基础配置 -->
        <section class="rounded-lg border border-slate-100 p-4">
          <h3 class="mb-3 text-sm font-semibold text-slate-700">基础配置</h3>

          <label class="block text-xs text-slate-500">服务商</label>
          <div class="mt-1 flex items-center gap-4">
            <label v-for="p in providers" :key="p.value" class="flex items-center gap-1.5 text-[13px]" :class="p.disabled ? 'text-slate-300' : 'text-slate-700'">
              <input
                v-model="form.provider"
                type="radio"
                :value="p.value"
                :disabled="p.disabled"
                :data-testid="`wms-provider-${p.value}`"
              />
              {{ WMS_PROVIDER_LABELS[p.value] }}
              <span class="text-xs text-slate-400">（{{ p.hint }}）</span>
            </label>
          </div>

          <label class="mt-3 block text-xs text-slate-500">运行环境</label>
          <div class="mt-1 flex items-center gap-4">
            <label v-for="opt in apiEnvOptions" :key="opt.value" class="flex items-center gap-1.5 text-[13px] text-slate-700">
              <input v-model="form.api_env" type="radio" :value="opt.value" />
              {{ opt.label }}
            </label>
          </div>

          <div class="mt-3 flex items-center gap-6">
            <label class="flex items-center gap-2 text-[13px] text-slate-700">
              <input v-model="form.enabled" type="checkbox" data-testid="wms-enabled" />
              启用 WMS 对接
            </label>
            <label class="flex items-center gap-2 text-[13px] text-slate-700">
              <input v-model="form.auto_push" type="checkbox" data-testid="wms-auto-push" />
              支付后自动推送出库单
            </label>
          </div>

          <label class="mt-3 flex items-center gap-2 text-[13px] text-slate-700">
            <input v-model="form.auto_push_return" type="checkbox" data-testid="wms-auto-push-return" />
            退货审核通过后自动推送退货入库单
          </label>

          <label class="mt-3 block text-xs text-slate-500">推送失败重试次数（0～10）</label>
          <input
            v-model.number="form.push_retry_times"
            type="number"
            min="0"
            max="10"
            data-testid="wms-retry-times"
            class="mt-1 w-32 rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
          />

          <label class="mt-3 block text-xs text-slate-500">SKU 映射方式</label>
          <select
            v-model="form.sku_mapping_mode"
            data-testid="wms-mapping-mode"
            class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
          >
            <option v-for="opt in mappingModeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
          <p class="mt-1 text-xs text-slate-400">
            「手工映射」时必须在「SKU 映射」页配置货品编码，否则拒绝推送。
          </p>

          <label class="mt-3 block text-xs text-slate-500">备注</label>
          <input
            v-model="form.remark"
            type="text"
            class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
          />
        </section>

        <!-- 右：菜鸟凭证 + 回调 -->
        <section class="rounded-lg border border-slate-100 p-4">
          <h3 class="mb-3 text-sm font-semibold text-slate-700">菜鸟（奇门）凭证</h3>

          <div class="flex items-start gap-2 rounded-lg bg-[#f0f7ff] px-3 py-2 text-xs text-slate-500">
            <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>密钥只写不读：保存后仅显示掩码。留空表示不修改原值；沙箱或未配置真实凭证时，连通性测试走 Mock。</span>
          </div>

          <label class="mt-3 block text-xs text-slate-500">AppKey</label>
          <input
            v-model="form.app_key"
            type="text"
            placeholder="菜鸟开放平台 AppKey"
            data-testid="wms-app-key"
            class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]"
          />

          <label class="mt-3 block text-xs text-slate-500">AppSecret</label>
          <input
            v-model="form.app_secret"
            type="password"
            autocomplete="new-password"
            :placeholder="hasAppSecret ? `已配置（${appSecretMasked}），留空不修改` : '未配置'"
            data-testid="wms-app-secret"
            class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]"
          />

          <label class="mt-3 block text-xs text-slate-500">access_token（可选）</label>
          <input
            v-model="form.access_token"
            type="password"
            autocomplete="new-password"
            :placeholder="hasAccessToken ? `已配置（${accessTokenMasked}），留空不修改` : '未配置'"
            class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]"
          />

          <div class="mt-3 flex gap-3">
            <div class="flex-1">
              <label class="block text-xs text-slate-500">货主编码 owner_no</label>
              <input v-model="form.owner_no" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]" />
            </div>
            <div class="flex-1">
              <label class="block text-xs text-slate-500">客户编码 customer_id</label>
              <input v-model="form.customer_id" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]" />
            </div>
          </div>

          <div class="mt-3 flex gap-3">
            <div class="flex-1">
              <label class="block text-xs text-slate-500">仓库编码 warehouse_code</label>
              <input v-model="form.warehouse_code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]" />
            </div>
            <div class="flex-1">
              <label class="block text-xs text-slate-500">物理仓编码 warehouse_no</label>
              <input v-model="form.warehouse_no" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 font-mono text-[13px] outline-none focus:border-[#1677ff]" />
            </div>
          </div>

          <label class="mt-4 block text-xs text-slate-500">回调地址（只读，复制到菜鸟后台）</label>
          <div class="mt-1 flex items-center gap-2">
            <input
              :value="callbackUrl ?? '保存配置后生成'"
              type="text"
              readonly
              data-testid="wms-callback-url"
              class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 font-mono text-xs text-slate-500 outline-none"
            />
            <Button
              class="shrink-0 border border-slate-300 bg-white text-slate-600 hover:bg-slate-50"
              :disabled="!callbackUrl"
              data-testid="wms-copy-callback"
              @click="copyCallback"
            >
              <Check v-if="copied" class="mr-1 h-4 w-4 text-green-600" />
              <Copy v-else class="mr-1 h-4 w-4" />
              {{ copied ? '已复制' : '复制' }}
            </Button>
          </div>

          <p v-if="willUseMock" class="mt-2 text-xs text-amber-600">
            当前为 {{ WMS_API_ENV_LABELS[form.api_env] }}{{ form.app_key ? '' : ' / 未填写 AppKey' }}，连通性测试将走 Mock（不产生真实调用）。
          </p>
        </section>
      </div>
    </template>
  </div>
</template>
