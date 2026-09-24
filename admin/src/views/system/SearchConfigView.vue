<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { DatabaseZap, RefreshCw, Search } from 'lucide-vue-next'
import {
  getSearchConfig,
  getSearchKeywords,
  reindexSearch,
  updateSearchConfig,
  type SearchConfigData,
  type SearchConfigUpdatePayload,
  type SearchEngineKey,
  type SearchKeywordRow,
} from '@/api/search'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 搜索配置（V1.2 站内搜索 S1-09 后半，权限 search.manage）
 *
 * 后端接口见 app/Http/Controllers/Admin/SearchController.php；
 * 设计文档 docs/design/CubeShop_Search_Design_v1.0.md §6。
 *
 * ⚠️ 两条与后端联动的硬约定（页面上都有对应提示，不是可省的装饰）：
 * 1. **任何保存都会递增 `index_version`** —— 命中集缓存 key 含版本号，
 *    所以前台立即读到新配置算出的结果（也意味着旧缓存全部失效，无需手动清）。
 * 2. **改「品牌/分类名入索引」后必须重建索引** —— 该开关决定索引列内容，
 *    只保存不重建的话索引里还是旧内容。保存该开关后本页会置顶提示。
 */
const auth = useAuthStore()
const canManage = computed(() => auth.hasPermission('search.manage'))

// ---------------- 提示 ----------------
const tip = ref('')
const tipOk = ref(true)
function notify(text: string, ok = true) {
  tip.value = text
  tipOk.value = ok
  setTimeout(() => (tip.value = ''), 3200)
}

// ---------------- 配置 ----------------
const loading = ref(false)
const saving = ref(false)
const config = ref<SearchConfigData | null>(null)

/** 本地编辑态：引擎 key（'' = 自动）+ 开关值 */
const editEngine = ref('')
const editSwitches = reactive<Record<string, string>>({})

/** 开关的展示定义：顺序即渲染顺序（数值项用 number 输入框） */
const switchDefs: Array<{ key: string; label: string; desc: string; type: 'bool' | 'number' }> = [
  {
    key: 'search.index_taxonomy_names',
    label: '品牌 / 分类名参与检索',
    desc: '关闭后搜品牌名、分类名将不再命中相关商品。改动后必须重建索引才生效。',
    type: 'bool',
  },
  {
    key: 'search.cache_ttl',
    label: '命中集缓存时长（秒）',
    desc: '同一关键词 + 筛选组合的结果缓存。0 = 不缓存；调大可降低数据库压力。',
    type: 'number',
  },
  {
    key: 'search.expose_debug',
    label: '向前台暴露调试字段',
    desc: '开启后 /search 响应附带 engine / relaxed 等调试信息，便于联调排查；日常应保持关闭。',
    type: 'bool',
  },
]

async function load() {
  loading.value = true
  try {
    const { data } = await getSearchConfig()
    config.value = data.data
    editEngine.value = data.data.engine
    for (const def of switchDefs) {
      editSwitches[def.key] = data.data.switches[def.key] ?? ''
    }
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  loadKeywords(1)
})

/** 是否有未保存改动（引擎或任一开关） */
const dirty = computed(() => {
  if (!config.value) return false
  if (editEngine.value !== config.value.engine) return true
  return switchDefs.some((def) => editSwitches[def.key] !== config.value!.switches[def.key])
})

/** 改了 taxonomy 开关 → 提示必须重建索引 */
const taxonomyDirty = computed(
  () =>
    !!config.value &&
    editSwitches['search.index_taxonomy_names'] !== config.value.switches['search.index_taxonomy_names'],
)

async function save() {
  if (!dirty.value || saving.value) return
  saving.value = true
  try {
    const payload: SearchConfigUpdatePayload = {}
    if (editEngine.value !== config.value?.engine) {
      // '' 与 'auto' 在后端同义（都表示「自动」）；HTTP 层只能传 auto
      payload.engine = editEngine.value === '' ? 'auto' : (editEngine.value as SearchEngineKey)
    }
    if (config.value) {
      const changed = switchDefs
        .filter((def) => editSwitches[def.key] !== config.value!.switches[def.key])
        .map((def) => def.key)
      if (changed.length > 0) {
        // ⚠️ number 输入框的 v-model 产出数字，后端 config_value 是字符串列 —— 统一转字符串
        payload.switches = Object.fromEntries(changed.map((key) => [key, String(editSwitches[key])]))
      }
    }
    const { data } = await updateSearchConfig(payload)
    notify(`已保存，索引版本 → v${data.data.index_version}`)
    await load()
  } finally {
    saving.value = false
  }
}

function resetEdit() {
  if (!config.value) return
  editEngine.value = config.value.engine
  for (const def of switchDefs) {
    editSwitches[def.key] = config.value.switches[def.key] ?? ''
  }
}

// ---------------- 热搜词 ----------------
const kwLoading = ref(false)
const kwItems = ref<SearchKeywordRow[]>([])
const kwPagination = ref({ page: 1, page_size: 20, total: 0 as number | null, total_pages: 1 as number | null })
const kwFilter = ref('')

async function loadKeywords(page = 1) {
  kwLoading.value = true
  try {
    const { data } = await getSearchKeywords({
      keyword: kwFilter.value || undefined,
      page,
      page_size: kwPagination.value.page_size,
    })
    kwItems.value = data.data.list
    kwPagination.value = data.data.pagination
  } finally {
    kwLoading.value = false
  }
}

/** hit 高但 result_count 为 0 = 有人搜、搜不到（运营最该关注的一行） */
const noResultCount = computed(() => kwItems.value.filter((row) => row.hit_count > 0 && row.result_count === 0).length)

// ---------------- 重建索引 ----------------
const reindexOpen = ref(false)
const reindexing = ref(false)

async function doReindex() {
  reindexing.value = true
  try {
    const { data } = await reindexSearch()
    notify(`重建完成：${data.data.updated} 条，耗时 ${data.data.elapsed_ms}ms`)
    reindexOpen.value = false
    await load()
  } finally {
    reindexing.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">搜索配置</h2>
        <p class="mt-0.5 text-[13px] text-slate-500">
          站内检索引擎与运行参数。任何保存都会递增索引版本，前台立即生效；改「品牌 / 分类名入索引」后需重建索引。
        </p>
      </div>
      <div class="flex items-center gap-2">
        <Button variant="outline" :disabled="loading" data-testid="search-reload" @click="load()">
          <RefreshCw class="mr-1 h-4 w-4" /> 刷新
        </Button>
        <Button
          class="bg-[#1677ff] hover:bg-[#4096ff]"
          :disabled="!canManage || !dirty || saving"
          data-testid="search-save"
          @click="save"
        >
          {{ saving ? '保存中…' : '保存配置' }}
        </Button>
      </div>
    </div>

    <LoadingSpinner v-if="loading && !config" />

    <!-- 轻提示（保存 / 重建结果），3 秒自动消失 -->
    <div
      v-if="tip"
      class="mb-3 rounded-lg border px-4 py-2.5 text-[13px]"
      :class="tipOk ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-rose-300 bg-rose-50 text-rose-800'"
      data-testid="search-tip"
      role="status"
    >
      {{ tip }}
    </div>

    <template v-if="config">
      <!-- 降级提示 -->
      <div
        v-if="config.degraded"
        class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-[13px] text-amber-800"
        data-testid="search-degraded"
      >
        ⚠️ 当前处于降级状态：配置引擎为「{{ config.engine || '自动' }}」，但实际生效的是
        <b>{{ config.active_engine === 'postgres' ? 'PostgreSQL 全文检索' : 'LIKE 降级引擎' }}</b>
        （PG 不可用或环境未就绪时自动降级，恢复后自动切回）。
      </div>
      <!-- 改了 taxonomy 开关但尚未重建 -->
      <div
        v-if="taxonomyDirty"
        class="mb-3 rounded-lg border border-sky-300 bg-sky-50 px-4 py-2.5 text-[13px] text-sky-800"
        data-testid="search-taxonomy-dirty"
      >
        ⓘ 已修改「品牌 / 分类名参与检索」：保存后请点击右下角「重建索引」，否则索引内容仍是旧的。
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <!-- 引擎 -->
        <section class="rounded-lg bg-white p-5 shadow-sm">
          <h3 class="text-[15px] font-semibold text-slate-800">检索引擎</h3>
          <p class="mt-0.5 text-[12px] text-slate-500">切换引擎会整体改变全站检索行为；索引版本会随之递增。</p>
          <div class="mt-3 space-y-2" data-testid="search-engines">
            <label
              v-for="opt in config.engines"
              :key="opt.key || 'auto'"
              class="flex cursor-pointer items-start gap-3 rounded-md border p-3 text-[13px] transition-colors"
              :class="
                editEngine === opt.key || (opt.key === '' && editEngine === 'auto')
                  ? 'border-[#1677ff] bg-blue-50/50'
                  : 'border-slate-200 hover:border-slate-300'
              "
            >
              <input
                v-model="editEngine"
                type="radio"
                name="search-engine"
                :value="opt.key"
                :disabled="!canManage || !opt.available"
                class="mt-0.5"
                :data-testid="`search-engine-${opt.key || 'auto'}`"
              />
              <span>
                <span class="font-medium text-slate-800">{{ opt.label }}</span>
                <span v-if="!opt.available" class="ml-2 text-[12px] text-amber-700">（当前环境不可用）</span>
              </span>
            </label>
          </div>
          <p class="mt-3 text-[12px] text-slate-500">
            实际生效：<b class="text-slate-700">{{ config.active_engine || '自动' }}</b>
            <span v-if="config.engine">（配置：{{ config.engine }}）</span>
            · 索引版本 <b class="text-slate-700">v{{ config.index_version }}</b>
          </p>
        </section>

        <!-- 开关 -->
        <section class="rounded-lg bg-white p-5 shadow-sm">
          <h3 class="text-[15px] font-semibold text-slate-800">运行参数</h3>
          <div class="mt-3 space-y-4" data-testid="search-switches">
            <div v-for="def in switchDefs" :key="def.key">
              <label class="flex items-center gap-2 text-[13px] font-medium text-slate-800">
                <input
                  v-if="def.type === 'bool'"
                  v-model="editSwitches[def.key]"
                  type="checkbox"
                  true-value="1"
                  false-value="0"
                  :disabled="!canManage"
                  :data-testid="`search-switch-${def.key}`"
                />
                {{ def.label }}
              </label>
              <input
                v-if="def.type === 'number'"
                v-model="editSwitches[def.key]"
                type="number"
                min="0"
                :disabled="!canManage"
                class="mt-1 w-32 rounded-md border border-slate-300 px-2 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
                :data-testid="`search-input-${def.key}`"
              />
              <p class="mt-0.5 text-[12px] text-slate-500">{{ def.desc }}</p>
            </div>
          </div>
          <div v-if="dirty" class="mt-4 flex items-center gap-2" data-testid="search-dirty-actions">
            <Button variant="outline" size="sm" :disabled="saving" @click="resetEdit">放弃改动</Button>
          </div>
        </section>
      </div>

      <!-- 热搜词 + 重建 -->
      <section class="mt-4 rounded-lg bg-white p-5 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <div>
            <h3 class="text-[15px] font-semibold text-slate-800">热搜词</h3>
            <p class="mt-0.5 text-[12px] text-slate-500">
              按搜索次数倒序。<span v-if="noResultCount > 0" class="text-amber-700">
                当前页有 {{ noResultCount }} 个「有人搜但搜不到」的词，优先补商品或配同义词。</span>
            </p>
          </div>
          <div class="flex items-center gap-2">
            <div class="relative">
              <Search class="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
              <input
                v-model="kwFilter"
                class="w-52 rounded-md border border-slate-300 py-1.5 pl-8 pr-2 text-[13px] outline-none focus:border-[#1677ff]"
                placeholder="按关键词筛选"
                data-testid="search-kw-filter"
                @keyup.enter="loadKeywords(1)"
              />
            </div>
            <Button variant="outline" :disabled="!canManage || reindexing" data-testid="search-reindex-open" @click="reindexOpen = true">
              <DatabaseZap class="mr-1 h-4 w-4" /> 重建索引
            </Button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="border-b border-slate-200 text-left text-[12px] text-slate-500">
                <th class="py-2 pr-4 font-medium">关键词</th>
                <th class="py-2 pr-4 font-medium">搜索次数</th>
                <th class="py-2 pr-4 font-medium">最近命中数</th>
                <th class="py-2 pr-4 font-medium">最近搜索</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in kwItems"
                :key="row.id"
                class="border-b border-slate-100"
                :class="row.hit_count > 0 && row.result_count === 0 ? 'bg-amber-50/60' : ''"
                :data-testid="`search-kw-row-${row.id}`"
              >
                <td class="py-2 pr-4 font-medium text-slate-800">{{ row.keyword }}</td>
                <td class="py-2 pr-4 text-slate-600">{{ row.hit_count }}</td>
                <td class="py-2 pr-4">
                  <span :class="row.result_count === 0 && row.hit_count > 0 ? 'font-medium text-amber-700' : 'text-slate-600'">
                    {{ row.result_count }}{{ row.hit_count > 0 && row.result_count === 0 ? '（搜不到）' : '' }}
                  </span>
                </td>
                <td class="py-2 pr-4 text-slate-500">{{ row.last_hit_at ?? '—' }}</td>
              </tr>
              <tr v-if="kwItems.length === 0 && !kwLoading">
                <td colspan="4" class="py-6 text-center text-slate-400">暂无搜索记录</td>
              </tr>
            </tbody>
          </table>
        </div>
        <TablePagination
          v-if="(kwPagination.total ?? 0) > 0"
          class="mt-3"
          :pagination="{ ...kwPagination, total: kwPagination.total ?? 0, total_pages: kwPagination.total_pages ?? 1 }"
          @change="loadKeywords"
        />
      </section>
    </template>

    <!-- 重建确认 -->
    <ConfirmDialog
      :open="reindexOpen"
      title="重建检索索引"
      message="将对全部商品（含已下架）重新生成检索索引，并递增索引版本使旧缓存失效。万级商品秒级完成。确认执行？"
      confirm-text="开始重建"
      @confirm="doReindex"
      @cancel="reindexOpen = false"
    />
  </div>
</template>
