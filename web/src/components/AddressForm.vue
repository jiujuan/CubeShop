<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Sparkles } from 'lucide-vue-next'
import {
  createAddress, getRegions, parseAddress, updateAddress,
  type Address, type AddressPayload, type RegionNode,
} from '@/api/user'

/**
 * 收货地址表单（V1.1 E04 / T-029，可复用）
 *
 * 能力：省市区三级级联 + 手输切换、地址标签、粘贴识别预填。
 * 保存成功后 emit('saved', address)，由父级决定后续动作（关闭弹窗 / 选中新地址）。
 */
const props = defineProps<{
  /** 编辑时传入；新增为 null */
  address?: Address | null
}>()

const emit = defineEmits<{
  (e: 'saved', address: Address): void
  (e: 'cancel'): void
}>()

const LABELS = ['家', '公司', '学校'] as const

const regions = ref<RegionNode[]>([])
const form = ref<AddressPayload>({
  contact_name: '',
  contact_phone: '',
  province: '',
  city: '',
  district: '',
  detail_address: '',
  label: null,
  is_default: false,
})

/** 手动输入模式（关闭级联，允许自由填写省市） */
const manualMode = ref(false)
const saving = ref(false)
const errorMsg = ref('')

/** 粘贴识别 */
const pasteText = ref('')
const parsing = ref(false)
const parseHint = ref('')

onMounted(async () => {
  try {
    const { data } = await getRegions()
    regions.value = data.data.regions
  } catch {
    regions.value = []
  }
  if (props.address) {
    const a = props.address
    form.value = {
      contact_name: a.contact_name,
      contact_phone: a.contact_phone_full ?? a.contact_phone,
      province: a.province ?? '',
      city: a.city ?? '',
      district: a.district ?? '',
      detail_address: a.detail_address,
      label: a.label,
      is_default: a.is_default,
    }
    // 编辑历史数据：若省市区不在级联数据中，自动切手输
    if (a.province && !regions.value.some((p) => p.name === a.province)) manualMode.value = true
  }
})

const cityOptions = computed(
  () => regions.value.find((p) => p.name === form.value.province)?.children ?? [],
)
const districtOptions = computed(
  () => cityOptions.value.find((c) => c.name === form.value.city)?.children ?? [],
)

function onProvinceChange() {
  form.value.city = ''
  form.value.district = ''
}
function onCityChange() {
  form.value.district = ''
}

function pickLabel(label: string) {
  form.value.label = form.value.label === label ? null : label
}

/** 粘贴识别：调用后端解析接口预填字段 */
async function doParse() {
  const text = pasteText.value.trim()
  if (!text) return
  parsing.value = true
  parseHint.value = ''
  try {
    const { data } = await parseAddress(text)
    const r = data.data
    if (r.contact_name) form.value.contact_name = r.contact_name
    if (r.contact_phone) form.value.contact_phone = r.contact_phone
    if (r.province) form.value.province = r.province
    if (r.city) form.value.city = r.city
    if (r.district) form.value.district = r.district
    if (r.detail_address) form.value.detail_address = r.detail_address
    // 若解析到的省市不在级联数据中，切手输避免被清空
    if (r.province && !regions.value.some((p) => p.name === r.province)) manualMode.value = true
    parseHint.value = r.confidence >= 0.75 ? '识别完成，请核对后保存' : '识别信息可能不完整，请补充核对'
  } catch (e) {
    parseHint.value = e instanceof Error ? e.message : '识别失败，请手动填写'
  } finally {
    parsing.value = false
  }
}

function validate(): string {
  if (!form.value.contact_name.trim()) return '请填写收货人姓名'
  if (!/^1[3-9]\d{9}$/.test(form.value.contact_phone)) return '请填写正确的手机号'
  if (!manualMode.value && (!form.value.province || !form.value.city)) return '请选择所在省市'
  if (manualMode.value && !form.value.province?.trim()) return '请填写省份'
  if (!form.value.detail_address.trim()) return '请填写详细地址'
  return ''
}

async function submit() {
  errorMsg.value = validate()
  if (errorMsg.value) return
  saving.value = true
  try {
    const payload: AddressPayload = {
      ...form.value,
      province: form.value.province || null,
      city: form.value.city || null,
      district: form.value.district || null,
    }
    const { data } = props.address
      ? await updateAddress(props.address.id, payload)
      : await createAddress(payload)
    emit('saved', data.data)
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '保存失败'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form class="space-y-3 text-sm" @submit.prevent="submit">
    <!-- 粘贴识别 -->
    <div class="rounded-lg bg-[#f5faff] p-3">
      <div class="mb-2 flex items-center gap-1 text-xs font-medium text-[#1677ff]">
        <Sparkles class="h-3.5 w-3.5" /> 粘贴识别（可选）
      </div>
      <div class="flex gap-2">
        <input
          v-model="pasteText"
          type="text"
          data-testid="address-paste"
          placeholder="粘贴「张三 13800001111 广东省深圳市南山区科技路1号」"
          class="h-9 flex-1 rounded-lg border border-slate-200 px-3 text-xs outline-none focus:border-[#1677ff]"
        />
        <button
          type="button"
          data-testid="address-parse-btn"
          class="shrink-0 rounded-lg bg-[#1677ff] px-3 text-xs text-white hover:bg-[#4096ff] disabled:opacity-60"
          :disabled="parsing"
          @click="doParse"
        >{{ parsing ? '识别中…' : '识别' }}</button>
      </div>
      <p v-if="parseHint" class="mt-1.5 text-xs text-slate-500" data-testid="address-parse-hint">{{ parseHint }}</p>
    </div>

    <!-- 联系人 -->
    <div class="flex gap-3">
      <input
        v-model="form.contact_name" type="text" placeholder="收货人姓名" maxlength="20"
        data-testid="address-name"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]"
      />
      <input
        v-model="form.contact_phone" type="tel" placeholder="手机号" maxlength="11"
        data-testid="address-phone"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]"
      />
    </div>

    <!-- 省市区 -->
    <div class="flex items-center justify-between text-xs text-slate-400">
      <span>所在地区</span>
      <button type="button" class="text-[#1677ff] hover:underline" data-testid="address-toggle-manual" @click="manualMode = !manualMode">
        {{ manualMode ? '使用级联选择' : '手动输入' }}
      </button>
    </div>
    <div v-if="manualMode" class="flex gap-3">
      <input v-model="form.province" type="text" placeholder="省" maxlength="20" data-testid="address-province-manual"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />
      <input v-model="form.city" type="text" placeholder="市" maxlength="20" data-testid="address-city-manual"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />
      <input v-model="form.district" type="text" placeholder="区" maxlength="20" data-testid="address-district-manual"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]" />
    </div>
    <div v-else class="flex gap-3">
      <select
        v-model="form.province" data-testid="address-province"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-2 outline-none focus:border-[#1677ff]"
        @change="onProvinceChange"
      >
        <option value="">请选择省</option>
        <option v-for="p in regions" :key="p.name" :value="p.name">{{ p.name }}</option>
      </select>
      <select
        v-model="form.city" data-testid="address-city"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-2 outline-none focus:border-[#1677ff]"
        :disabled="!form.province"
        @change="onCityChange"
      >
        <option value="">请选择市</option>
        <option v-for="c in cityOptions" :key="c.name" :value="c.name">{{ c.name }}</option>
      </select>
      <select
        v-model="form.district" data-testid="address-district"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-2 outline-none focus:border-[#1677ff]"
        :disabled="!form.city"
      >
        <option value="">请选择区</option>
        <option v-for="d in districtOptions" :key="d.code" :value="d.name">{{ d.name }}</option>
      </select>
    </div>

    <input
      v-model="form.detail_address" type="text" placeholder="详细地址（街道、门牌号）" maxlength="100"
      data-testid="address-detail"
      class="h-10 w-full rounded-lg border border-slate-200 px-3 outline-none focus:border-[#1677ff]"
    />

    <!-- 标签 -->
    <div class="flex items-center gap-2">
      <span class="text-xs text-slate-400">标签</span>
      <button
        v-for="lb in LABELS" :key="lb" type="button"
        :data-testid="`address-label-${lb}`"
        class="rounded-full border px-3 py-1 text-xs transition-colors"
        :class="form.label === lb ? 'border-[#1677ff] bg-[#e6f4ff] text-[#1677ff]' : 'border-slate-200 text-slate-500 hover:border-[#1677ff]'"
        @click="pickLabel(lb)"
      >{{ lb }}</button>
      <input
        v-model="form.label" type="text" placeholder="自定义" maxlength="6"
        data-testid="address-label-custom"
        class="h-7 w-20 rounded-full border border-slate-200 px-2 text-xs outline-none focus:border-[#1677ff]"
      />
    </div>

    <label class="flex cursor-pointer items-center gap-2 text-slate-600">
      <input v-model="form.is_default" type="checkbox" class="accent-[#1677ff]" data-testid="address-default" />
      设为默认地址
    </label>

    <p v-if="errorMsg" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-500" data-testid="address-form-error">{{ errorMsg }}</p>

    <div class="flex justify-end gap-2 pt-1">
      <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-slate-600 hover:bg-slate-50" @click="emit('cancel')">取消</button>
      <button
        type="submit"
        data-testid="address-save"
        class="rounded-lg bg-[#1677ff] px-5 py-2 text-white hover:bg-[#4096ff] disabled:opacity-60"
        :disabled="saving"
      >{{ saving ? '保存中…' : '保存' }}</button>
    </div>
  </form>
</template>
