<script setup lang="ts">
import { computed, ref } from 'vue'
import { ImagePlus, Star, X } from 'lucide-vue-next'
import { submitReview, updateReview, type ReviewItem, type ReviewPayload } from '@/api/review'
import { uploadImage } from '@/api/user'

/**
 * 评价表单（V1.1 F01 / T-016）
 *
 * - 新建：传 orderId + itemId；编辑：传 initial（调用 modify 接口）
 * - 星级 1~5 + 文案提示；内容上限 500 字；图片 ≤9 张（上传后回填 URL）；匿名开关
 */
const props = defineProps<{
  orderId?: string | number
  itemId?: string | number
  /** 编辑模式：传入已存在的评价 */
  initial?: ReviewItem | null
  productTitle?: string
}>()

const emit = defineEmits<{
  (e: 'submitted'): void
  (e: 'cancel'): void
}>()

const MAX_CONTENT = 500
const MAX_IMAGES = 9

const rating = ref(props.initial?.rating ?? 0)
const hoverRating = ref(0)
const content = ref(props.initial?.content ?? '')
const images = ref<string[]>(props.initial?.images ?? [])
const anonymous = ref(props.initial?.is_anonymous ?? false)

const submitting = ref(false)
const uploading = ref(false)
const tip = ref('')
const tipType = ref<'ok' | 'err'>('ok')

const isEdit = computed(() => !!props.initial)

const RATING_TEXT = ['', '很差', '较差', '一般', '满意', '非常满意']
const ratingText = computed(() => RATING_TEXT[hoverRating.value || rating.value] ?? '')

const contentLength = computed(() => content.value.length)

function pickRating(v: number) {
  rating.value = v
}

async function onPickFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (images.value.length >= MAX_IMAGES) {
    tipType.value = 'err'
    tip.value = `最多上传 ${MAX_IMAGES} 张图片`
    return
  }
  uploading.value = true
  tip.value = ''
  try {
    const { data } = await uploadImage(file)
    images.value.push(data.data.url)
  } catch (err) {
    tipType.value = 'err'
    tip.value = err instanceof Error ? err.message : '图片上传失败'
  } finally {
    uploading.value = false
  }
}

function removeImage(i: number) {
  images.value.splice(i, 1)
}

function validate(): string | null {
  if (!rating.value || rating.value < 1 || rating.value > 5) return '请先选择评分'
  if (contentLength.value > MAX_CONTENT) return `内容不能超过 ${MAX_CONTENT} 字`
  if (images.value.length > MAX_IMAGES) return `最多上传 ${MAX_IMAGES} 张图片`
  return null
}

async function onSubmit() {
  const err = validate()
  if (err) {
    tipType.value = 'err'
    tip.value = err
    return
  }

  const payload: ReviewPayload = {
    rating: rating.value,
    content: content.value || undefined,
    images: images.value.length ? images.value : undefined,
    is_anonymous: anonymous.value,
  }

  submitting.value = true
  tip.value = ''
  try {
    if (isEdit.value && props.initial) {
      await updateReview(props.initial.id, payload)
      tipType.value = 'ok'
      tip.value = '评价已更新'
    } else if (props.orderId && props.itemId) {
      const { data } = await submitReview(props.orderId, props.itemId, payload)
      tipType.value = 'ok'
      tip.value = data.data.status === 'pending' ? '评价已提交，审核通过后展示' : '评价成功'
    }
    emit('submitted')
  } catch (e) {
    tipType.value = 'err'
    tip.value = e instanceof Error ? e.message : '提交失败'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="rounded-xl border border-slate-200 p-5" data-testid="review-form">
    <h4 class="mb-3 text-base font-bold text-slate-800">
      {{ isEdit ? '修改评价' : '发表评价' }}
      <span v-if="productTitle" class="ml-2 text-xs font-normal text-slate-400">{{ productTitle }}</span>
    </h4>

    <!-- 星级 -->
    <div class="flex items-center gap-2" data-testid="review-stars">
      <button
        v-for="n in 5" :key="n"
        type="button"
        class="text-2xl leading-none transition-colors"
        :class="(hoverRating || rating) >= n ? 'text-[#ffb400]' : 'text-slate-300'"
        :data-testid="`review-star-${n}`"
        @mouseenter="hoverRating = n"
        @mouseleave="hoverRating = 0"
        @click="pickRating(n)"
      >
        <Star class="h-6 w-6" :fill="(hoverRating || rating) >= n ? '#ffb400' : 'none'" />
      </button>
      <span class="ml-2 text-sm text-slate-400" data-testid="review-rating-text">{{ ratingText }}</span>
    </div>

    <!-- 内容 -->
    <div class="mt-4">
      <textarea
        v-model="content"
        rows="4"
        :maxlength="MAX_CONTENT"
        placeholder="说说这件商品怎么样，给其他买家一点参考～"
        class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#1677ff]"
        data-testid="review-content"
      ></textarea>
      <div class="mt-1 text-right text-xs" :class="contentLength > MAX_CONTENT ? 'text-red-500' : 'text-slate-400'">
        {{ contentLength }}/{{ MAX_CONTENT }}
      </div>
    </div>

    <!-- 图片 -->
    <div class="mt-3 flex flex-wrap items-center gap-2" data-testid="review-images">
      <div v-for="(img, i) in images" :key="img" class="relative h-20 w-20 overflow-hidden rounded-lg border border-slate-200">
        <img :src="img" class="h-full w-full object-cover" alt="" />
        <button
          type="button"
          class="absolute right-0.5 top-0.5 rounded-full bg-black/50 p-0.5 text-white hover:bg-black/70"
          :data-testid="`review-image-remove-${i}`"
          @click="removeImage(i)"
        ><X class="h-3 w-3" /></button>
      </div>

      <label
        v-if="images.length < MAX_IMAGES"
        class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300 text-xs text-slate-400 hover:border-[#1677ff] hover:text-[#1677ff]"
        data-testid="review-image-picker"
      >
        <ImagePlus class="h-5 w-5" />
        {{ uploading ? '上传中' : '晒图' }}
        <input type="file" accept="image/*" class="hidden" :disabled="uploading" @change="onPickFile" />
      </label>
    </div>

    <!-- 匿名 -->
    <label class="mt-4 flex items-center gap-2 text-sm text-slate-600">
      <input v-model="anonymous" type="checkbox" class="h-4 w-4" data-testid="review-anonymous" />
      匿名评价
    </label>

    <!-- 提示 -->
    <p
      v-if="tip"
      class="mt-3 rounded-md px-3 py-2 text-xs"
      :class="tipType === 'ok' ? 'bg-[#e8f7ec] text-[#2e9e57]' : 'bg-red-50 text-red-500'"
      data-testid="review-tip"
    >{{ tip }}</p>

    <div class="mt-4 flex items-center gap-3">
      <button
        type="button"
        class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white transition-colors hover:bg-[#4096ff] disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="submitting || uploading"
        data-testid="review-submit"
        @click="onSubmit"
      >{{ submitting ? '提交中…' : (isEdit ? '保存修改' : '提交评价') }}</button>
      <button
        v-if="isEdit"
        type="button"
        class="text-sm text-slate-400 hover:text-slate-600"
        @click="emit('cancel')"
      >取消</button>
    </div>
  </div>
</template>
