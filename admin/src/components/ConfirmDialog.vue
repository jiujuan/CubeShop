<script setup lang="ts">
/**
 * 通用确认弹层：下架/删除等危险操作前二次确认
 * 用法：<ConfirmDialog :open="!!confirmState" :title="..." :message="..." danger @confirm="..." @cancel="confirmState = null" />
 */
withDefaults(
  defineProps<{
    open: boolean
    title?: string
    message?: string
    confirmText?: string
    cancelText?: string
    danger?: boolean
  }>(),
  { title: '操作确认', message: '', confirmText: '确认', cancelText: '取消', danger: false },
)

const emit = defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>()
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center">
      <!-- 遮罩 -->
      <div class="absolute inset-0 bg-black/40" @click="emit('cancel')"></div>

      <!-- 弹层 -->
      <div class="relative w-90 max-w-[90vw] rounded-lg bg-white p-5 shadow-xl">
        <div class="flex items-start gap-3">
          <span
            class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold"
            :class="danger ? 'bg-red-50 text-red-500' : 'bg-[#e6f4ff] text-[#1677ff]'"
          >!</span>
          <div class="min-w-0">
            <h4 class="text-sm font-semibold text-slate-800">{{ title }}</h4>
            <p class="mt-1.5 text-[13px] leading-5 text-slate-500">{{ message }}</p>
          </div>
        </div>

        <div class="mt-5 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-4 py-1.5 text-[13px] text-slate-600 transition-colors hover:bg-slate-50"
            @click="emit('cancel')"
          >{{ cancelText }}</button>
          <button
            type="button"
            data-testid="confirm-ok"
            class="rounded-md px-4 py-1.5 text-[13px] text-white transition-colors"
            :class="danger ? 'bg-[#ff4d4f] hover:bg-[#ff7875]' : 'bg-[#1677ff] hover:bg-[#4096ff]'"
            @click="emit('confirm')"
          >{{ confirmText }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
