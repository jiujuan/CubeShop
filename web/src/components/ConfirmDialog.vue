<script setup lang="ts">
/**
 * 二次确认弹窗（V1.1 T-005）
 *
 * 用于不可撤销操作（如确认收货）的二次确认，避免误触。
 */
withDefaults(
  defineProps<{
    modelValue: boolean
    title?: string
    content?: string
    confirmText?: string
    cancelText?: string
    loading?: boolean
  }>(),
  {
    title: '请确认',
    content: '',
    confirmText: '确定',
    cancelText: '取消',
    loading: false,
  },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm'): void
  (e: 'cancel'): void
}>()

function close() {
  emit('update:modelValue', false)
  emit('cancel')
}
</script>

<template>
  <div v-if="modelValue" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-6" data-testid="confirm-dialog">
    <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
      <h3 class="text-base font-semibold text-slate-800">{{ title }}</h3>
      <p class="mt-2 whitespace-pre-line text-sm text-slate-500">{{ content }}</p>
      <div class="mt-6 flex justify-end gap-3">
        <button
          class="rounded-full border border-slate-200 px-5 py-1.5 text-sm text-slate-500 hover:bg-slate-50"
          :disabled="loading"
          data-testid="confirm-dialog-cancel"
          @click="close"
        >{{ cancelText }}</button>
        <button
          class="rounded-full bg-[#1677ff] px-5 py-1.5 text-sm text-white hover:bg-[#4096ff] disabled:opacity-60"
          :disabled="loading"
          data-testid="confirm-dialog-ok"
          @click="emit('confirm')"
        >{{ loading ? '处理中…' : confirmText }}</button>
      </div>
    </div>
  </div>
</template>
