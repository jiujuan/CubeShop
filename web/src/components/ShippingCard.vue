<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Copy, Check, ExternalLink, Package, Truck } from 'lucide-vue-next'
import { getOrderShipping, type ShippingInfo } from '@/api/order'

/**
 * 物流卡片（V1.1 T-046，F07）
 *
 * - 有轨迹：公司名 + 单号（可复制）+ 轨迹时间线（最新在顶、高亮「最新」标记）；
 * - 无轨迹：降级展示「暂无轨迹」+ 官方查询外链 + 单号复制；
 * - 接口异常：降级卡兜底，不抛错不白屏；
 * - 未发货（接口返回 null）：不渲染；
 * - 移动端（<sm）：时间线时间与描述上下堆叠。
 */

const props = defineProps<{ orderId: string | number }>()

const loading = ref(true)
const failed = ref(false)
const shipping = ref<ShippingInfo | null>(null)
const copied = ref(false)

const TRACE_STATUS_LABELS: Record<string, string> = {
  pending: '待揽收',
  in_transit: '运输中',
  delivered: '已签收',
  failed: '轨迹查询异常',
}

/** 常见公司 → 快递100 com 编码（未命中则仅按单号查询，自动识别） */
const COMPANY_QUERY_CODE: Record<string, string> = {
  顺丰速运: 'shunfeng',
  中通快递: 'zhongtong',
  圆通速递: 'yuantong',
  韵达快递: 'yunda',
  申通快递: 'shengtong',
  京东物流: 'jd',
  邮政EMS: 'ems',
  极兔速递: 'jtexpress',
}

const queryUrl = computed(() => {
  if (! shipping.value) return ''
  const code = COMPANY_QUERY_CODE[shipping.value.express_company]
  const base = 'https://www.kuaidi100.com/chaxun'
  return code
    ? `${base}?com=${code}&nu=${encodeURIComponent(shipping.value.tracking_no)}`
    : `${base}?nu=${encodeURIComponent(shipping.value.tracking_no)}`
})

const statusLabel = computed(() =>
  shipping.value ? TRACE_STATUS_LABELS[shipping.value.trace_status] ?? shipping.value.trace_status : '',
)

const isDelivered = computed(() => shipping.value?.trace_status === 'delivered')

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const res = await getOrderShipping(props.orderId)
    // data=null 表示未发货，父层不渲染该卡片；这里仍存 null
    shipping.value = res.data?.data ?? null
  } catch {
    // 接口异常走降级卡（不抛错、不白屏）
    failed.value = true
  } finally {
    loading.value = false
  }
}

/** 复制单号（失败时提示手动选择，不抛错） */
async function copyTrackingNo(): Promise<void> {
  if (! shipping.value) return
  try {
    await navigator.clipboard.writeText(shipping.value.tracking_no)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch {
    /* 剪贴板不可用（如非安全上下文）：静默降级，用户可手动选择复制 */
  }
}

onMounted(load)

defineExpose({ load })
</script>

<template>
  <!-- 加载骨架 -->
  <section v-if="loading" class="mb-6 animate-pulse rounded-xl border border-slate-100 bg-white p-5">
    <div class="mb-4 h-4 w-28 rounded bg-slate-100"></div>
    <div class="space-y-3">
      <div class="h-3 w-2/3 rounded bg-slate-100"></div>
      <div class="h-3 w-1/2 rounded bg-slate-100"></div>
      <div class="h-3 w-3/5 rounded bg-slate-100"></div>
    </div>
  </section>

  <!-- 接口异常降级卡：不报错不白屏 -->
  <section v-else-if="failed" class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700">
      <Truck class="h-4 w-4 text-[#1677ff]" /> 物流信息
    </h2>
    <p class="text-sm text-slate-400">物流信息加载失败，请稍后刷新重试</p>
  </section>

  <!-- 正常展示 -->
  <section v-else-if="shipping" class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700">
      <Truck class="h-4 w-4 text-[#1677ff]" /> 物流信息
      <span
        class="ml-auto rounded-full px-2 py-0.5 text-xs font-medium"
        :class="isDelivered ? 'bg-green-50 text-green-600' : 'bg-blue-50 text-[#1677ff]'"
      >
        {{ statusLabel }}
      </span>
    </h2>

    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
      <p class="text-sm text-slate-700">{{ shipping.express_company }}</p>
      <p class="font-mono text-sm text-slate-500 sm:flex-1">{{ shipping.tracking_no }}</p>
      <div class="flex items-center gap-3">
        <button
          type="button"
          class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2 py-1 text-xs text-slate-600 transition hover:border-[#1677ff] hover:text-[#1677ff]"
          data-testid="copy-tracking"
          @click="copyTrackingNo"
        >
          <Check v-if="copied" class="h-3.5 w-3.5 text-green-500" />
          <Copy v-else class="h-3.5 w-3.5" />
          {{ copied ? '已复制' : '复制' }}
        </button>
        <a
          :href="queryUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-flex items-center gap-1 text-xs text-[#1677ff] hover:underline"
          data-testid="query-external"
        >
          官方查询 <ExternalLink class="h-3 w-3" />
        </a>
      </div>
    </div>

    <!-- 复制成功轻提示（无轨迹降级态下同样可用） -->
    <p
      v-if="copied && !shipping.has_trace"
      class="mb-3 text-xs text-green-600"
      data-testid="copy-tip"
    >
      单号已复制到剪贴板
    </p>

    <!-- 有轨迹：时间线（最新在顶、高亮） -->
    <ol v-if="shipping.has_trace" class="relative space-y-0 border-l border-slate-100 pl-4" data-testid="trace-list">
      <li
        v-for="(trace, idx) in shipping.traces"
        :key="`${trace.occurred_at}-${idx}`"
        class="relative py-2"
      >
        <span
          class="absolute -left-[21px] top-3.5 h-2.5 w-2.5 rounded-full"
          :class="idx === 0 ? 'bg-[#1677ff] ring-4 ring-blue-50' : 'bg-slate-200'"
        ></span>
        <div class="flex flex-col sm:flex-row sm:items-baseline sm:gap-3">
          <time class="shrink-0 text-xs text-slate-400 sm:w-36">{{ trace.occurred_at }}</time>
          <p
            class="min-w-0 break-words text-sm sm:flex-1"
            :class="idx === 0 ? 'font-semibold text-slate-800' : 'text-slate-500'"
          >
            {{ trace.context }}
            <span
              v-if="idx === 0"
              class="ml-1 inline-block rounded bg-[#1677ff] px-1.5 py-0.5 align-middle text-[10px] font-normal text-white"
              data-testid="latest-badge"
            >
              最新
            </span>
          </p>
        </div>
      </li>
    </ol>

    <!-- 无轨迹降级：查询中/暂无轨迹 + 外链 + 复制 -->
    <div
      v-else
      class="flex items-start gap-3 rounded-lg bg-slate-50 p-4"
      data-testid="trace-empty"
    >
      <Package class="mt-0.5 h-5 w-5 shrink-0 text-slate-300" />
      <div class="min-w-0">
        <p class="text-sm text-slate-600">
          {{ isDelivered ? '暂无轨迹记录' : '快递信息查询中，暂无轨迹' }}
        </p>
        <p class="mt-1 text-xs text-slate-400">
          可复制单号到
          <a :href="queryUrl" target="_blank" rel="noopener noreferrer" class="text-[#1677ff] hover:underline">
            {{ shipping.express_company }}官方渠道
          </a>
          查询最新物流状态
        </p>
      </div>
    </div>
  </section>
</template>
