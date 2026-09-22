<script setup lang="ts">
import { ref } from 'vue'
import { ImageOff } from 'lucide-vue-next'

/**
 * Web 端统一图片组件（图片资产治理 P3 范畴）
 *
 * 取代散用的 `<img>`，统一三件事：
 * - **懒加载**：`loading="lazy"`，首屏外的图片不抢带宽；
 * - **加载占位**：浅灰底（`bg-slate-100`），图片字节到达前不露白；
 * - **失败兜底**：404 / 域名切换 / 文件缺失时显示中性占位图标，不再出现浏览器「裂图」。
 *
 * `class` 透传到根元素（与裸 `<img>` 一致），可直接用于 `absolute inset-0` 等定位场景；
 * `@click` 等监听器也会落到根元素上。加载失败时根元素退化为一个同样尺寸的占位 `<div>`，
 * 因此既有的布局（卡片、相册缩略图）不会因图片缺失而塌陷。
 *
 * 用法：`<AppImage :src="url" alt="..." class="h-full w-full object-cover" />`
 */
const props = withDefaults(
  defineProps<{
    src?: string | null
    alt?: string
  }>(),
  { src: '', alt: '' },
)

const failed = ref(false)
</script>

<template>
  <img
    v-if="src && !failed"
    :src="src"
    :alt="alt"
    loading="lazy"
    class="bg-slate-100"
    data-testid="app-image"
    @error="failed = true"
  />
  <div
    v-else
    class="flex items-center justify-center bg-slate-100 text-slate-300"
    data-testid="app-image-fallback"
  >
    <ImageOff class="h-8 w-8" />
  </div>
</template>
