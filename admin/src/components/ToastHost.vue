<script setup lang="ts">
import { useToast } from '@/composables/useToast'

const { toasts } = useToast()
</script>

<template>
  <div
    class="pointer-events-none fixed left-1/2 top-4 z-[100] flex -translate-x-1/2 flex-col items-center gap-2"
    data-testid="toast-host"
  >
    <transition-group name="toast">
      <div
        v-for="t in toasts"
        :key="t.id"
        class="pointer-events-auto rounded-md px-4 py-2 text-[13px] text-white shadow-lg"
        :class="{
          'bg-red-500': t.type === 'error',
          'bg-green-500': t.type === 'success',
          'bg-slate-700': t.type === 'info',
        }"
        :data-testid="`toast-${t.type}`"
      >{{ t.message }}</div>
    </transition-group>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.2s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
