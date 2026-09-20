<script setup lang="ts">
import { CircleHelp, Info, Phone } from 'lucide-vue-next'
import { useSiteStore } from '@/stores/site'

/**
 * 页脚（按新版原型：三项服务入口 + 版权）
 *
 * 三个入口走真实路由（CMS-112 前是三个死链 span）：
 * - 关于我们 / 联系客服 → CMS 单页 `/p/:slug`（公开，决策 D4）
 * - 帮助中心 → `/service-center/faq`（公开，决策 D4）
 */
const site = useSiteStore()
const links = [
  { icon: Info, label: '关于我们', to: '/p/about', testid: 'footer-about' },
  { icon: CircleHelp, label: '帮助中心', to: '/service-center/faq', testid: 'footer-help' },
  { icon: Phone, label: '联系客服', to: '/p/contact', testid: 'footer-contact' },
]
</script>

<template>
  <footer class="border-t border-slate-100 bg-white py-6 text-center text-xs text-slate-400">
    <div class="mb-2.5 flex items-center justify-center gap-8">
      <RouterLink
        v-for="l in links"
        :key="l.label"
        :to="l.to"
        class="flex items-center gap-1.5 transition-colors hover:text-[#1677ff]"
        :data-testid="l.testid"
      >
        <component :is="l.icon" class="h-3.5 w-3.5" /> {{ l.label }}
      </RouterLink>
    </div>
    <div>© 2024 {{ site.name }} 版权所有 京ICP备 12345678号-1</div>
  </footer>
</template>
