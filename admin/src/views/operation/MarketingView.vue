<script setup lang="ts">
import { ref } from 'vue'
import CouponPanel from '@/components/marketing/CouponPanel.vue'
import PromotionPanel from '@/components/marketing/PromotionPanel.vue'

/**
 * 营销管理（V1.1 二期 F06 / T-040，权限 marketing.manage）
 *
 * 优惠券 / 满减活动 两个 Tab。
 */
type Tab = 'coupons' | 'promotions'
const tab = ref<Tab>('coupons')

const tabs: Array<{ key: Tab; label: string }> = [
  { key: 'coupons', label: '优惠券' },
  { key: 'promotions', label: '满减活动' },
]
</script>

<template>
  <div class="rounded-lg bg-white p-5 shadow-sm">
    <!-- 标题 + Tab（Tab 位置与样式沿用原实现，仅整体纳入页面卡片） -->
    <div class="mb-4 flex items-center gap-3">
      <h2 class="text-lg font-semibold text-slate-800">营销管理</h2>
      <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm" data-testid="marketing-tabs">
        <button
          v-for="t in tabs" :key="t.key"
          class="rounded-md px-4 py-1.5 transition-colors"
          :class="tab === t.key ? 'bg-white font-medium text-[#1677ff] shadow-sm' : 'text-slate-500 hover:text-slate-700'"
          :data-testid="`marketing-tab-${t.key}`"
          @click="tab = t.key"
        >{{ t.label }}</button>
      </div>
    </div>

    <CouponPanel v-show="tab === 'coupons'" />
    <PromotionPanel v-show="tab === 'promotions'" />
  </div>
</template>
