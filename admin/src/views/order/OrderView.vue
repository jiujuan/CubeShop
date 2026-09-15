<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  exportOrders,
  getOrders,
  ORDER_STATUS_CLASS,
  ORDER_STATUS_LABELS,
  shipOrder,
  type AdminOrder,
  type OrderStatus,
} from '@/api/order'
import { Download, Send } from 'lucide-vue-next'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

/**
 * 订单管理（Roadmap P6）：多条件筛选、详情、发货、导出
 */
const loading = ref(true)
const list = ref<AdminOrder[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })

// 筛选条件
const orderNo = ref('')
const statusFilter = ref<'' | OrderStatus>('')
const startDate = ref('')
const endDate = ref('')

// 详情 / 发货
const detailOrder = ref<AdminOrder | null>(null)
const shipTarget = ref<AdminOrder | null>(null)
const shipRemark = ref('')
const shipping = ref(false)
const exporting = ref(false)

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

async function doExport() {
  exporting.value = true
  try {
    await exportOrders(queryParams.value, `订单导出-${new Date().toISOString().slice(0, 10)}.csv`)
  } finally {
    exporting.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-lg font-semibold">订单管理</h1>
      <button
        class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] text-slate-600 transition-colors hover:border-[#1677ff] hover:text-[#1677ff] disabled:opacity-50"
        :disabled="exporting"
        @click="doExport"
      >
        <Download class="h-3.5 w-3.5" /> {{ exporting ? '导出中…' : '导出 CSV' }}
      </button>
    </div>

    <!-- 筛选 -->
    <div class="rounded-lg border border-slate-200 bg-white p-4">
      <div class="flex flex-wrap items-center gap-3">
        <input
          v-model="orderNo"
          placeholder="订单号"
          class="w-52 rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]"
          @keyup.enter="search"
        />
        <input v-model="startDate" type="date" class="rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
        <span class="text-slate-300">~</span>
        <input v-model="endDate" type="date" class="rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] outline-none focus:border-[#1677ff]" />
        <button class="rounded-lg bg-[#1677ff] px-4 py-1.5 text-[13px] text-white hover:bg-[#4096ff]" @click="search">查询</button>
      </div>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-for="tab in statusTabs"
          :key="tab.value"
          class="rounded-full px-3 py-1 text-xs transition-colors"
          :class="statusFilter === tab.value ? 'bg-[#1677ff] text-white' : 'border border-slate-200 text-slate-500 hover:text-[#1677ff]'"
          @click="filterStatus(tab.value)"
        >{{ tab.label }}</button>
      </div>
    </div>

    <!-- 列表 -->
    <div class="rounded-lg border border-slate-200 bg-white">
      <div v-if="loading" class="p-10"><LoadingSpinner /></div>
      <div v-else-if="!list.length" class="p-10 text-center text-[13px] text-slate-400">暂无订单</div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-[13px]">
          <thead>
            <tr class="bg-slate-50 text-left text-slate-400">
              <th class="px-4 py-2 font-normal">订单号</th>
              <th class="px-4 py-2 font-normal">用户</th>
              <th class="px-4 py-2 font-normal">商品</th>
              <th class="px-4 py-2 font-normal">实付金额</th>
              <th class="px-4 py-2 font-normal">状态</th>
              <th class="px-4 py-2 font-normal">下单时间</th>
              <th class="px-4 py-2 font-normal">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="order in list" :key="order.id" class="border-t border-slate-50 hover:bg-slate-50/50">
              <td class="px-4 py-2 font-mono text-slate-600">{{ order.order_no }}</td>
              <td class="px-4 py-2 text-slate-500">#{{ order.user_id }}</td>
              <td class="max-w-64 truncate px-4 py-2 text-slate-700" :title="specsText(order)">{{ specsText(order) }}</td>
              <td class="px-4 py-2 font-medium text-slate-700">¥{{ order.pay_amount }}</td>
              <td class="px-4 py-2">
                <span class="rounded px-1.5 py-0.5 text-xs" :class="ORDER_STATUS_CLASS[order.status]">{{ order.status_label }}</span>
              </td>
              <td class="px-4 py-2 text-slate-500">{{ order.created_at }}</td>
              <td class="px-4 py-2">
                <div class="flex items-center gap-2">
                  <button class="text-[#1677ff] hover:underline" @click="detailOrder = order">详情</button>
                  <button
                    v-if="order.status === 'paid'"
                    class="flex items-center gap-1 text-orange-500 hover:underline"
                    @click="shipTarget = order; shipRemark = ''"
                  >
                    <Send class="h-3 w-3" /> 发货
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 分页 -->
      <div v-if="!loading && pagination.total > 0" class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-[13px] text-slate-500">
        <span>共 {{ pagination.total }} 条</span>
        <div class="flex items-center gap-2">
          <button
            class="rounded border border-slate-200 px-2.5 py-1 disabled:opacity-40"
            :disabled="pagination.page <= 1"
            @click="load(pagination.page - 1)"
          >上一页</button>
          <span>{{ pagination.page }} / {{ pagination.total_pages }}</span>
          <button
            class="rounded border border-slate-200 px-2.5 py-1 disabled:opacity-40"
            :disabled="pagination.page >= pagination.total_pages"
            @click="load(pagination.page + 1)"
          >下一页</button>
        </div>
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
          <span class="rounded px-1.5 py-0.5 text-xs" :class="ORDER_STATUS_CLASS[detailOrder.status]">{{ detailOrder.status_label }}</span>
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
  </div>
</template>
