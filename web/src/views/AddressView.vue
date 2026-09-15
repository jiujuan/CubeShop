<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Home, MapPin, Pencil, Plus, School, Star, Trash2 } from 'lucide-vue-next'
import {
  deleteAddress, getAddresses, setDefaultAddress,
  type Address,
} from '@/api/user'
import AddressForm from '@/components/AddressForm.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 收货地址管理（V1.1 E04 / T-029）
 * 标签、级联选择、粘贴识别、常用度排序、删除默认提示
 */
const auth = useAuthStore()
const addresses = ref<Address[]>([])
const loading = ref(true)
const dialogOpen = ref(false)
const editing = ref<Address | null>(null)

const confirmOpen = ref(false)
const pendingDelete = ref<Address | null>(null)
const deleteHint = ref('')

/** 常用度排序：默认地址置顶，其次按使用次数、最近使用 */
const sorted = computed(() =>
  [...addresses.value].sort((a, b) => {
    if (a.is_default !== b.is_default) return a.is_default ? -1 : 1
    if ((b.used_count ?? 0) !== (a.used_count ?? 0)) return (b.used_count ?? 0) - (a.used_count ?? 0)
    return b.id - a.id
  }),
)

async function load() {
  loading.value = true
  try {
    const { data } = await getAddresses()
    addresses.value = data.data
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (auth.token) load()
  else loading.value = false
})

function openCreate() {
  editing.value = null
  dialogOpen.value = true
}

function openEdit(addr: Address) {
  editing.value = addr
  dialogOpen.value = true
}

async function onSaved() {
  dialogOpen.value = false
  await load()
}

function askRemove(addr: Address) {
  pendingDelete.value = addr
  deleteHint.value = addr.is_default ? '删除的是默认地址，删除后请重新设置一个默认地址。' : ''
  confirmOpen.value = true
}

async function doRemove() {
  if (!pendingDelete.value) return
  await deleteAddress(pendingDelete.value.id)
  confirmOpen.value = false
  pendingDelete.value = null
  await load()
}

async function makeDefault(addr: Address) {
  await setDefaultAddress(addr.id)
  await load()
}

function fullAddress(addr: Address) {
  return [addr.province, addr.city, addr.district, addr.detail_address].filter(Boolean).join(' ')
}

const labelIcon: Record<string, typeof Home> = { 家: Home, 公司: MapPin, 学校: School }
</script>

<template>
  <div>
    <ShopHeader />

    <main class="mx-auto w-full max-w-4xl flex-1 px-6 py-8">
      <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-bold text-slate-800">收货地址</h1>
        <button
          v-if="auth.token"
          class="flex items-center gap-1 rounded-full bg-[#1677ff] px-4 py-2 text-sm text-white hover:bg-[#4096ff]"
          data-testid="address-create-btn"
          @click="openCreate"
        ><Plus class="h-4 w-4" /> 新增地址</button>
      </div>

      <div v-if="loading" class="py-24"><LoadingSpinner /></div>

      <div v-else-if="!auth.token" class="flex flex-col items-center py-24 text-slate-400">
        <MapPin class="mb-3 h-10 w-10" />
        <p class="mb-4">登录后管理收货地址</p>
        <button class="rounded-full bg-[#1677ff] px-6 py-2 text-sm text-white" @click="$router.push({ path: '/login', query: { redirect: '/account/addresses' } })">去登录</button>
      </div>

      <template v-else>
        <!-- 空态 -->
        <div v-if="!addresses.length" class="flex flex-col items-center py-24 text-slate-400">
          <MapPin class="mb-3 h-10 w-10" />
          <p>还没有收货地址，点击右上角新增</p>
        </div>

        <!-- 地址卡片 -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2" data-testid="address-list">
          <div
            v-for="addr in sorted" :key="addr.id"
            class="relative rounded-xl border p-5 transition-all"
            :data-testid="`address-card-${addr.id}`"
            :class="addr.is_default ? 'border-[#1677ff] bg-[#f5faff]' : 'border-slate-200 bg-white hover:border-[#91caff]'"
          >
            <div class="flex items-center gap-2">
              <span
                v-if="addr.label"
                class="flex items-center gap-0.5 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500"
                data-testid="address-label-badge"
              >
                <component :is="labelIcon[addr.label] ?? MapPin" class="h-3 w-3" /> {{ addr.label }}
              </span>
              <span class="font-medium text-slate-800">{{ addr.contact_name }}</span>
              <span class="text-sm text-slate-400">{{ addr.contact_phone }}</span>
              <span v-if="addr.is_default" class="flex items-center gap-0.5 rounded bg-[#e6f4ff] px-1.5 py-0.5 text-[11px] text-[#1677ff]" data-testid="address-default-badge">
                <Star class="h-3 w-3" /> 默认
              </span>
            </div>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ fullAddress(addr) }}</p>
            <p v-if="addr.used_count" class="mt-1 text-xs text-slate-400">已使用 {{ addr.used_count }} 次</p>

            <div class="mt-4 flex items-center gap-4 text-xs">
              <button v-if="!addr.is_default" class="text-[#1677ff] hover:underline" @click="makeDefault(addr)">设为默认</button>
              <button class="flex items-center gap-0.5 text-slate-500 hover:text-[#1677ff]" @click="openEdit(addr)">
                <Pencil class="h-3.5 w-3.5" /> 编辑
              </button>
              <button class="flex items-center gap-0.5 text-slate-500 hover:text-red-500" @click="askRemove(addr)">
                <Trash2 class="h-3.5 w-3.5" /> 删除
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- 新增/编辑弹窗 -->
      <Teleport to="body">
        <div v-if="dialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="dialogOpen = false">
          <div class="max-h-[88vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
            <h3 class="mb-4 text-base font-semibold text-slate-800">{{ editing ? '编辑地址' : '新增地址' }}</h3>
            <AddressForm :address="editing" @saved="onSaved" @cancel="dialogOpen = false" />
          </div>
        </div>
      </Teleport>

      <ConfirmDialog
        v-model="confirmOpen"
        title="确认删除地址？"
        :content="deleteHint || '删除后不可恢复。'"
        confirm-text="确认删除"
        @confirm="doRemove"
        @cancel="confirmOpen = false"
      />
    </main>

    <ShopFooter />
  </div>
</template>
