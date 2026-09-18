<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Heart, Trash2 } from 'lucide-vue-next'
import { batchRemoveFavorites, getFavorites, type FavoriteItem } from '@/api/favorite'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Pagination from '@/components/Pagination.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 我的收藏（V1.1 F05 / T-025）
 * 支持：失效灰显、批量取消、分页、空态。
 */
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const tip = ref('')
const list = ref<FavoriteItem[]>([])
const pagination = ref({ page: 1, page_size: 20, total: 0, total_pages: 1 })
const selected = ref<Set<string>>(new Set())
const confirmOpen = ref(false)

const allSelected = computed(() => list.value.length > 0 && list.value.every((i) => selected.value.has(i.id)))

async function load(page = 1) {
  loading.value = true
  tip.value = ''
  try {
    const { data } = await getFavorites({ page, page_size: pagination.value.page_size })
    list.value = data.data.list
    pagination.value = data.data.pagination
    selected.value = new Set()
  } finally {
    loading.value = false
  }
}

function toggle(id: string) {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}

function toggleAll() {
  selected.value = allSelected.value ? new Set() : new Set(list.value.map((i) => i.id))
}

async function doBatchRemove() {
  confirmOpen.value = false
  if (!selected.value.size) return
  try {
    const { data } = await batchRemoveFavorites([...selected.value])
    // 先刷新（load 会清空 tip），再提示结果
    await load(pagination.value.page)
    tip.value = `已取消收藏 ${data.data.removed} 件商品`
  } catch (e) {
    tip.value = e instanceof Error ? e.message : '操作失败'
  }
}

function goDetail(item: FavoriteItem) {
  if (!item.is_available) return
  router.push(`/product/${item.id}`)
}

onMounted(() => {
  if (auth.token) load()
  else loading.value = false
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <ShopHeader />
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6" data-testid="favorite-view">
      <div class="mb-4 flex items-center justify-between">
        <h1 class="flex items-center gap-2 text-lg font-semibold text-slate-800">
          <Heart class="h-5 w-5 text-[#ff4d4f]" /> 我的收藏
          <span class="text-sm font-normal text-slate-400">共 {{ pagination.total }} 件</span>
        </h1>
        <div class="flex items-center gap-3 text-[13px]">
          <label v-if="list.length" class="flex cursor-pointer items-center gap-1.5 text-slate-500">
            <input type="checkbox" :checked="allSelected" data-testid="select-all" @change="toggleAll" /> 全选
          </label>
          <button
            v-if="selected.size"
            class="flex items-center gap-1 rounded-md border border-red-200 px-3 py-1.5 text-red-500 hover:bg-red-50"
            data-testid="batch-remove-btn"
            @click="confirmOpen = true"
          >
            <Trash2 class="h-3.5 w-3.5" /> 取消收藏（{{ selected.size }}）
          </button>
        </div>
      </div>

      <p v-if="tip" class="mb-3 rounded-md bg-green-50 px-3 py-2 text-[13px] text-green-600" data-testid="fav-tip">{{ tip }}</p>

      <LoadingSpinner v-if="loading" />

      <div v-else-if="!list.length" class="rounded-xl border border-slate-100 bg-white py-20 text-center">
        <p class="text-slate-400" data-testid="fav-empty">还没有收藏的商品</p>
        <button class="mt-4 rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white hover:bg-[#4096ff]" @click="router.push('/')">去逛逛</button>
      </div>

      <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4" data-testid="fav-grid">
        <div
          v-for="item in list" :key="item.id"
          class="group relative flex flex-col overflow-hidden rounded-xl border bg-white transition-all"
          :class="item.is_available ? 'border-slate-100 shadow-sm hover:-translate-y-0.5 hover:shadow-md' : 'border-slate-100 opacity-70'"
          :data-testid="`fav-item-${item.id}`"
        >
          <label class="absolute left-2 top-2 z-10 flex h-5 w-5 cursor-pointer items-center justify-center rounded bg-white/90 shadow" @click.stop>
            <input type="checkbox" :checked="selected.has(item.id)" :data-testid="`fav-check-${item.id}`" @change="toggle(item.id)" />
          </label>

          <span
            v-if="!item.is_available"
            class="absolute right-2 top-2 z-10 rounded bg-slate-700/85 px-1.5 py-0.5 text-[11px] text-white"
            :data-testid="`fav-unavailable-${item.id}`"
          >{{ item.unavailable_reason || '已失效' }}</span>

          <div
            class="relative aspect-square cursor-pointer overflow-hidden bg-gradient-to-br from-[#f5faff] to-[#e6f4ff]"
            @click="goDetail(item)"
          >
            <img v-if="item.main_image" :src="item.main_image" class="absolute inset-0 h-full w-full object-cover object-center" :class="!item.is_available && 'grayscale'" alt="" />
            <span v-else class="absolute inset-0 flex items-center justify-center text-5xl">{{ !item.is_available ? '💤' : '📦' }}</span>
          </div>

          <div class="flex flex-1 flex-col gap-1 p-3">
            <div class="truncate text-sm font-semibold text-slate-800" :title="item.title">{{ item.title }}</div>
            <div class="mt-auto flex items-end justify-between pt-1.5">
              <div class="text-base font-bold text-[#ff4d4f]"><span class="text-xs">¥</span>{{ item.price }}</div>
              <button
                v-if="item.is_available"
                class="rounded-full border border-[#1677ff] px-3 py-1 text-xs text-[#1677ff] hover:bg-[#1677ff] hover:text-white"
                @click="goDetail(item)"
              >去购买</button>
              <span v-else class="text-xs text-slate-400">不可购买</span>
            </div>
          </div>
        </div>
      </div>

      <!-- 分页（统一分页条，风格与后台一致） -->
      <Pagination v-if="pagination.total_pages > 1" :pagination="pagination" @change="(p: number) => load(p)" />
    </main>
    <ShopFooter />

    <ConfirmDialog
      v-model="confirmOpen"
      title="确认取消收藏？"
      :content="`将从收藏中移除选中的 ${selected.size} 件商品。`"
      confirm-text="确认移除"
      @confirm="doBatchRemove"
      @cancel="confirmOpen = false"
    />
  </div>
</template>
