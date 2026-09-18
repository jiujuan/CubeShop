<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Sparkles } from 'lucide-vue-next'
import {
  createAddress, parseAddress, updateAddress,
  type Address, type AddressPayload,
} from '@/api/user'
import {
  listCities, listDistricts, listProvinces, type RegionNode,
} from '@/lib/region'

/**
 * 收货地址表单（可复用：地址管理页 / 结算页）
 *
 * 所在地区：省、市、区统一由公共地区字典驱动的下拉选择（数据源 shared/region-dict/regions.json），
 * 不允许手动输入，避免脏数据影响运费按省匹配（收货省名需能被字典解析成 GB/T 2260 编码）。
 * 详细地址保持手动输入；保存成功后 emit('saved', address)。
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

const provinces = ref<RegionNode[]>([])
const cities = ref<RegionNode[]>([])
const districts = ref<RegionNode[]>([])
const regionReady = ref(false)

/** 选择态用字典 code（唯一定位，避免同名区县误判）；提交时换算回名称 */
const provinceCode = ref('')
const cityCode = ref('')
const districtCode = ref('')

const form = ref({
  contact_name: '',
  contact_phone: '',
  detail_address: '',
  label: null as string | null,
  is_default: false,
})

const saving = ref(false)
const errorMsg = ref('')

/** 粘贴识别 */
const pasteText = ref('')
const parsing = ref(false)
const parseHint = ref('')

const provinceName = computed(() => provinces.value.find((p) => p.code === provinceCode.value)?.name ?? '')
const cityName = computed(() => cities.value.find((c) => c.code === cityCode.value)?.name ?? '')
const districtName = computed(() => districts.value.find((d) => d.code === districtCode.value)?.name ?? '')

onMounted(async () => {
  provinces.value = await listProvinces()
  regionReady.value = true
  if (props.address) {
    const a = props.address
    form.value = {
      contact_name: a.contact_name,
      contact_phone: a.contact_phone_full ?? a.contact_phone,
      detail_address: a.detail_address,
      label: a.label,
      is_default: a.is_default,
    }
    await selectByName(a.province ?? '', a.city ?? '', a.district ?? '')
  }
})

watch(provinceCode, async (code) => {
  cityCode.value = ''
  districtCode.value = ''
  districts.value = []
  cities.value = code ? await listCities(code) : []
})

watch(cityCode, async (code) => {
  districtCode.value = ''
  districts.value = code ? await listDistricts(provinceCode.value, code) : []
})

/**
 * 按名称回填三级选择（编辑历史地址 / 粘贴识别结果）。
 * 名称不在字典内时忽略该级并在粘贴场景给出提示。
 */
async function selectByName(province: string, city: string, district: string): Promise<boolean> {
  const p = provinces.value.find((x) => x.name === province)
  provinceCode.value = p?.code ?? ''
  if (province && !p) return false

  cities.value = provinceCode.value ? await listCities(provinceCode.value) : []
  const c = cities.value.find((x) => x.name === city)
  cityCode.value = c?.code ?? ''

  districts.value = cityCode.value ? await listDistricts(provinceCode.value, cityCode.value) : []
  const d = districts.value.find((x) => x.name === district)
  districtCode.value = d?.code ?? ''

  return true
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
    if (r.detail_address) form.value.detail_address = r.detail_address

    const matched = await selectByName(r.province ?? '', r.city ?? '', r.district ?? '')
    parseHint.value = matched
      ? (r.confidence >= 0.75 ? '识别完成，请核对后保存' : '识别信息可能不完整，请补充核对')
      : '地区未匹配到字典，请从下拉列表中重新选择'
  } catch (e) {
    parseHint.value = e instanceof Error ? e.message : '识别失败，请手动填写'
  } finally {
    parsing.value = false
  }
}

function validate(): string {
  if (!form.value.contact_name.trim()) return '请填写收货人姓名'
  if (!/^1[3-9]\d{9}$/.test(form.value.contact_phone)) return '请填写正确的手机号'
  if (!provinceCode.value) return '请选择省'
  if (cities.value.length > 0 && !cityCode.value) return '请选择市'
  if (districts.value.length > 0 && !districtCode.value) return '请选择区/县'
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
      province: provinceName.value,
      city: cityName.value,
      district: districtName.value,
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

    <!-- 所在地区：省/市/区统一走地区字典下拉，禁止手输 -->
    <div class="flex items-center justify-between text-xs text-slate-400">
      <span>所在地区</span>
      <span class="text-[11px]">省 / 市 / 区均从地区字典中选择</span>
    </div>
    <div class="flex gap-3">
      <select
        v-model="provinceCode" data-testid="address-province"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-2 outline-none focus:border-[#1677ff]"
      >
        <option value="">{{ regionReady ? '请选择省' : '加载中…' }}</option>
        <option v-for="p in provinces" :key="p.code" :value="p.code">{{ p.name }}</option>
      </select>
      <select
        v-model="cityCode" data-testid="address-city"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-2 outline-none focus:border-[#1677ff] disabled:bg-slate-50"
        :disabled="!provinceCode || cities.length === 0"
      >
        <option value="">{{ cities.length ? '请选择市' : '—' }}</option>
        <option v-for="c in cities" :key="c.code" :value="c.code">{{ c.name }}</option>
      </select>
      <select
        v-model="districtCode" data-testid="address-district"
        class="h-10 flex-1 rounded-lg border border-slate-200 px-2 outline-none focus:border-[#1677ff] disabled:bg-slate-50"
        :disabled="!cityCode || districts.length === 0"
      >
        <option value="">{{ districts.length ? '请选择区/县' : '—' }}</option>
        <option v-for="d in districts" :key="d.code" :value="d.code">{{ d.name }}</option>
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
