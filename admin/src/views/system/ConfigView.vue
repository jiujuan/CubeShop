<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ImagePlus, Save, Settings, X } from 'lucide-vue-next'
import { getConfigs, updateConfigs, uploadSiteLogo, type SystemConfig } from '@/api/admin'
import { useAuthStore } from '@/stores/auth'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 系统设置（权限 config.manage）
 *
 * 按配置键前缀分组，以 Tab 管理（分组真源在后端 App\Support\ConfigGroup，
 * 前端只按接口返回的 group 字段聚拢，顺序沿用后端排序，不重复维护分组表）。
 * 「站点信息」组内的 logo 项渲染为上传控件，其余为文本输入。
 */
const auth = useAuthStore()
const configs = ref<SystemConfig[]>([])
const drafts = reactive<Record<string, string>>({})
const loading = ref(true)
const saving = ref(false)
const uploadingKey = ref('')
const message = ref('')
const errorMsg = ref('')
const canManage = auth.hasPermission('config.manage')

/** 以图片上传控件渲染的配置键（大 logo / 小 logo） */
const LOGO_KEYS = ['site.logo', 'site.logo_small']
const isLogo = (key: string) => LOGO_KEYS.includes(key)

/** 按分组聚拢，保持后端返回顺序 */
const groups = computed(() => {
  const map = new Map<string, SystemConfig[]>()
  for (const c of configs.value) {
    const label = c.group || '其它设置'
    const bucket = map.get(label)
    if (bucket) bucket.push(c)
    else map.set(label, [c])
  }
  return [...map.entries()].map(([label, items]) => ({ label, items }))
})

const activeTab = ref('')

const activeConfigs = computed(
  () => groups.value.find((g) => g.label === activeTab.value)?.items ?? [],
)

onMounted(async () => {
  try {
    const res = await getConfigs()
    configs.value = res.data.data
    configs.value.forEach((c) => (drafts[c.config_key] = c.config_value ?? ''))
    activeTab.value = groups.value[0]?.label ?? ''
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '配置加载失败'
  } finally {
    loading.value = false
  }
})

/** 上传 logo：仅更新草稿，点「保存配置」后才落库 */
async function onLogoChange(event: Event, key: string) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  uploadingKey.value = key
  message.value = ''
  errorMsg.value = ''
  try {
    const { data } = await uploadSiteLogo(file)
    drafts[key] = data.data.url
    message.value = '图片已上传，点击「保存配置」后生效'
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '图片上传失败'
  } finally {
    uploadingKey.value = ''
    input.value = ''
  }
}

function clearLogo(key: string) {
  drafts[key] = ''
  message.value = ''
}

async function save() {
  // 站点名称是前台品牌展示的兜底，禁止保存为空（后端也会回落默认值）
  if (drafts['site.name'] !== undefined && !drafts['site.name'].trim()) {
    errorMsg.value = '站点名称不能为空'
    message.value = ''
    return
  }

  saving.value = true
  message.value = ''
  errorMsg.value = ''
  try {
    await updateConfigs(
      configs.value.map((c) => ({
        config_key: c.config_key,
        config_value: drafts[c.config_key] ?? '',
      })),
    )
    // 就地回写，避免整页重载
    configs.value.forEach((c) => (c.config_value = drafts[c.config_key] ?? ''))
    message.value = '配置已保存，前台刷新即可看到最新的站点名称与 logo'
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-lg font-semibold">系统设置</h1>

    <div v-if="loading" class="rounded-lg bg-white p-5 shadow-sm"><LoadingSpinner /></div>

    <template v-else>
      <!-- 分组 Tab：分组来自后端 group 字段，顺序即后端 Tab 顺序 -->
      <div
        v-if="groups.length"
        class="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 text-sm"
        data-testid="config-tabs"
      >
        <button
          v-for="g in groups"
          :key="g.label"
          class="rounded-md px-3 py-1.5 transition-colors"
          :class="
            activeTab === g.label
              ? 'bg-white font-medium text-[#1677ff] shadow-sm'
              : 'text-slate-500 hover:text-slate-700'
          "
          :data-testid="`config-tab-${g.label}`"
          @click="activeTab = g.label"
        >
          {{ g.label }}
        </button>
      </div>

      <section
        class="rounded-lg bg-white p-5 shadow-sm"
        :data-testid="`config-panel-${activeTab}`"
      >
        <h2 class="mb-4 flex items-center gap-2 text-sm font-medium text-slate-700">
          <Settings class="h-4 w-4 text-[#1677ff]" />
          {{ activeTab || '系统设置' }}
        </h2>

        <div class="space-y-5">
          <div v-for="c in activeConfigs" :key="c.config_key">
            <!-- 图片类配置（logo）：上传 + 预览 + 移除 -->
            <template v-if="isLogo(c.config_key)">
              <div class="text-[13px] text-slate-500">
                <span class="font-mono text-slate-700">{{ c.config_key }}</span>
                <span v-if="c.description" class="ml-2 text-slate-400">{{ c.description }}</span>
              </div>
              <div class="mt-2 flex items-center gap-3">
                <div
                  class="flex h-16 w-32 items-center justify-center overflow-hidden rounded border border-dashed border-slate-300 bg-slate-50"
                >
                  <img
                    v-if="drafts[c.config_key]"
                    :src="drafts[c.config_key]"
                    alt="logo 预览"
                    class="h-16 w-32 object-contain"
                    :data-testid="`logo-preview-${c.config_key}`"
                  />
                  <ImagePlus v-else class="h-5 w-5 text-slate-300" />
                </div>
                <div class="flex flex-col gap-2">
                  <label
                    class="flex h-8 cursor-pointer items-center gap-1.5 rounded border border-slate-200 px-3 text-[13px] text-slate-600 hover:border-[#1677ff] hover:text-[#1677ff]"
                    :class="{ 'cursor-not-allowed opacity-60': !canManage }"
                  >
                    <ImagePlus class="h-3.5 w-3.5" />
                    {{ uploadingKey === c.config_key ? '上传中…' : '选择图片' }}
                    <input
                      type="file"
                      accept="image/*"
                      class="hidden"
                      :disabled="!canManage || uploadingKey === c.config_key"
                      :data-testid="`logo-input-${c.config_key}`"
                      @change="onLogoChange($event, c.config_key)"
                    />
                  </label>
                  <button
                    v-if="drafts[c.config_key]"
                    class="flex h-8 items-center gap-1.5 self-start rounded px-3 text-[13px] text-slate-500 hover:text-red-500"
                    :data-testid="`logo-clear-${c.config_key}`"
                    @click="clearLogo(c.config_key)"
                  >
                    <X class="h-3.5 w-3.5" /> 移除
                  </button>
                </div>
              </div>
              <p class="mt-1 text-[12px] text-slate-400">
                支持 jpg / png / gif / webp，≤ 5MB；建议使用透明底、高度 40px 左右的图片
              </p>
            </template>

            <!-- 文本类配置 -->
            <label v-else class="block text-[13px] text-slate-500">
              <span class="font-mono text-slate-700">{{ c.config_key }}</span>
              <span v-if="c.description" class="ml-2 text-slate-400">{{ c.description }}</span>
              <input
                v-model="drafts[c.config_key]"
                type="text"
                :disabled="!canManage"
                :data-testid="`config-input-${c.config_key}`"
                class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff] disabled:bg-slate-50 disabled:text-slate-400"
              />
            </label>
          </div>
        </div>

        <p
          v-if="message"
          class="mt-4 rounded bg-blue-50 px-3 py-2 text-[13px] text-[#1677ff]"
          data-testid="config-message"
        >
          {{ message }}
        </p>
        <p
          v-if="errorMsg"
          class="mt-4 rounded bg-red-50 px-3 py-2 text-[13px] text-red-500"
          data-testid="config-error"
        >
          {{ errorMsg }}
        </p>

        <button
          v-if="canManage"
          v-permission="'config.manage'"
          :disabled="saving"
          class="mt-4 flex h-8 items-center gap-1.5 rounded bg-[#1677ff] px-4 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-60"
          data-testid="config-save"
          @click="save"
        >
          <Save class="h-4 w-4" />
          {{ saving ? '保存中…' : '保存配置' }}
        </button>
        <p v-else class="mt-4 text-[13px] text-slate-400">
          当前账号无配置修改权限（config.manage）
        </p>
      </section>
    </template>
  </div>
</template>
