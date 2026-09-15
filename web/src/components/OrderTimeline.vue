<script setup lang="ts">
import { computed } from 'vue'
import { AlertTriangle, Check } from 'lucide-vue-next'
import type { OrderLogEntry, OrderStatus } from '@/api/order'

/**
 * 订单状态时间轴（V1.1 E02-B / T-005）
 *
 * 数据来源：后端订单详情返回的 logs（order_logs 流水，按时间升序）。
 * 节点：提交订单 → 支付成功 → 商家发货 → 交易完成
 * 未到达节点置灰；取消 / 退款等异常分支单独以告警态呈现。
 *
 * 预留 `shipping` 具名插槽，供二期 T-046 挂载物流卡片，避免二次改版。
 */
const props = defineProps<{
  logs: OrderLogEntry[]
  status: OrderStatus
}>()

interface TimelineNode {
  status: OrderStatus
  label: string
  hint: string
}

const NODES: TimelineNode[] = [
  { status: 'pending_payment', label: '提交订单', hint: '订单已创建，等待付款' },
  { status: 'paid', label: '支付成功', hint: '已收到货款，等待发货' },
  { status: 'shipped', label: '商家发货', hint: '商品已寄出，请注意查收' },
  { status: 'completed', label: '交易完成', hint: '订单已完成' },
]

/** 异常状态分支 */
const ABNORMAL: Partial<Record<OrderStatus, { label: string; hint: string }>> = {
  cancelled: { label: '订单已取消', hint: '订单已关闭，库存已释放' },
  refunding: { label: '退款处理中', hint: '退款申请已提交，等待商家审核' },
  refunded: { label: '退款完成', hint: '货款已退回原支付渠道' },
}

const nodeMap = computed(() => {
  const map: Partial<Record<OrderStatus, OrderLogEntry>> = {}
  for (const log of props.logs) {
    // 同状态取最早一条（首次到达该节点的时间）
    if (!map[log.to_status]) map[log.to_status] = log
  }
  return map
})

/** 主链路进度：未到达的节点置灰 */
const nodes = computed(() =>
  NODES.map((node) => {
    const log = nodeMap.value[node.status]
    return {
      ...node,
      time: log?.created_at ?? null,
      remark: log?.remark ?? null,
      reached: Boolean(log),
      current: props.status === node.status,
    }
  }),
)

const abnormal = computed(() => {
  const cfg = ABNORMAL[props.status]
  if (!cfg) return null
  const log = nodeMap.value[props.status]
  return { ...cfg, time: log?.created_at ?? null, remark: log?.remark ?? null }
})
</script>

<template>
  <section class="mb-6 rounded-xl border border-slate-100 bg-white p-5">
    <h2 class="mb-4 text-sm font-semibold text-slate-700">订单进度</h2>

    <!-- 异常分支（取消 / 退款） -->
    <div
      v-if="abnormal"
      class="mb-4 flex items-start gap-3 rounded-lg border border-orange-100 bg-orange-50 px-4 py-3"
      data-testid="timeline-abnormal"
    >
      <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-orange-500" />
      <div>
        <p class="text-sm font-medium text-orange-600">{{ abnormal.label }}</p>
        <p class="mt-0.5 text-xs text-orange-500">{{ abnormal.remark || abnormal.hint }}</p>
        <p v-if="abnormal.time" class="mt-0.5 text-xs text-orange-400">{{ abnormal.time }}</p>
      </div>
    </div>

    <!-- 主链路节点 -->
    <ol class="space-y-0">
      <li
        v-for="(node, idx) in nodes" :key="node.status"
        class="flex gap-3"
        :class="{ 'text-slate-300': !node.reached && !node.current }"
        :data-testid="`timeline-node-${node.status}`"
        :data-reached="node.reached ? '1' : '0'"
      >
        <!-- 轴点与连线 -->
        <div class="flex flex-col items-center">
          <span
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs"
            :class="node.reached
              ? 'border-[#1677ff] bg-[#1677ff] text-white'
              : 'border-slate-200 bg-white text-slate-300'"
          >
            <Check v-if="node.reached" class="h-3.5 w-3.5" />
            <span v-else>{{ idx + 1 }}</span>
          </span>
          <span v-if="idx < nodes.length - 1" class="w-px flex-1 bg-slate-100" :class="{ 'min-h-8': true }" />
        </div>

        <div class="min-w-0 flex-1 pb-5">
          <p
            class="text-sm font-medium"
            :class="node.reached ? (node.current ? 'text-[#1677ff]' : 'text-slate-700') : 'text-slate-300'"
          >
            {{ node.label }}
          </p>
          <p v-if="node.time" class="mt-0.5 text-xs text-slate-400">{{ node.time }}</p>
          <p v-else class="mt-0.5 text-xs text-slate-300">{{ node.hint }}</p>
          <p v-if="node.remark && node.remark !== node.label" class="mt-0.5 text-xs text-slate-400">
            {{ node.remark }}
          </p>
        </div>
      </li>
    </ol>

    <!-- 二期 T-046 物流卡片插槽 -->
    <slot name="shipping" />
  </section>
</template>
