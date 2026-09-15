<script setup lang="ts">
/**
 * 数据加载指示器：旋转圆环 + 提示文案（全后台统一使用）
 */
withDefaults(defineProps<{ text?: string }>(), { text: '正在获取数据…' })
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-2.5 py-10 text-slate-400">
    <span class="h-6 w-6 animate-spin rounded-full border-2 border-slate-200 border-t-[#1677ff]"></span>
    <span class="text-[13px]">{{ text }}</span>
  </div>
</template>
