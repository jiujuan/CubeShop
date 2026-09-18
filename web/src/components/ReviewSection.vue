<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { MessageSquare, Star } from 'lucide-vue-next'
import { getProductReviews, type ReviewItem, type ReviewSummary } from '@/api/review'

/**
 * 商品详情页「用户评价」区（V1.1 F01 / T-016）
 *
 * 评分汇总卡（平均分 / 好评率 / 星级分布）+ 评价列表（头像昵称/时间/评分/内容/图片/商家回复）
 * + 筛选排序（最新 / 评分 / 带图）+ 分页加载更多 + 空态。
 */
const props = defineProps<{ productId: number | string }>()

const summary = ref<ReviewSummary | null>(null)
const list = ref<ReviewItem[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const page = ref(1)
const totalPages = ref(1)

const sort = ref<'newest' | 'rating_desc' | 'rating_asc'>('newest')
const ratingFilter = ref<number | 0>(0)
const hasImage = ref(false)

const lightbox = ref<string | null>(null)

// SEC-04：公开接口不再返回 total_pages，改由服务端 has_more 驱动"加载更多"
const hasMore = ref(false)

/** 星级分布条数据（5→1） */
const starRows = computed(() => {
  const counts = summary.value?.star_counts ?? {}
  const total = summary.value?.total ?? 0
  return [5, 4, 3, 2, 1].map((star) => {
    const count = Number(counts[String(star)] ?? counts[star] ?? 0)
    return { star, count, percent: total > 0 ? Math.round((count / total) * 100) : 0 }
  })
})

async function load(reset = true) {
  if (reset) {
    page.value = 1
    loading.value = true
  } else {
    loadingMore.value = true
  }

  try {
    const { data } = await getProductReviews(props.productId, {
      page: page.value,
      page_size: 5,
      sort: sort.value,
      rating: ratingFilter.value || undefined,
      has_image: hasImage.value ? 1 : undefined,
    })
    summary.value = data.data.summary
    totalPages.value = data.data.pagination.total_pages ?? 1
    hasMore.value = data.data.pagination.has_more ?? page.value < (data.data.pagination.total_pages ?? 1)
    list.value = reset ? data.data.list : [...list.value, ...data.data.list]
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function loadMore() {
  if (!hasMore.value || loadingMore.value) return
  page.value += 1
  load(false)
}

function setRating(r: number) {
  ratingFilter.value = ratingFilter.value === r ? 0 : r
  load()
}

function setSort(v: 'newest' | 'rating_desc' | 'rating_asc') {
  sort.value = v
}

onMounted(() => load())

watch([sort, hasImage], () => load())
</script>

<template>
  <section class="mt-12 border-t border-slate-100 pt-8" data-testid="review-section">
    <h3 class="mb-5 flex items-center gap-2 text-lg font-bold text-slate-800">
      <MessageSquare class="h-5 w-5 text-[#1677ff]" /> 用户评价
    </h3>

    <!-- 汇总卡 -->
    <div v-if="summary" class="mb-6 flex flex-wrap items-center gap-8 rounded-xl bg-[#f7faff] px-6 py-5" data-testid="review-summary">
      <div class="text-center">
        <div class="text-3xl font-bold text-[#ff6a00]" data-testid="review-avg">{{ summary.avg.toFixed(1) }}</div>
        <div class="mt-1 text-xs text-slate-400">平均分</div>
      </div>
      <div class="text-center">
        <div class="text-3xl font-bold text-[#1677ff]" data-testid="review-good-rate">{{ summary.good_rate }}%</div>
        <div class="mt-1 text-xs text-slate-400">好评率</div>
      </div>
      <div class="min-w-[200px] flex-1">
        <div v-for="row in starRows" :key="row.star" class="flex items-center gap-2 text-xs text-slate-500">
          <span class="w-8">{{ row.star }} 星</span>
          <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200">
            <div class="h-full rounded-full bg-[#ffb400]" :style="{ width: row.percent + '%' }"></div>
          </div>
          <span class="w-10 text-right">{{ row.count }}</span>
        </div>
      </div>
      <div class="text-sm text-slate-400">共 {{ summary.total }} 条评价</div>
    </div>

    <!-- 筛选 -->
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
      <button
        v-for="opt in [
          { key: 'newest', label: '最新' },
          { key: 'rating_desc', label: '好评优先' },
          { key: 'rating_asc', label: '差评优先' },
        ]" :key="opt.key"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="sort === opt.key ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
        :data-testid="`review-sort-${opt.key}`"
        @click="setSort(opt.key as 'newest' | 'rating_desc' | 'rating_asc')"
      >{{ opt.label }}</button>
      <span class="mx-1 text-slate-200">|</span>
      <button
        v-for="r in [5, 4, 3, 2, 1]" :key="r"
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="ratingFilter === r ? 'bg-[#ffb400] text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
        :data-testid="`review-filter-${r}`"
        @click="setRating(r)"
      >{{ r }} 星</button>
      <button
        class="rounded-full px-3 py-1 text-xs transition-colors"
        :class="hasImage ? 'bg-[#1677ff] text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
        data-testid="review-filter-image"
        @click="hasImage = !hasImage"
      >有图</button>
    </div>

    <!-- 列表 -->
    <div v-if="loading" class="py-10 text-center text-sm text-slate-400">加载中…</div>

    <div v-else-if="!list.length" class="flex flex-col items-center py-12 text-slate-400">
      <MessageSquare class="mb-3 h-10 w-10 text-slate-200" />
      <p class="text-sm" data-testid="review-empty">暂无评价，期待你的第一条评价</p>
    </div>

    <div v-else class="space-y-5">
      <div
        v-for="r in list" :key="r.id"
        class="border-b border-slate-50 pb-5 last:border-0"
        data-testid="review-item"
      >
        <div class="flex items-center gap-3">
          <div class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-sm text-slate-400">
            <img v-if="r.avatar" :src="r.avatar" class="h-full w-full object-cover" alt="" />
            <span v-else>{{ r.nickname?.slice(0, 1) }}</span>
          </div>
          <div>
            <div class="text-sm font-medium text-slate-700">{{ r.nickname }}</div>
            <div class="flex items-center gap-1 text-xs text-[#ffb400]">
              <Star v-for="n in 5" :key="n" class="h-3 w-3" :fill="n <= r.rating ? '#ffb400' : 'none'" :class="n <= r.rating ? '' : 'text-slate-200'" />
            </div>
          </div>
          <span class="ml-auto text-xs text-slate-400">{{ r.created_at }}</span>
        </div>

        <p v-if="r.content" class="mt-3 text-sm leading-6 text-slate-600">{{ r.content }}</p>

        <div v-if="r.images?.length" class="mt-3 flex gap-2">
          <img
            v-for="img in r.images" :key="img"
            :src="img" class="h-20 w-20 cursor-pointer rounded-lg object-cover"
            alt="" @click="lightbox = img"
          />
        </div>

        <div v-if="r.reply_content" class="mt-3 rounded-lg bg-[#f7faff] px-3 py-2 text-xs text-slate-500" data-testid="review-reply">
          <span class="font-medium text-[#1677ff]">商家回复：</span>{{ r.reply_content }}
        </div>
      </div>

      <div v-if="hasMore" class="pt-2 text-center">
        <button
          class="rounded-full border border-slate-200 px-6 py-2 text-sm text-slate-500 hover:border-[#1677ff] hover:text-[#1677ff]"
          :disabled="loadingMore"
          data-testid="review-load-more"
          @click="loadMore"
        >{{ loadingMore ? '加载中…' : '加载更多' }}</button>
      </div>
    </div>

    <!-- 图片放大 -->
    <div
      v-if="lightbox"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-8"
      data-testid="review-lightbox"
      @click="lightbox = null"
    >
      <img :src="lightbox" class="max-h-full max-w-full rounded-lg" alt="" />
    </div>
  </section>
</template>
