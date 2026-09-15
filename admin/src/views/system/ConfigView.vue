<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Save, Settings } from 'lucide-vue-next'
import { getConfigs, updateConfigs, type SystemConfig } from '@/api/admin'
import { useAuthStore } from '@/stores/auth'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 系统配置页（权限 config.manage，配合 v-permission 演示按钮级控制）
 */
const auth = useAuthStore()
const configs = ref<SystemConfig[]>([])
const drafts = reactive<Record<string, string>>({})
const loading = ref(true)
const saving = ref(false)
const message = ref('')
const canManage = auth.hasPermission('config.manage')

onMounted(async () => {
  try {
    const res = await getConfigs()
    configs.value = res.data.data
    configs.value.forEach((c) => (drafts[c.config_key] = c.config_value))
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value = true
  message.value = ''
  try {
    await updateConfigs(
      configs.value.map((c) => ({ config_key: c.config_key, config_value: drafts[c.config_key] })),
    )
    message.value = '配置已保存并即时生效'
  } catch (e) {
    message.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-lg font-semibold">系统配置</h1>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-3 flex items-center gap-2 text-sm font-medium">
        <Settings class="h-4 w-4 text-[#1677ff]" />
        运营参数
      </h2>

      <div v-if="loading"><LoadingSpinner /></div>

      <template v-else>
        <div class="space-y-3">
          <label v-for="c in configs" :key="c.config_key" class="block text-[13px] text-slate-500">
            <span class="font-mono text-slate-700">{{ c.config_key }}</span>
            <span v-if="c.description" class="ml-2 text-slate-400">{{ c.description }}</span>
            <input
              v-model="drafts[c.config_key]"
              type="text"
              :disabled="!canManage"
              class="mt-1 h-9 w-full rounded border border-slate-200 px-2 text-sm outline-none focus:border-[#1677ff] disabled:bg-slate-50 disabled:text-slate-400"
            />
          </label>
        </div>

        <p v-if="message" class="mt-3 rounded bg-blue-50 px-3 py-2 text-[13px] text-[#1677ff]">{{ message }}</p>

        <button
          v-if="canManage"
          v-permission="'config.manage'"
          :disabled="saving"
          class="mt-4 flex h-8 items-center gap-1.5 rounded bg-[#1677ff] px-4 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-60"
          @click="save"
        >
          <Save class="h-4 w-4" />
          {{ saving ? '保存中…' : '保存配置' }}
        </button>
        <p v-else class="mt-4 text-[13px] text-slate-400">当前账号无配置修改权限（config.manage）</p>
      </template>
    </section>
  </div>
</template>
