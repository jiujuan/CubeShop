<script setup lang="ts">
import { CircleHelp, Info, Phone } from 'lucide-vue-next'

/** 页脚（按新版原型：三项服务入口 + 版权） */
const links = [
  { icon: Info, label: '关于我们' },
  { icon: CircleHelp, label: '帮助中心' },
  { icon: Phone, label: '联系客服' },
]
</script>

<template>
  <footer class="border-t border-slate-100 bg-white py-6 text-center text-xs text-slate-400">
    <div class="mb-2.5 flex items-center justify-center gap-8">
      <span v-for="l in links" :key="l.label" class="flex items-center gap-1.5 transition-colors hover:text-[#1677ff]">
        <component :is="l.icon" class="h-3.5 w-3.5" /> {{ l.label }}
      </span>
    </div>
    <div>© 2024 CubeShop 版权所有 京ICP备 12345678号-1</div>
  </footer>
</template>
