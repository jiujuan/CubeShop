<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  acceptOrder,
  exportOrders,
  getEnabledShippingCompanies,
  getOrders,
  ORDER_STATUS_CLASS,
  ORDER_STATUS_LABELS,
  shipOrder,
  type AdminOrder,
  type EnabledShippingCompany,
  type OrderStatus,
} from '@/api/order'
import { Check, Copy, Download, PackageCheck, Search, Send } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import TablePagination from '@/components/TablePagination.vue'

/**
 * 订单管理（Roadmap P6）：多条件筛选、详情、发货、导出
 */
const router = useRouter()
const route = useRoute()
const loading = ref(true)
const list = ref<AdminOrder[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })

// 筛选条件（支持从仪表盘待办卡带状态直达：/orders?status=pending_ship）
const queryStatus = String(route.query.status ?? '')
const orderNo = ref('')
const statusFilter = ref<'' | OrderStatus>(
  queryStatus !== '' && queryStatus in ORDER_STATUS_LABELS ? (queryStatus as OrderStatus) : '',
)
const startDate = ref('')
const endDate = ref('')

// 发货 / 受理备货
const shipTarget = ref<AdminOrder | null>(null)
const shipCompanies = ref<EnabledShippingCompany[]>([])
const shipCompanyCode = ref('')
const shipTrackingNo = ref('')
const shipTrackingTouched = ref(false)
const shipRemark = ref('')
const shipConfirmStep = ref(false)
const shipping = ref(false)
const acceptTarget = ref<AdminOrder | null>(null)
const acceptRemark = ref('')
const accepting = ref(false)
const exporting = ref(false)

// 列表物流单号复制反馈（order_id → 已复制）
const copiedOrderId = ref<number | null>(null)

/** 快递单号：8~32 位字母数字 */
const SHIP_TRACKING_RE = /^[A-Za-z0-9]{8,32}$/
const shipTrackingError = computed(() => {
  const v = shipTrackingNo.value.trim()
  if (!v) return '请输入快递单号'
  if (!SHIP_TRACKING_RE.test(v)) return '单号需为 8~32 位字母或数字'
  return ''
})
const shipFormValid = computed(() => shipCompanyCode.value !== '' && shipTrackingError.value === '')
const shipCompanyName = computed(
  () => shipCompanies.value.find((c) => c.code === shipCompanyCode.value)?.name ?? shipCompanyCode.value,
)

// 状态 Tab / 筛选下拉同源：键顺序即履约主链路（待支付→已支付→待发货→已发货→已完成）
const statusTabs: Array<{ value: '' | OrderStatus; label: string }> = [
  { value: '', label: '全部' },
  ...(Object.keys(ORDER_STATUS_LABELS) as OrderStatus[]).map((s) => ({ value: s as OrderStatus, label: ORDER_STATUS_LABELS[s] })),
]

const queryParams = computed(() => ({
  order_no: orderNo.value.trim() || undefined,
  status: statusFilter.value || undefined,
  start_time: startDate.value ? `${startDate.value} 00:00:00` : undefined,
  end_time: endDate.value ? `${endDate.value} 23:59:59` : undefined,
  page_size: pagination.value.page_size,
}))

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await getOrders({ ...queryParams.value, page })
    list.value = data.data.list
    pagination.value = data.data.pagination
  } finally {
    loading.value = false
  }
}

function search() {
  load(1)
}

/** 顶部胶囊快捷筛选：点击立即按状态加载第一页 */
function filterStatus(status: '' | OrderStatus) {
  statusFilter.value = status
  load(1)
}

function specsText(order: AdminOrder): string {
  return order.items.map((i) => `${i.product_title} ×${i.quantity}`).join('；')
}

/** 打开发货弹窗：加载启用快递公司字典，重置表单 */
async function openShip(order: AdminOrder) {
  shipTarget.value = order
  shipCompanyCode.value = ''
  shipTrackingNo.value = ''
  shipTrackingTouched.value = false
  shipRemark.value = ''
  shipConfirmStep.value = false
  if (!shipCompanies.value.length) {
    try {
      const { data } = await getEnabledShippingCompanies()
      shipCompanies.value = data.data
    } catch {
      shipCompanies.value = []
    }
  }
}

/** 单号粘贴：去除所有空白字符（防止从快递单复制出空格/换行） */
function onTrackingPaste(e: ClipboardEvent) {
  e.preventDefault()
  const text = (e.clipboardData?.getData('text') ?? '').replace(/\s+/g, '')
  const el = e.target as HTMLInputElement
  shipTrackingNo.value = text
  el.value = text
}

async function doShip() {
  if (!shipTarget.value || !shipFormValid.value) return
  shipping.value = true
  try {
    await shipOrder(shipTarget.value.id, {
      express_company_code: shipCompanyCode.value,
      tracking_no: shipTrackingNo.value.trim(),
      remark: shipRemark.value.trim() || undefined,
    })
    shipTarget.value = null
    await load(pagination.value.page)
  } finally {
    shipping.value = false
  }
}

/** 复制运单号（2s 反馈） */
async function copyTracking(order: AdminOrder) {
  if (!order.tracking_no) return
  try {
    await navigator.clipboard.writeText(order.tracking_no)
    copiedOrderId.value = order.id
    setTimeout(() => {
      if (copiedOrderId.value === order.id) copiedOrderId.value = null
    }, 2000)
  } catch {
    // 忽略复制失败（无权限等）
  }
}

/** 受理备货：paid → pending_ship（正常由系统自动流转，这里只处理异常滞留订单） */
async function doAccept() {
  if (!acceptTarget.value) return
  accepting.value = true
  try {
    await acceptOrder(acceptTarget.value.id, acceptRemark.value.trim() || undefined)
    acceptTarget.value = null
    acceptRemark.value = ''
    await load(pagination.value.page)
  } finally {
    accepting.value = false
  }
}

/** 跳转到订单流水页并按该订单号筛选 */
function viewLogs(order: AdminOrder) {
  router.push({ path: '/order-logs', query: { order_no: order.order_no } })
}

async function doExport() {
  exporting.value = true
  try {
    await exportOrders(queryParams.value, `订单导出-${new Date().toISOString().slice(0, 10)}.csv`)
  } finally {
    exporting.value = false
  }
}

function goPage(page: number) {
  if (page < 1 || page > pagination.value.total_pages) return
  load(page)
}

onMounted(() => load())
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <!-- 标题 + 操作 -->
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-slate-800">订单管理</h2>
      <Button variant="outline" :disabled="exporting" @click="doExport">
        <Download class="mr-1 h-4 w-4" /> {{ exporting ? '导出中…' : '导出 CSV' }}
      </Button>
    </div>

    <!-- 筛选区 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <input
        v-model="orderNo" type="text" placeholder="订单号"
        class="w-48 rounded-md border border-slate-300 px-3 py-1.5 outline-none focus:border-[#1677ff]"
        @keyup.enter="search"
      />
      <select v-model="statusFilter" class="rounded-md border border-slate-300 px-2 py-1.5 outline-none focus:border-[#1677ff]">
        <option value="">状态</option>
        <option v-for="tab in statusTabs.slice(1)" :key="tab.value" :value="tab.value">{{ tab.label }}</option>
      </select>
      <div class="flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1.5">
        <input v-model="startDate" type="date" class="outline-none" />
        <span class="text-slate-300">–</span>
        <input v-model="endDate" type="date" class="outline-none" />
      </div>
      <Button class="bg-[#1677ff] px-5 hover:bg-[#4096ff]" @click="search"><Search class="mr-1 h-4 w-4" /> 搜索</Button>
    </div>

    <!-- 状态快捷筛选（胶囊标签排） -->
    <div class="mb-4 flex flex-wrap gap-2">
      <button
        v-for="tab in statusTabs"
        :key="tab.value"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="statusFilter === tab.value ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
        @click="filterStatus(tab.value)"
      >{{ tab.label }}</button>
    </div>

    <!-- 表格 -->
    <table class="w-full text-[13px]">
      <thead>
        <tr class="border-b border-slate-200 text-left text-slate-500">
          <th class="px-3 py-1.5">订单号</th>
          <th class="w-20 px-3 py-1.5">用户</th>
          <th class="px-3 py-1.5">商品</th>
          <th class="w-24 px-3 py-1.5">实付金额</th>
          <th class="w-20 px-3 py-1.5">状态</th>
          <th class="w-44 px-3 py-1.5">物流</th>
          <th class="w-40 px-3 py-1.5">下单时间</th>
          <th class="w-32 px-3 py-1.5">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="order in list" :key="order.id" class="border-b border-slate-100 hover:bg-slate-50">
          <td class="px-3 py-1.5 font-mono text-black">{{ order.order_no }}</td>
          <td class="px-3 py-1.5 text-black">#{{ order.user_id }}</td>
          <td class="max-w-64 truncate px-3 py-1.5 text-black" :title="specsText(order)">{{ specsText(order) }}</td>
          <td class="px-3 py-1.5 font-medium text-black">¥{{ order.pay_amount }}</td>
          <td class="px-3 py-1.5">
            <span class="rounded px-2 py-0.5 text-xs" :class="ORDER_STATUS_CLASS[order.status]">{{ ORDER_STATUS_LABELS[order.status] ?? order.status_label }}</span>
          </td>
          <td class="px-3 py-1.5" :data-testid="`logistics-${order.id}`">
            <template v-if="order.tracking_no">
              <p class="text-slate-700">{{ order.express_company || '—' }}</p>
              <button
                class="mt-0.5 flex items-center gap-1 font-mono text-xs text-slate-500 hover:text-[#1677ff]"
                :title="`复制单号 ${order.tracking_no}`"
                @click="copyTracking(order)"
              >
                <span class="max-w-24 truncate">{{ order.tracking_no }}</span>
                <Check v-if="copiedOrderId === order.id" class="h-3 w-3 text-green-500" />
                <Copy v-else class="h-3 w-3" />
              </button>
            </template>
            <span v-else class="text-slate-300">—</span>
          </td>
          <td class="px-3 py-1.5 text-black">{{ order.created_at }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button class="hover:underline" :data-testid="`detail-${order.id}`" @click="router.push(`/orders/${order.id}`)">详情</button>
              <span class="text-slate-200">|</span>
              <button
                v-permission="'order.log'"
                class="hover:underline"
                :data-testid="`logs-${order.id}`"
                @click="viewLogs(order)"
              >流水</button>
              <template v-if="order.status === 'pending_ship'">
                <span class="text-slate-200">|</span>
                <button class="flex items-center gap-0.5 text-orange-500 hover:underline" :data-testid="`ship-${order.id}`" @click="openShip(order)">
                  <Send class="h-3 w-3" /> 发货
                </button>
              </template>
              <template v-else-if="order.status === 'paid'">
                <span class="text-slate-200">|</span>
                <button
                  class="flex items-center gap-0.5 text-emerald-600 hover:underline"
                  :data-testid="`accept-${order.id}`"
                  @click="acceptTarget = order; acceptRemark = ''"
                >
                  <PackageCheck class="h-3 w-3" /> 受理备货
                </button>
              </template>
            </div>
          </td>
        </tr>
        <tr v-if="loading">
          <td colspan="8"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="8" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <TablePagination :pagination="pagination" @change="goPage" />

    <!-- 发货弹窗（T-047：快递公司字典下拉 + 单号即时校验 + 二次确认） -->
    <div v-if="shipTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="shipTarget = null">
      <div class="w-full max-w-md rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">确认发货</h3>
        <p class="mt-2 text-[13px] leading-5 text-slate-500">
          订单 <span class="font-mono text-slate-700">{{ shipTarget.order_no }}</span>（实付 ¥{{ shipTarget.pay_amount }}），
          发货后订单状态将变为「已发货」，用户端可见。
        </p>

        <template v-if="!shipConfirmStep">
          <!-- 第一步：填写物流信息 -->
          <label class="mt-4 block text-xs text-slate-500">快递公司</label>
          <select
            v-model="shipCompanyCode"
            data-testid="ship-company"
            class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
          >
            <option value="" disabled>请选择快递公司</option>
            <option v-for="c in shipCompanies" :key="c.code" :value="c.code">{{ c.name }}（{{ c.code }}）</option>
          </select>

          <label class="mt-3 block text-xs text-slate-500">快递单号</label>
          <input
            v-model="shipTrackingNo"
            data-testid="ship-tracking-no"
            type="text"
            placeholder="8~32 位字母或数字"
            class="mt-1 w-full rounded-lg border px-3 py-1.5 text-[13px] font-mono outline-none"
            :class="shipTrackingTouched && shipTrackingError ? 'border-red-400 focus:border-red-400' : 'border-slate-200 focus:border-[#1677ff]'"
            @blur="shipTrackingTouched = true"
            @paste="onTrackingPaste"
          />
          <p v-if="shipTrackingTouched && shipTrackingError" data-testid="ship-tracking-error" class="mt-1 text-xs text-red-500">
            {{ shipTrackingError }}
          </p>

          <label class="mt-3 block text-xs text-slate-500">备注（可选）</label>
          <input
            v-model="shipRemark"
            data-testid="ship-remark"
            type="text"
            placeholder="如：易碎品、延迟发货说明"
            class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
          />
        </template>

        <template v-else>
          <!-- 第二步：二次确认摘要 -->
          <div data-testid="ship-confirm-summary" class="mt-4 rounded-lg bg-slate-50 p-3 text-[13px] leading-6">
            <p><span class="text-slate-400">快递公司：</span>{{ shipCompanyName }}（{{ shipCompanyCode }}）</p>
            <p><span class="text-slate-400">快递单号：</span><span class="font-mono">{{ shipTrackingNo }}</span></p>
            <p v-if="shipRemark.trim()"><span class="text-slate-400">备注：</span>{{ shipRemark }}</p>
            <p class="mt-1 text-xs text-orange-500">请仔细核对单号，发货后用户端将立即收到通知。</p>
          </div>
        </template>

        <div class="mt-5 flex justify-end gap-2">
          <button
            v-if="shipConfirmStep"
            class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50"
            @click="shipConfirmStep = false"
          >返回修改</button>
          <button v-else class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="shipTarget = null">取消</button>
          <button
            v-if="!shipConfirmStep"
            data-testid="ship-next"
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="!shipFormValid"
            @click="shipConfirmStep = true"
          >下一步</button>
          <button
            v-else
            data-testid="ship-final"
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="shipping"
            @click="doShip"
          >{{ shipping ? '发货中…' : '确认发货' }}</button>
        </div>
      </div>
    </div>

    <!-- 受理备货确认（异常滞留订单的人工兜底） -->
    <div v-if="acceptTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="acceptTarget = null">
      <div class="w-full max-w-sm rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">受理备货</h3>
        <p class="mt-2 text-[13px] leading-5 text-slate-500">
          确认为订单 <span class="font-mono text-slate-700">{{ acceptTarget.order_no }}</span>（实付 ¥{{ acceptTarget.pay_amount }}）受理备货？
          状态将变为「待发货」，之后即可发货。
        </p>
        <p class="mt-1 text-xs text-slate-400">
          正常无需手动操作：支付成功后系统会自动把订单放进待发货队列。
        </p>
        <input
          v-model="acceptRemark"
          placeholder="备注（可选）"
          class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
        />
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="acceptTarget = null">取消</button>
          <button
            class="rounded-md bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff] disabled:opacity-50"
            :disabled="accepting"
            @click="doAccept"
          >{{ accepting ? '处理中…' : '确认受理' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
