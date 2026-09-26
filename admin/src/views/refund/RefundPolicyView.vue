<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getRefundPolicy, updateRefundPolicy } from '@/api/refund'

/**
 * 退款策略配置页（#6）：refund.* 配置的专属管理入口（单白卡布局，对齐支付日志页）
 * - 自动同意阈值：'0.00' 表示不启用
 * - 修改需 refund.process 权限（按钮仍展示，由后端 403 提示兜底）
 */
const loading = ref(false)
const loaded = ref(false)
const saving = ref(false)
const tip = ref('')
const tipType = ref<'error' | 'success'>('error')

const form = reactive({
  auto_approve_amount: '0.00',
  max_retry: 3,
  dispute_sla_hours: 48,
  return_address_template: '',
})

async function load() {
  loading.value = true
  tip.value = ''
  try {
    const res = await getRefundPolicy()
    Object.assign(form, res.data.data)
    loaded.value = true
  } catch (e) {
    tipType.value = 'error'
    tip.value = e instanceof Error ? e.message : '加载退款策略失败'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  tip.value = ''
  try {
    const res = await updateRefundPolicy({
      auto_approve_amount: form.auto_approve_amount,
      max_retry: form.max_retry,
      dispute_sla_hours: form.dispute_sla_hours,
      return_address_template: form.return_address_template,
    })
    Object.assign(form, res.data.data)
    tipType.value = 'success'
    tip.value = '已保存，立即生效'
  } catch (e) {
    tipType.value = 'error'
    tip.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">退款策略</h2>
    </div>

    <p v-if="tip" data-testid="policy-tip" :class="tipType === 'error' ? 'text-red-500' : 'text-green-600'" class="mb-3 text-sm">{{ tip }}</p>

    <div v-if="loading" class="py-10 text-center text-sm text-slate-400" data-testid="policy-loading">加载中…</div>

    <div v-else-if="loaded" class="max-w-2xl space-y-5" data-testid="policy-form">
      <!-- 自动同意阈值 -->
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">自动同意阈值（元）</label>
        <input
          v-model="form.auto_approve_amount"
          type="number" min="0" step="0.01"
          class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-[#1677ff]"
          data-testid="policy-auto-approve"
        />
        <p class="mt-1 text-xs text-slate-400">买家申请退款的金额不超该值时，系统自动审核通过；0 表示不启用。退货退款自动同意后进入「待买家寄回」。</p>
      </div>

      <!-- 重试上限 -->
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">退款重试上限（次）</label>
        <input
          v-model="form.max_retry"
          type="number" min="0" max="10" step="1"
          class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-[#1677ff]"
          data-testid="policy-max-retry"
        />
        <p class="mt-1 text-xs text-slate-400">渠道退款失败后的自动重试上限，达到上限仍失败将标记转人工处理。</p>
      </div>

      <!-- 纠纷 SLA -->
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">纠纷处理时限（小时）</label>
        <input
          v-model="form.dispute_sla_hours"
          type="number" min="1" max="8760" step="1"
          class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-[#1677ff]"
          data-testid="policy-sla"
        />
        <p class="mt-1 text-xs text-slate-400">退款纠纷开单超过该时限未办结，将在「退款纠纷」列表标记为已超时，需优先跟进。</p>
      </div>

      <!-- 退货地址模板 -->
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">退货地址模板</label>
        <textarea
          v-model="form.return_address_template"
          rows="4"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
          placeholder="买家申请退货退款后展示的寄回地址（支持换行），留空则不下发"
          data-testid="policy-return-address"
        ></textarea>
        <p class="mt-1 text-xs text-slate-400">买家提交退货退款申请成功后，响应中将下发该地址供买家寄件。</p>
      </div>

      <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
        <button
          class="rounded bg-[#1677ff] px-5 py-1.5 text-sm text-white hover:bg-[#4096ff] disabled:opacity-50"
          data-testid="policy-save"
          :disabled="saving"
          @click="save"
        >{{ saving ? '保存中…' : '保存' }}</button>
      </div>
    </div>
  </div>
</template>
