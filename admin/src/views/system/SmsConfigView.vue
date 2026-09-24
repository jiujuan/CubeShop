<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RefreshCw, Send } from 'lucide-vue-next'
import {
  getSmsConfig,
  getSmsLogs,
  testSms,
  updateSmsChannel,
  updateSmsSwitches,
  type SmsChannelRow,
  type SmsConfigData,
  type SmsLogRow,
} from '@/api/sms'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 短信渠道配置（短信渠道计划 第一期，权限 sms.view / sms.manage）
 *
 * 后端接口见 app/Http/Controllers/Admin/SmsConfigController.php；
 * 设计文档 docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D7。
 *
 * ⚠️ 四条与后端联动的硬约定（页面上都有对应提示，不是可省的装饰）：
 * 1. **Secret 留空 = 不修改**：输入框 placeholder 显示掩码，留空提交不会清空线上凭证
 *    （HTTP 层无法表达「清空」—— `''` 会被全局中间件转成 null，后端当成「不改」）；
 * 2. **启用渠道是互斥的**：点「启用」会由后端在事务里关掉其它渠道，页面只发一个 id；
 * 3. **总开关关闭时什么都不发**：所有发送只落 skipped 日志，验证码场景回退图形验证码；
 * 4. **测试发送按条计费**：后端额外限流（同 IP 每分钟 SMS_SEND_RATE_LIMIT 次）。
 */
const auth = useAuthStore()
const canManage = computed(() => auth.hasPermission('sms.manage'))

const tip = ref('')
const tipOk = ref(true)
function notify(text: string, ok = true) {
  tip.value = text
  tipOk.value = ok
  setTimeout(() => (tip.value = ''), 3600)
}

// ---------------- 加载 ----------------
const loading = ref(false)
const config = ref<SmsConfigData | null>(null)

async function load() {
  loading.value = true
  try {
    const { data } = await getSmsConfig()
    config.value = data.data
    syncSwitches(data.data)
    syncChannels(data.data)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  loadLogs(1)
})

// ---------------- 运营开关 ----------------
const editEnabled = ref(false)
const editScenes = ref<string[]>([])
const editTemplates = reactive<Record<string, string>>({})

function syncSwitches(data: SmsConfigData) {
  editEnabled.value = data.switches.enabled
  editScenes.value = [...data.switches.code_scenes]
  for (const key of Object.keys(data.options.scenes)) {
    editTemplates[key] = data.switches.code_templates[key] ?? ''
  }
}

const switchesDirty = computed(() => {
  if (!config.value) return false
  if (editEnabled.value !== config.value.switches.enabled) return true
  const a = [...editScenes.value].sort().join(',')
  const b = [...config.value.switches.code_scenes].sort().join(',')
  if (a !== b) return true
  return Object.keys(config.value.options.scenes).some(
    (key) => editTemplates[key] !== (config.value!.switches.code_templates[key] ?? ''),
  )
})

const savingSwitches = ref(false)

async function saveSwitches() {
  if (!switchesDirty.value || savingSwitches.value) return
  savingSwitches.value = true
  try {
    const payload = {
      enabled: editEnabled.value,
      code_scenes: editScenes.value,
      code_templates: { ...editTemplates },
    }
    await updateSmsSwitches(payload)
    notify('已保存短信开关')
    await load()
  } finally {
    savingSwitches.value = false
  }
}

// ---------------- 渠道凭证 ----------------
/** 每个渠道的本地编辑态（key = provider） */
const channelEdit = reactive<Record<string, { access_key_id: string; access_key_secret: string; sign_name: string; region: string }>>({})

function syncChannels(data: SmsConfigData) {
  for (const row of data.channels) {
    channelEdit[row.provider] = {
      // Secret 永远以掩码回显，且提交时若未改动会被清空为 ''（= 不修改）
      access_key_id: row.access_key_id ?? '',
      access_key_secret: '',
      sign_name: row.sign_name ?? '',
      region: row.region ?? '',
    }
  }
}

const savingProvider = ref('')

async function saveChannel(row: SmsChannelRow) {
  if (!canManage.value || savingProvider.value) return
  savingProvider.value = row.provider
  try {
    const edit = channelEdit[row.provider]
    await updateSmsChannel(row.id, {
      access_key_id: edit.access_key_id,
      access_key_secret: edit.access_key_secret, // 留空 = 不修改
      sign_name: edit.sign_name,
      region: edit.region,
    })
    notify(`「${row.provider_label}」已保存`)
    edit.access_key_secret = ''
    await load()
  } finally {
    savingProvider.value = ''
  }
}

async function enableChannel(row: SmsChannelRow) {
  if (!canManage.value) return
  savingProvider.value = row.provider
  try {
    await updateSmsChannel(row.id, { is_enabled: true })
    notify(`已启用「${row.provider_label}」，其它渠道已自动停用`)
    await load()
  } finally {
    savingProvider.value = ''
  }
}

// ---------------- 测试发送 ----------------
const testPhone = ref('')
const testTemplate = ref('')
const testParams = ref('')
const testing = ref(false)
const testResult = ref<{ ok: boolean; text: string } | null>(null)

async function sendTest() {
  if (!canManage.value || testing.value) return
  let params: Record<string, string> = {}
  if (testParams.value.trim() !== '') {
    try {
      params = JSON.parse(testParams.value)
    } catch {
      notify('模板变量不是合法 JSON，例如 {"code":"123456"}', false)
      return
    }
  }
  testing.value = true
  testResult.value = null
  try {
    const { data } = await testSms({
      phone: testPhone.value.trim(),
      template_code: testTemplate.value.trim(),
      params,
    })
    const r = data.data
    testResult.value = {
      ok: r.ok,
      text: r.ok
        ? `发送成功，流水号 ${r.biz_id ?? '—'}，耗时 ${r.latency_ms ?? 0}ms`
        : `发送失败：${r.error_msg ?? r.error_code ?? '未知错误'}`,
    }
    notify(testResult.value.text, r.ok)
    if (r.ok) {
      testPhone.value = ''
    }
    await loadLogs(1)
  } finally {
    testing.value = false
  }
}

// ---------------- 发送记录 ----------------
const logLoading = ref(false)
const logs = ref<SmsLogRow[]>([])
const logPagination = ref({ page: 1, page_size: 20, total: 0 as number | null, total_pages: 1 as number | null })
const logFilter = reactive({ provider: '', status: '', phone: '' })

async function loadLogs(page = 1) {
  logLoading.value = true
  try {
    const { data } = await getSmsLogs({
      provider: logFilter.provider || undefined,
      status: logFilter.status || undefined,
      phone: logFilter.phone || undefined,
      page,
      page_size: logPagination.value.page_size,
    })
    logs.value = data.data.list
    logPagination.value = data.data.pagination
  } finally {
    logLoading.value = false
  }
}

const statusText: Record<string, string> = { sent: '已发送', failed: '失败', skipped: '未发送' }
const statusClass: Record<string, string> = {
  sent: 'text-emerald-700',
  failed: 'text-rose-700',
  skipped: 'text-slate-500',
}
</script>

<template>
  <div class="p-6">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">短信渠道</h2>
        <p class="mt-0.5 text-[13px] text-slate-500">
          配置短信服务商凭证、切换启用渠道、测试发送与查看发送记录。凭证密文入库，接口只回掩码。
        </p>
      </div>
      <Button variant="outline" :disabled="loading" data-testid="sms-reload" @click="load()">
        <RefreshCw class="mr-1 h-4 w-4" /> 刷新
      </Button>
    </div>

    <LoadingSpinner v-if="loading && !config" />

    <div
      v-if="tip"
      class="mb-3 rounded-lg border px-4 py-2.5 text-[13px]"
      :class="tipOk ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-rose-300 bg-rose-50 text-rose-800'"
      data-testid="sms-tip"
      role="status"
    >
      {{ tip }}
    </div>

    <template v-if="config">
      <!-- 降级提示：配了阿里云但凭证不全，非生产环境实际走 Mock -->
      <div
        v-if="config.active.degraded"
        class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-[13px] text-amber-800"
        data-testid="sms-degraded"
      >
        ⚠️ 当前配置渠道为「{{ config.active.configured_provider }}」，但实际生效的是
        <b>Mock 渠道（不会发出真实短信）</b>
        <span v-if="config.active.error">：{{ config.active.error }}</span>
        <span v-else>：凭证未填写完整，请补全后保存。</span>
      </div>

      <!-- 运营开关 -->
      <section class="rounded-lg bg-white p-5 shadow-sm" data-testid="sms-switches">
        <h3 class="text-[15px] font-semibold text-slate-800">短信开关</h3>
        <p class="mt-0.5 text-[12px] text-slate-500">
          总开关关闭时所有短信只记录、不发送；验证码场景按场景灰度，未勾选的场景仍走图形验证码。
        </p>

        <label class="mt-3 flex items-center gap-2 text-[13px] font-medium text-slate-800">
          <input
            v-model="editEnabled"
            type="checkbox"
            :disabled="!canManage"
            data-testid="sms-switch-enabled"
          />
          启用短信发送（总开关）
        </label>

        <div class="mt-4">
          <p class="text-[13px] font-medium text-slate-800">走短信验证码的场景</p>
          <div class="mt-1.5 flex flex-wrap gap-3" data-testid="sms-scenes">
            <label
              v-for="(label, key) in config.options.scenes"
              :key="key"
              class="flex items-center gap-2 text-[13px] text-slate-700"
            >
              <input
                v-model="editScenes"
                type="checkbox"
                :value="key"
                :disabled="!canManage"
                :data-testid="`sms-scene-${key}`"
              />
              {{ label }}
            </label>
          </div>
          <p
            v-if="config.switches.scenes_auto"
            class="mt-2 text-[12px] text-slate-400"
            data-testid="sms-scenes-auto-tip"
          >
            当前为自动模式：配好服务商账号后，系统会自动勾选已配模板的场景。你一旦手动提交，此后就完全由你决定。
          </p>
        </div>

        <div class="mt-4 space-y-2">
          <p class="text-[13px] font-medium text-slate-800">验证码模板 CODE</p>
          <p class="text-[12px] text-slate-500">
            模板 CODE 由服务商账号申请（阿里云形如 SMS_123456），未配置的场景发不出验证码。
          </p>
          <div
            v-for="(label, key) in config.options.scenes"
            :key="key"
            class="flex items-center gap-2"
          >
            <span class="w-20 shrink-0 text-[13px] text-slate-600">{{ label }}</span>
            <input
              v-model="editTemplates[key]"
              :disabled="!canManage"
              :placeholder="`SMS_xxxxxx`"
              class="w-64 rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
              :data-testid="`sms-template-${key}`"
            />
          </div>
        </div>

        <div class="mt-4 flex items-center gap-2">
          <Button
            class="bg-[#1677ff] hover:bg-[#4096ff]"
            :disabled="!canManage || !switchesDirty || savingSwitches"
            data-testid="sms-save-switches"
            @click="saveSwitches"
          >
            {{ savingSwitches ? '保存中…' : '保存开关' }}
          </Button>
        </div>
      </section>

      <!-- 渠道卡片 -->
      <section class="mt-4 grid gap-4 lg:grid-cols-2" data-testid="sms-channels">
        <div
          v-for="row in config.channels"
          :key="row.id"
          class="rounded-lg bg-white p-5 shadow-sm"
          :class="row.is_enabled ? 'ring-1 ring-[#1677ff]' : ''"
          :data-testid="`sms-channel-${row.provider}`"
        >
          <div class="flex items-start justify-between gap-2">
            <div>
              <h3 class="text-[15px] font-semibold text-slate-800">
                {{ row.provider_label }}
                <span
                  v-if="row.is_enabled"
                  class="ml-1 rounded bg-[#e6f4ff] px-1.5 py-0.5 text-[11px] text-[#1677ff]"
                  :data-testid="`sms-enabled-badge-${row.provider}`"
                >当前启用</span>
                <span
                  v-if="!row.available"
                  class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500"
                >二期提供</span>
              </h3>
              <p class="mt-0.5 text-[12px] text-slate-500">{{ row.remark }}</p>
            </div>
            <Button
              v-if="row.available && !row.is_enabled"
              variant="outline"
              size="sm"
              :disabled="!canManage || savingProvider === row.provider"
              :data-testid="`sms-enable-${row.provider}`"
              @click="enableChannel(row)"
            >
              启用
            </Button>
          </div>

          <template v-if="row.available">
            <div class="mt-3 space-y-2">
              <div>
                <span class="text-[12px] text-slate-500">AccessKey ID</span>
                <input
                  v-model="channelEdit[row.provider].access_key_id"
                  :disabled="!canManage"
                  class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
                  :data-testid="`sms-akid-${row.provider}`"
                />
              </div>
              <div>
                <span class="text-[12px] text-slate-500">AccessKey Secret</span>
                <input
                  v-model="channelEdit[row.provider].access_key_secret"
                  type="password"
                  :disabled="!canManage"
                  :placeholder="row.has_secret ? `${row.secret_masked}（留空则不修改）` : '未配置'"
                  class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
                  :data-testid="`sms-secret-${row.provider}`"
                />
                <p class="mt-0.5 text-[12px] text-slate-400">密文入库，接口只回掩码；留空提交表示不修改。</p>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <span class="text-[12px] text-slate-500">短信签名</span>
                  <input
                    v-model="channelEdit[row.provider].sign_name"
                    :disabled="!canManage"
                    class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
                    :data-testid="`sms-sign-${row.provider}`"
                  />
                </div>
                <div>
                  <span class="text-[12px] text-slate-500">地域（预留）</span>
                  <input
                    v-model="channelEdit[row.provider].region"
                    :disabled="!canManage"
                    class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
                    :data-testid="`sms-region-${row.provider}`"
                  />
                </div>
              </div>
              <p
                v-if="!row.credentials_complete && row.is_enabled && row.provider !== 'mock'"
                class="text-[12px] text-amber-700"
                :data-testid="`sms-missing-${row.provider}`"
              >
                缺失：{{ row.missing_credentials.join('、') }} —— 非生产环境会回退 Mock，不会发出真实短信。
              </p>
            </div>
            <div class="mt-3">
              <Button
                variant="outline"
                size="sm"
                :disabled="!canManage || savingProvider === row.provider"
                :data-testid="`sms-save-${row.provider}`"
                @click="saveChannel(row)"
              >
                {{ savingProvider === row.provider ? '保存中…' : '保存凭证' }}
              </Button>
            </div>
          </template>
        </div>
      </section>

      <!-- 测试发送 -->
      <section class="mt-4 rounded-lg bg-white p-5 shadow-sm" data-testid="sms-test-panel">
        <h3 class="text-[15px] font-semibold text-slate-800">测试发送</h3>
        <p class="mt-0.5 text-[12px] text-slate-500">
          走真实渠道链路并落发送记录，<b class="text-amber-700">按条计费且后端限流</b>；总开关关闭时只记录不发送。
        </p>
        <div class="mt-3 grid gap-2 md:grid-cols-3">
          <input
            v-model="testPhone"
            placeholder="手机号（11 位）"
            class="rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
            data-testid="sms-test-phone"
          />
          <input
            v-model="testTemplate"
            placeholder="模板 CODE，如 SMS_123456"
            class="rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
            data-testid="sms-test-template"
          />
          <input
            v-model="testParams"
            placeholder='模板变量 JSON，如 {"code":"123456"}'
            class="rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
            data-testid="sms-test-params"
          />
        </div>
        <div class="mt-3 flex items-center gap-2">
          <Button
            class="bg-[#1677ff] hover:bg-[#4096ff]"
            :disabled="!canManage || testing || !testPhone || !testTemplate"
            data-testid="sms-test-send"
            @click="sendTest"
          >
            <Send class="mr-1 h-4 w-4" /> {{ testing ? '发送中…' : '发送测试短信' }}
          </Button>
          <span
            v-if="testResult"
            class="text-[13px]"
            :class="testResult.ok ? 'text-emerald-700' : 'text-rose-700'"
            data-testid="sms-test-result"
          >{{ testResult.text }}</span>
        </div>
      </section>

      <!-- 发送记录 -->
      <section class="mt-4 rounded-lg bg-white p-5 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-[15px] font-semibold text-slate-800">发送记录</h3>
          <div class="flex items-center gap-2">
            <select
              v-model="logFilter.status"
              class="rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
              data-testid="sms-log-status"
              @change="loadLogs(1)"
            >
              <option value="">全部状态</option>
              <option value="sent">已发送</option>
              <option value="failed">失败</option>
              <option value="skipped">未发送</option>
            </select>
            <input
              v-model="logFilter.phone"
              placeholder="按脱敏手机号筛选"
              class="w-44 rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
              data-testid="sms-log-phone"
              @keyup.enter="loadLogs(1)"
            />
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="border-b border-slate-200 text-left text-[12px] text-slate-500">
                <th class="py-2 pr-4 font-medium">时间</th>
                <th class="py-2 pr-4 font-medium">手机号</th>
                <th class="py-2 pr-4 font-medium">场景</th>
                <th class="py-2 pr-4 font-medium">渠道</th>
                <th class="py-2 pr-4 font-medium">状态</th>
                <th class="py-2 pr-4 font-medium">错误</th>
                <th class="py-2 pr-4 font-medium">耗时</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in logs" :key="row.id" class="border-b border-slate-100" :data-testid="`sms-log-row-${row.id}`">
                <td class="py-2 pr-4 text-slate-500">{{ row.created_at ?? '—' }}</td>
                <td class="py-2 pr-4 text-slate-700">{{ row.phone_masked }}</td>
                <td class="py-2 pr-4 text-slate-600">{{ row.scene }}</td>
                <td class="py-2 pr-4 text-slate-600">{{ row.provider }}</td>
                <td class="py-2 pr-4" :class="statusClass[row.status]">{{ statusText[row.status] ?? row.status }}</td>
                <td class="py-2 pr-4 text-[12px] text-slate-500">
                  {{ row.error_code ? `${row.error_code}${row.error_msg ? ' · ' + row.error_msg : ''}` : '—' }}
                </td>
                <td class="py-2 pr-4 text-slate-500">{{ row.latency_ms === null ? '—' : row.latency_ms + 'ms' }}</td>
              </tr>
              <tr v-if="logs.length === 0 && !logLoading">
                <td colspan="7" class="py-6 text-center text-slate-400">暂无发送记录</td>
              </tr>
            </tbody>
          </table>
        </div>
        <TablePagination
          v-if="(logPagination.total ?? 0) > 0"
          class="mt-3"
          :pagination="{ ...logPagination, total: logPagination.total ?? 0, total_pages: logPagination.total_pages ?? 1 }"
          @change="loadLogs"
        />
      </section>
    </template>
  </div>
</template>
