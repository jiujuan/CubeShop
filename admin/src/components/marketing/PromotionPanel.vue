<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { CalendarRange, Pencil, Plus, Trash2 } from 'lucide-vue-next'
import {
  createPromotion, getPromotions, togglePromotion, updatePromotion,
  type AdminPromotion, type PromotionPayload, type PromotionTier,
} from '@/api/marketing'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 满减活动管理 Tab（V1.1 二期 F06 / T-040）
 *
 * 列表（梯度/范围/时间窗/运行态）+ 创建/编辑弹层（**多级梯度编辑器**：增删行、
 * 门槛严格递增校验）+ 运行启停。
 */
const list = ref<AdminPromotion[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const loading = ref(true)
const tip = ref('')

const formOpen = ref(false)
const editing = ref<AdminPromotion | null>(null)
const saving = ref(false)
const formError = ref('')

const form = ref<PromotionPayload>(emptyForm())

function emptyForm(): PromotionPayload {
  return {
    name: '',
    rules: [{ min: 100, discount: 10 }],
    scope: 'all',
    scope_refs: [],
    start_at: '',
    end_at: '',
  }
}

const confirmState = ref<{ title: string; message: string; run: () => Promise<void> } | null>(null)

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const res = await getPromotions({ page: pagination.value.page, page_size: pagination.value.page_size })
    list.value = res.data.data.list
    pagination.value = res.data.data.pagination
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  formError.value = ''
  formOpen.value = true
}

function openEdit(p: AdminPromotion) {
  editing.value = p
  formError.value = ''
  form.value = {
    name: p.name,
    rules: p.rules.map((r) => ({ ...r })),
    scope: p.scope,
    scope_refs: [...p.scope_refs],
    start_at: p.start_at,
    end_at: p.end_at,
    status: p.status,
  }
  formOpen.value = true
}

/** 梯度编辑：新增行（门槛默认接在最后一级之后） */
function addTier() {
  const rules = form.value.rules
  const last = rules[rules.length - 1]
  rules.push({ min: last ? Number(last.min) + 100 : 100, discount: last ? Number(last.discount) + 5 : 10 })
}

function removeTier(i: number) {
  if (form.value.rules.length <= 1) return
  form.value.rules.splice(i, 1)
}

/** 本地校验：门槛必须严格递增（与后端 assertRules 同口径） */
function validateTiers(): string | null {
  const mins = form.value.rules.map((r: PromotionTier) => Number(r.min))
  const discounts = form.value.rules.map((r: PromotionTier) => Number(r.discount))
  if (mins.some((m) => !(m > 0))) return '梯度门槛必须大于 0'
  if (discounts.some((d) => !(d > 0))) return '梯度优惠金额必须大于 0'
  for (let i = 1; i < mins.length; i++) {
    if (mins[i] <= mins[i - 1]) return '满减梯度必须按门槛从小到大排列（严格递增）'
  }
  return null
}

async function save() {
  formError.value = ''
  const f = form.value
  if (!f.name.trim()) { formError.value = '请填写活动名称'; return }
  const tierError = validateTiers()
  if (tierError) { formError.value = tierError; return }
  if (!f.start_at || !f.end_at) { formError.value = '请填写活动起止时间'; return }
  if (f.end_at <= f.start_at) { formError.value = '结束时间必须晚于开始时间'; return }

  saving.value = true
  try {
    if (editing.value) await updatePromotion(editing.value.id, f)
    else await createPromotion(f)
    formOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

function askToggle(p: AdminPromotion) {
  const next = p.status === 'active' ? '停用' : '启用'
  confirmState.value = {
    title: `${next}活动`,
    message: `确定${next}「${p.name}」？`,
    run: async () => {
      await togglePromotion(p.id)
      await load()
    },
  }
}

function rulesText(p: AdminPromotion): string {
  return p.rules.map((r) => `满${r.min}减${r.discount}`).join(' / ')
}

defineExpose({ load, openCreate, openEdit, form, validateTiers, addTier, removeTier })
</script>

<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <p class="text-xs text-slate-400">多活动并存时用户订单取优惠最大的一个，不叠加</p>
      <button
        class="flex items-center gap-1 rounded-md bg-[#1677ff] px-4 py-2 text-sm text-white hover:bg-[#4096ff]"
        data-testid="promotion-create-btn"
        @click="openCreate"
      ><Plus class="h-4 w-4" /> 新建活动</button>
    </div>

    <p v-if="tip" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ tip }}</p>

    <LoadingSpinner v-if="loading" />

    <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="w-full text-left text-[13px]">
        <thead class="bg-slate-50 text-slate-500">
          <tr>
            <th class="px-4 py-2.5">活动名称</th>
            <th class="px-4 py-2.5">满减梯度</th>
            <th class="px-4 py-2.5">活动时间</th>
            <th class="px-4 py-2.5">状态</th>
            <th class="px-4 py-2.5">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in list" :key="p.id" class="border-t border-slate-100" :data-testid="`promotion-row-${p.id}`">
            <td class="px-4 py-2.5 font-medium text-slate-700">{{ p.name }}</td>
            <td class="px-4 py-2.5 text-[#ff4d4f]" :data-testid="`promotion-rules-${p.id}`">{{ rulesText(p) }}</td>
            <td class="px-4 py-2.5 text-slate-500">
              <span class="flex items-center gap-1"><CalendarRange class="h-3.5 w-3.5" /> {{ p.start_at.slice(0, 10) }} ~ {{ p.end_at.slice(0, 10) }}</span>
            </td>
            <td class="px-4 py-2.5">
              <span class="rounded px-1.5 py-0.5 text-xs" :class="p.status === 'active' ? (p.running ? 'bg-green-50 text-green-600' : 'bg-blue-50 text-[#1677ff]') : 'bg-slate-100 text-slate-400'">
                {{ p.status === 'active' ? (p.running ? '进行中' : '未开始/已结束') : '已停用' }}
              </span>
            </td>
            <td class="px-4 py-2.5">
              <div class="flex items-center gap-2 text-[#1677ff]">
                <button class="hover:underline" :data-testid="`promotion-edit-${p.id}`" @click="openEdit(p)"><Pencil class="h-3.5 w-3.5" /></button>
                <button class="text-slate-400 hover:text-[#1677ff]" :data-testid="`promotion-toggle-${p.id}`" @click="askToggle(p)">{{ p.status === 'active' ? '停用' : '启用' }}</button>
              </div>
            </td>
          </tr>
          <tr v-if="!list.length">
            <td colspan="5" class="px-4 py-10 text-center text-slate-400" data-testid="promotion-empty">暂无满减活动</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 创建/编辑弹层 -->
    <Teleport to="body">
      <div v-if="formOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="formOpen = false">
        <div class="max-h-[88vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" data-testid="promotion-form-dialog">
          <h3 class="mb-4 text-base font-semibold text-slate-800">{{ editing ? '编辑满减活动' : '新建满减活动' }}</h3>

          <p v-if="formError" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="promotion-form-error">{{ formError }}</p>

          <div class="space-y-3 text-[13px]">
            <label class="block">
              <span class="mb-1 block text-slate-500">活动名称 *</span>
              <input v-model="form.name" type="text" maxlength="100" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="promotion-form-name" />
            </label>

            <!-- 多级梯度编辑器 -->
            <div>
              <div class="mb-1 flex items-center justify-between">
                <span class="text-slate-500">满减梯度 *（门槛从小到大，严格递增）</span>
                <button class="flex items-center gap-1 text-xs text-[#1677ff] hover:underline" data-testid="promotion-tier-add" @click="addTier"><Plus class="h-3 w-3" /> 加一档</button>
              </div>
              <div
                v-for="(tier, i) in form.rules" :key="i"
                class="mb-2 flex items-center gap-2"
                :data-testid="`promotion-tier-row-${i}`"
              >
                <span class="text-slate-400">满</span>
                <input v-model.number="tier.min" type="number" min="0.01" class="w-28 rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" :data-testid="`promotion-tier-min-${i}`" />
                <span class="text-slate-400">减</span>
                <input v-model.number="tier.discount" type="number" min="0.01" class="w-28 rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]" :data-testid="`promotion-tier-discount-${i}`" />
                <button
                  v-if="form.rules.length > 1"
                  class="text-slate-300 hover:text-red-500"
                  :data-testid="`promotion-tier-remove-${i}`"
                  @click="removeTier(i)"
                ><Trash2 class="h-4 w-4" /></button>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <label class="block">
                <span class="mb-1 block text-slate-500">开始时间 *</span>
                <input v-model="form.start_at" type="datetime-local" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="promotion-form-start" />
              </label>
              <label class="block">
                <span class="mb-1 block text-slate-500">结束时间 *</span>
                <input v-model="form.end_at" type="datetime-local" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#1677ff]" data-testid="promotion-form-end" />
              </label>
            </div>
          </div>

          <div class="mt-5 flex justify-end gap-3">
            <button class="rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-500" data-testid="promotion-form-cancel" @click="formOpen = false">取消</button>
            <button class="rounded-md bg-[#1677ff] px-5 py-2 text-sm text-white hover:bg-[#4096ff] disabled:opacity-50" :disabled="saving" data-testid="promotion-form-save" @click="save">{{ saving ? '保存中…' : '保存' }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title"
      :message="confirmState?.message"
      @confirm="() => { confirmState?.run(); confirmState = null }"
      @cancel="confirmState = null"
    />
  </div>
</template>
