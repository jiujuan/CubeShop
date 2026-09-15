<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { MapPin, Pencil, Plus, Star, Trash2 } from 'lucide-vue-next'
import {
  createAddress, deleteAddress, getAddresses, setDefaultAddress, updateAddress,
  type Address, type AddressPayload,
} from '@/api/user'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ShopFooter from '@/components/ShopFooter.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 收货地址管理（增删改查 + 默认地址）
 */
const auth = useAuthStore()
const addresses = ref<Address[]>([])
const loading = ref(true)
const dialogOpen = ref(false)
const editing = ref<Address | null>(null)
const saving = ref(false)
const errorMsg = ref('')

const emptyForm: AddressPayload = {
  contact_name: '',
  contact_phone: '',
  province: '',
  city: '',
  district: '',
  detail_address: '',
  is_default: false,
}
const form = ref<AddressPayload>({ ...emptyForm })

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
  form.value = { ...emptyForm }
  errorMsg.value = ''
  dialogOpen.value = true
}

function openEdit(addr: Address) {
  editing.value = addr
  form.value = {
    contact_name: addr.contact_name,
    contact_phone: addr.contact_phone_full ?? addr.contact_phone,
    province: addr.province ?? '',
    city: addr.city ?? '',
    district: addr.district ?? '',
    detail_address: addr.detail_address,
    is_default: addr.is_default,
  }
  errorMsg.value = ''
  dialogOpen.value = true
}

function validate(): string {
  if (!form.value.contact_name.trim()) return '请填写收货人姓名'
  if (!/^1[3-9]\d{9}$/.test(form.value.contact_phone)) return '请填写正确的手机号'
  if (!form.value.detail_address.trim()) return '请填写详细地址'
  return ''
}

async function submit() {
  errorMsg.value = validate()
  if (errorMsg.value) return
  saving.value = true
  try {
    if (editing.value) await updateAddress(editing.value.id, form.value)
    else await createAddress(form.value)
    dialogOpen.value = false
    await load()
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}

async function remove(addr: Address) {
  if (!confirm(`确定删除「${addr.contact_name}」的地址？`)) return
  await deleteAddress(addr.id)
  await load()
}

async function makeDefault(addr: Address) {
  await setDefaultAddress(addr.id)
  await load()
}

function fullAddress(addr: Address) {
  return [addr.province, addr.city, addr.district, addr.detail_address].filter(Boolean).join(' ')
}
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
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div
            v-for="addr in addresses" :key="addr.id"
            class="relative rounded-xl border p-5 transition-all"
            :class="addr.is_default ? 'border-[#1677ff] bg-[#f5faff]' : 'border-slate-200 bg-white hover:border-[#91caff]'"
          >
            <div class="flex items-center gap-2">
              <span class="font-medium text-slate-800">{{ addr.contact_name }}</span>
              <span class="text-sm text-slate-400">{{ addr.contact_phone }}</span>
              <span v-if="addr.is_default" class="flex items-center gap-0.5 rounded bg-[#e6f4ff] px-1.5 py-0.5 text-[11px] text-[#1677ff]">
                <Star class="h-3 w-3" /> 默认
              </span>
            </div>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ fullAddress(addr) }}</p>

            <div class="mt-4 flex items-center gap-4 text-xs">
              <button v-if="!addr.is_default" class="text-[#1677ff] hover:underline" @click="makeDefault(addr)">设为默认</button>
              <button class="flex items-center gap-0.5 text-slate-500 hover:text-[#1677ff]" @click="openEdit(addr)">
                <Pencil class="h-3.5 w-3.5" /> 编辑
              </button>
              <button class="flex items-center gap-0.5 text-slate-500 hover:text-red-500" @click="remove(addr)">
                <Trash2 class="h-3.5 w-3.5" /> 删除
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- 新增/编辑弹窗 -->
      <Teleport to="body">
        <div v-if="dialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="dialogOpen = false">
          <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h3 class="mb-4 text-base font-semibold text-slate-800">{{ editing ? '编辑地址' : '新增地址' }}</h3>

            <div class="space-y-3 text-sm">
              <div class="flex gap-3">
                <input v-model="form.contact_name" type="text" placeholder="收货人姓名" maxlength="20"
                  class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />
                <input v-model="form.contact_phone" type="tel" placeholder="手机号" maxlength="11"
                  class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />
              </div>
              <div class="flex gap-3">
                <input v-model="form.province" type="text" placeholder="省" maxlength="20"
                  class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />
                <input v-model="form.city" type="text" placeholder="市" maxlength="20"
                  class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />
                <input v-model="form.district" type="text" placeholder="区" maxlength="20"
                  class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />
              </div>
              <input v-model="form.detail_address" type="text" placeholder="详细地址（街道、门牌号）" maxlength="100"
                class="h-10 w-full rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />

              <label class="flex cursor-pointer items-center gap-2 text-slate-600">
                <input v-model="form.is_default" type="checkbox" class="accent-[#1677ff]" />
                设为默认地址
              </label>

              <p v-if="errorMsg" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500">{{ errorMsg }}</p>
            </div>

            <div class="mt-5 flex justify-end gap-2">
              <button class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50" @click="dialogOpen = false">取消</button>
              <button
                class="rounded-lg bg-[#1677ff] px-5 py-2 text-sm text-white hover:bg-[#4096ff] disabled:opacity-60"
                :disabled="saving"
                @click="submit"
              >{{ saving ? '保存中...' : '保存' }}</button>
            </div>
          </div>
        </div>
      </Teleport>
    </main>

    <ShopFooter />
  </div>
</template>
