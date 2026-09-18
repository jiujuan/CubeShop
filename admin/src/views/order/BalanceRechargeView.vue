<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Download, Search } from 'lucide-vue-next'
import {
  exportBalanceRecharges,
  getBalanceRecharge,
  getBalanceRecharges,
  reviewBalanceRecharge,
  RECHARGE_CHANNEL_LABELS,
  RECHARGE_STATUS_CLASS,
  RECHARGE_STATUS_LABELS,
  type BalanceRechargeDetail,
  type BalanceRechargeRow,
  type RechargeChannel,
  type RechargeStatus,
} from '@/api/payment'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 余额充值单管理（§5.2 / §6.5，权限 balance.recharge.view；核账 payment.offline.review）
 */
const loading = ref(true)
const list = ref<BalanceRechargeRow[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const error = ref('')

const rechargeNo = ref('')
const channelFilter = ref<'' | RechargeChannel>('')
const statusFilter = ref<'' | RechargeStatus>('')
const startDate = ref('')
const endDate = ref('')

const detail = ref<BalanceRechargeDetail | null>(null)
const detailLoading = ref(false)
const reviewTarget = ref<BalanceRechargeRow | null>(null)
const reviewPass = ref(true)
const reviewRemark = ref('')
const reviewing = ref(false)
const reviewError = ref('')

const statusTabs: Array<{ value: '' | RechargeStatus; label: string }> = [
  { value: '', label: '全部' },
  ...(Object.keys(RECHARGE_STATUS_LABELS) as RechargeStatus[]).map((s) => ({ value: s, label: RECHARGE_STATUS_LABELS[s] })),
]

const queryParams = computed(() => ({
  recharge_no: rechargeNo.value.trim() || undefined,
  channel: channelFilter.value || undefined,
  status: statusFilter.value || undefined,
  start_time: startDate.value ? `${startDate.value} 00:00:00` : undefined,
  end_time: endDate.value ? `${endDate.value} 23:59:59` : undefined,
  page_size: pagination.value.page_size,
}))

async function load(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getBalanceRecharges({ ...queryParams.value, page })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } catch (e) {
    error.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

function money(v: string | number): string {
  const n = typeof v === 'number' ? v : Number.parseFloat(v)
  if (Number.isNaN(n)) return String(v)
  return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function search() { load(1) }
function reset() {
  rechargeNo.value = ''
  channelFilter.value = ''
  statusFilter.value = ''
  startDate.value = ''
  endDate.value = ''
  load(1)
}
function filterStatus(s: '' | RechargeStatus) { statusFilter.value = s; load(1) }
function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

async function openDetail(row: BalanceRechargeRow) {
  detailLoading.value = true
  detail.value = null
  try {
    const { data } = await getBalanceRecharge(row.id)
    detail.value = data.data
  } finally {
    detailLoading.value = false
  }
}

function openReview(row: BalanceRechargeRow) {
  reviewTarget.value = row
  reviewPass.value = true
  reviewRemark.value = ''
  reviewError.value = ''
}

async function doReview() {
  if (!reviewTarget.value) return
  reviewing.value = true
  reviewError.value = ''
  const id = reviewTarget.value.id
  try {
    await reviewBalanceRecharge(id, reviewPass.value, reviewPass.value ? undefined : reviewRemark.value.trim())
    reviewTarget.value = null
    if (detail.value?.id === id) detail.value = null
    await load(pagination.value.page)
  } catch (e) {
    reviewError.value = e instanceof Error ? e.message : '核账失败'
  } finally {
    reviewing.value = false
  }
}

async function doExport() {
  await exportBalanceRecharges(queryParams.value, `充值单导出-${new Date().toISOString().slice(0, 10)}.csv`)
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">余额充值单</h2>
      <Button variant="outline" @click="doExport"><Download class="mr-1 h-4 w-4" /> 导出 CSV</Button>
    </div>

    <p v-if="error" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-500">{{ error }}</p>

    <!-- 筛选 -->
    <div class="mb-3 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="rechargeNo" type="text" placeholder="充值单号"
        class="w-44 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <select v-model="channelFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">全部渠道</option>
        <option v-for="(label, value) in RECHARGE_CHANNEL_LABELS" :key="value" :value="value">{{ label }}</option>
      </select>
      <select v-model="statusFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">全部状态</option>
        <option v-for="tab in statusTabs.slice(1)" :key="tab.value" :value="tab.value">{{ tab.label }}</option>
      </select>
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <input v-model="startDate" type="date" class="outline-none" />
        <span class="text-slate-300">–</span>
        <input v-model="endDate" type="date" class="outline-none" />
      </div>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="search"><Search class="mr-1 h-4 w-4" /> 搜索</Button>
      <Button variant="outline" @click="reset">重置</Button>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
      <button
        v-for="tab in statusTabs" :key="tab.value"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="statusFilter === tab.value ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
        @click="filterStatus(tab.value)"
      >{{ tab.label }}</button>
    </div>

    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">充值单号</th>
          <th class="px-3 py-1.5">用户</th>
          <th class="px-3 py-1.5">本金</th>
          <th class="px-3 py-1.5">赠送</th>
          <th class="px-3 py-1.5">到账</th>
          <th class="px-3 py-1.5">渠道</th>
          <th class="px-3 py-1.5">状态</th>
          <th class="px-3 py-1.5">时间</th>
          <th class="px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ row.recharge_no }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.user_name || ('#' + row.user_id) }}</td>
          <td class="px-3 py-1.5 text-black">¥{{ money(row.amount) }}</td>
          <td class="px-3 py-1.5 text-black">¥{{ money(row.gift_amount) }}</td>
          <td class="px-3 py-1.5 font-medium text-black">¥{{ money(row.total) }}</td>
          <td class="px-3 py-1.5 text-black">{{ row.channel_label }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="RECHARGE_STATUS_CLASS[row.status]">{{ row.status_label }}</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ row.created_at }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button class="hover:underline" @click="openDetail(row)">详情</button>
              <template v-if="row.status === 'reviewing'">
                <span class="text-slate-200">|</span>
                <button v-permission="'payment.offline.review'" class="text-orange-500 hover:underline" @click="openReview(row)">核账</button>
              </template>
            </div>
          </td>
        </tr>
        <tr v-if="loading"><td colspan="9"><LoadingSpinner /></td></tr>
        <tr v-if="!list.length && !loading">
          <td colspan="9" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <TablePagination :pagination="pagination" :total-text="`共 ${pagination.total} 条记录`" @change="goPage" />

    <!-- 详情 -->
    <div v-if="detail || detailLoading" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="detail = null">
      <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-base font-semibold">充值单详情</h2>
          <button class="text-slate-400 hover:text-slate-600" @click="detail = null">✕</button>
        </div>
        <template v-if="detail">
          <div class="mb-4 grid grid-cols-2 gap-y-1 text-[13px]">
            <p><span class="text-slate-400">充值单号：</span><span class="font-mono text-black">{{ detail.recharge_no }}</span></p>
            <p><span class="text-slate-400">用户：</span><span class="text-black">{{ detail.user_name || ('#' + detail.user_id) }}</span></p>
            <p><span class="text-slate-400">本金：</span><span class="text-black">¥{{ money(detail.amount) }}</span></p>
            <p><span class="text-slate-400">赠送：</span><span class="text-black">¥{{ money(detail.gift_amount) }}</span></p>
            <p><span class="text-slate-400">到账：</span><span class="font-medium text-black">¥{{ money(detail.total) }}</span></p>
            <p>
              <span class="text-slate-400">状态：</span>
              <span class="rounded px-1.5 py-0.5 text-xs" :class="RECHARGE_STATUS_CLASS[detail.status]">{{ detail.status_label }}</span>
            </p>
          </div>

          <div v-if="detail.payment" class="mb-4 rounded-lg bg-slate-50 p-3 text-[13px]">
            <p><span class="text-slate-400">关联支付单：</span><span class="font-mono text-black">{{ detail.payment.payment_no }}</span></p>
            <p class="mt-1"><span class="text-slate-400">渠道交易号：</span><span class="font-mono text-black">{{ detail.payment.channel_trade_no || '-' }}</span></p>
            <p v-if="detail.payment.review_remark" class="mt-1"><span class="text-slate-400">核账备注：</span><span class="text-black">{{ detail.payment.review_remark }}</span></p>
          </div>

          <div v-if="detail.voucher_url || detail.payer_name" class="mb-4 rounded-lg border border-slate-100 p-3 text-[13px]">
            <p class="mb-1 font-medium text-slate-600">转账凭证</p>
            <p><span class="text-slate-400">付款人：</span><span class="text-black">{{ detail.payer_name || '-' }}</span></p>
            <p><span class="text-slate-400">付款账号：</span><span class="text-black">{{ detail.payer_account || '-' }}</span></p>
            <p><span class="text-slate-400">流水号：</span><span class="text-black">{{ detail.transfer_no || '-' }}</span></p>
            <p><span class="text-slate-400">转账时间：</span><span class="text-black">{{ detail.transferred_at || '-' }}</span></p>
            <img v-if="detail.voucher_url" :src="detail.voucher_url" class="mt-2 max-h-48 rounded border border-slate-200" alt="凭证" />
          </div>

          <div v-if="detail.balance_log" class="mb-4 rounded-lg bg-green-50 p-3 text-[13px]">
            <p><span class="text-slate-400">入账流水：</span><span class="text-black">{{ detail.balance_log.amount }}（余额 {{ detail.balance_log.balance_after }}）</span></p>
          </div>

          <div v-if="detail.status === 'reviewing'" class="flex justify-end">
            <Button class="bg-orange-500 px-4 hover:bg-orange-600" v-permission="'payment.offline.review'" @click="openReview({ id: detail.id, status: detail.status, recharge_no: detail.recharge_no } as BalanceRechargeRow)">去核账</Button>
          </div>
        </template>
        <LoadingSpinner v-else />
      </div>
    </div>

    <!-- 核账确认 -->
    <div v-if="reviewTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="reviewTarget = null">
      <div class="w-full max-w-md rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">线下充值核账</h3>
        <p class="mt-2 text-[13px] text-slate-500">
          充值单 <span class="font-mono text-slate-700">{{ reviewTarget.recharge_no }}</span>
          {{ reviewPass ? '通过后将自动入账（本金 + 赠送）。' : '驳回后充值单置为失败，用户可重新提交。' }}
        </p>
        <div class="mt-3 flex gap-2">
          <label class="flex items-center gap-1 text-[13px]"><input v-model="reviewPass" type="radio" :value="true" class="h-4 w-4" />通过</label>
          <label class="flex items-center gap-1 text-[13px]"><input v-model="reviewPass" type="radio" :value="false" class="h-4 w-4" />驳回</label>
        </div>
        <input
          v-if="!reviewPass"
          v-model="reviewRemark"
          placeholder="驳回原因（必填）"
          class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
        />
        <p v-if="reviewError" class="mt-2 text-xs text-red-500">{{ reviewError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="reviewTarget = null">取消</button>
          <button
            class="rounded-md px-4 py-1.5 text-[13px] text-white hover:opacity-90 disabled:opacity-50"
            :class="reviewPass ? 'bg-green-600' : 'bg-red-500'"
            :disabled="reviewing || (!reviewPass && !reviewRemark.trim())"
            @click="doReview"
          >{{ reviewing ? '处理中…' : (reviewPass ? '确认通过' : '确认驳回') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
