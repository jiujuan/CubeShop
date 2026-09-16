<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  acceptOrder,
  exportOrders,
  getOrders,
  ORDER_STATUS_CLASS,
  ORDER_STATUS_LABELS,
  shipOrder,
  type AdminOrder,
  type OrderStatus,
} from '@/api/order'
import { ChevronLeft, ChevronRight, Download, PackageCheck, Search, Send } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

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

// 详情 / 发货 / 受理备货
const detailOrder = ref<AdminOrder | null>(null)
const shipTarget = ref<AdminOrder | null>(null)
const shipRemark = ref('')
const shipping = ref(false)
const acceptTarget = ref<AdminOrder | null>(null)
const acceptRemark = ref('')
const accepting = ref(false)
const exporting = ref(false)

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

async function doShip() {
  if (!shipTarget.value) return
  shipping.value = true
  try {
    const { data } = await shipOrder(shipTarget.value.id, shipRemark.value.trim() || undefined)
    detailOrder.value = data.data
    shipTarget.value = null
    shipRemark.value = ''
    await load(pagination.value.page)
  } finally {
    shipping.value = false
  }
}

/** 受理备货：paid → pending_ship（正常由系统自动流转，这里只处理异常滞留订单） */
async function doAccept() {
  if (!acceptTarget.value) return
  accepting.value = true
  try {
    const { data } = await acceptOrder(acceptTarget.value.id, acceptRemark.value.trim() || undefined)
    detailOrder.value = data.data
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
          <td class="px-3 py-1.5 text-black">{{ order.created_at }}</td>
          <td class="px-3 py-1.5">
            <div class="flex items-center gap-1 text-[#1677ff]">
              <button class="hover:underline" @click="detailOrder = order">详情</button>
              <span class="text-slate-200">|</span>
              <button
                v-permission="'order.log'"
                class="hover:underline"
                :data-testid="`logs-${order.id}`"
                @click="viewLogs(order)"
              >流水</button>
              <template v-if="order.status === 'pending_ship'">
                <span class="text-slate-200">|</span>
                <button class="flex items-center gap-0.5 text-orange-500 hover:underline" @click="shipTarget = order; shipRemark = ''">
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
          <td colspan="7"><LoadingSpinner /></td>
        </tr>
        <tr v-if="!list.length && !loading">
          <td colspan="7" class="px-3 py-12 text-center text-slate-400">暂时无数据</td>
        </tr>
      </tbody>
    </table>

    <!-- 分页 -->
    <div class="mt-4 flex items-center justify-between text-[13px] text-slate-500">
      <span>共 {{ pagination.total }} 条记录 / 每页 {{ pagination.page_size }} 条</span>
      <div class="flex items-center gap-1">
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)"
        ><ChevronLeft class="h-4 w-4" /></button>
        <button
          v-for="page in pagination.total_pages"
          :key="page"
          class="h-7 min-w-7 rounded border px-1.5"
          :class="page === pagination.page ? 'border-[#1677ff] bg-[#1677ff] text-white' : 'border-slate-200 hover:border-[#1677ff]'"
          @click="goPage(page)"
        >{{ page }}</button>
        <button
          class="flex h-7 w-7 items-center justify-center rounded border border-slate-200 disabled:opacity-40"
          :disabled="pagination.page >= pagination.total_pages" @click="goPage(pagination.page + 1)"
        ><ChevronRight class="h-4 w-4" /></button>
      </div>
    </div>

    <!-- 详情弹窗 -->
    <div v-if="detailOrder" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="detailOrder = null">
      <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-base font-semibold">订单详情</h2>
          <button class="text-slate-400 hover:text-slate-600" @click="detailOrder = null">✕</button>
        </div>
        <p class="mb-1 text-sm"><span class="text-slate-400">订单号：</span><span class="font-mono">{{ detailOrder.order_no }}</span></p>
        <p class="mb-4 text-sm">
          <span class="text-slate-400">状态：</span>
          <span class="rounded px-1.5 py-0.5 text-xs" :class="ORDER_STATUS_CLASS[detailOrder.status]">{{ ORDER_STATUS_LABELS[detailOrder.status] ?? detailOrder.status_label }}</span>
        </p>

        <div class="mb-4 rounded-lg bg-slate-50 p-3 text-[13px]" v-if="detailOrder.address_snapshot">
          <p class="font-medium text-slate-700">{{ detailOrder.address_snapshot.contact_name }}　{{ detailOrder.address_snapshot.contact_phone }}</p>
          <p class="mt-1 text-slate-500">{{ detailOrder.address_snapshot.full_address }}</p>
        </div>

        <table class="mb-4 w-full text-[13px]">
          <thead>
            <tr class="bg-slate-50 text-left text-slate-400">
              <th class="px-3 py-1.5 font-normal">商品</th>
              <th class="px-3 py-1.5 font-normal">单价</th>
              <th class="px-3 py-1.5 font-normal">数量</th>
              <th class="px-3 py-1.5 font-normal">小计</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, idx) in detailOrder.items" :key="idx" class="border-t border-slate-50">
              <td class="px-3 py-1.5 text-slate-700">{{ item.product_title }}</td>
              <td class="px-3 py-1.5 text-slate-500">¥{{ item.price }}</td>
              <td class="px-3 py-1.5 text-slate-500">{{ item.quantity }}</td>
              <td class="px-3 py-1.5 text-slate-700">¥{{ item.total_amount }}</td>
            </tr>
          </tbody>
        </table>

        <div class="space-y-1 text-right text-[13px] text-slate-600">
          <p>商品合计：¥{{ detailOrder.total_amount }}　运费：¥{{ detailOrder.freight_amount }}</p>
          <p class="text-base font-semibold text-[#ff4d4f]">实付款：¥{{ detailOrder.pay_amount }}</p>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-slate-400">
          <p>下单时间：{{ detailOrder.created_at }}</p>
          <p>支付时间：{{ detailOrder.paid_at || '—' }}</p>
          <p>发货时间：{{ detailOrder.shipped_at || '—' }}</p>
          <p v-if="detailOrder.cancel_reason">取消原因：{{ detailOrder.cancel_reason }}</p>
          <p v-if="detailOrder.remark" class="col-span-2">备注：{{ detailOrder.remark }}</p>
        </div>
      </div>
    </div>

    <!-- 发货确认 -->
    <div v-if="shipTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" @click.self="shipTarget = null">
      <div class="w-full max-w-sm rounded-xl bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-800">确认发货</h3>
        <p class="mt-2 text-[13px] leading-5 text-slate-500">
          确认为订单 <span class="font-mono text-slate-700">{{ shipTarget.order_no }}</span>（实付 ¥{{ shipTarget.pay_amount }}）发货？
          发货后订单状态将变为「已发货」，用户端可见。
        </p>
        <input
          v-model="shipRemark"
          placeholder="发货备注（可选，如快递单号）"
          class="mt-3 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
        />
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 hover:bg-slate-50" @click="shipTarget = null">取消</button>
          <button
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
