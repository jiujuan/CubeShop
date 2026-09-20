import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { getSiteConfig, type SiteInfo } from '@/api/site'

/**
 * 站点品牌信息（名称 / 大 logo / 小 logo）的单一真源。
 *
 * 设计要点：
 * 1. **先水合缓存再回源**：`localStorage` 里存有上次拿到的品牌信息，store 初始化时同步读入，
 *    因此刷新页面时顶栏立刻就是正确名称/logo，不会先闪一下默认的「CubeShop」。
 *    回源失败时保留缓存值，网络抖动不会把品牌打回默认。
 * 2. **标题占位替换**：路由 meta 的 title 统一写品牌占位符 `CubeShop`，
 *    运行时按实际站点名替换（见 `setTitleBase`），避免在 30+ 处路由里重复维护站点名。
 */

const STORAGE_KEY = 'site_info'

/** 品牌占位符：路由 meta.title 里用它标记「此处应替换为站点名」 */
export const BRAND_PLACEHOLDER = 'CubeShop'

/** 未配置站点名时的默认值（与后端 SiteController::DEFAULT_NAME 一致） */
export const DEFAULT_SITE_NAME = 'CubeShop'

interface CachedSite {
  name: string
  logo: string
  logo_small: string
}

/** 同步读取本地缓存；损坏或不可用时回落默认值 */
function readCache(): CachedSite {
  const fallback: CachedSite = { name: DEFAULT_SITE_NAME, logo: '', logo_small: '' }
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return fallback
    const parsed = JSON.parse(raw) as Partial<CachedSite>
    return {
      name: typeof parsed.name === 'string' && parsed.name ? parsed.name : DEFAULT_SITE_NAME,
      logo: typeof parsed.logo === 'string' ? parsed.logo : '',
      logo_small: typeof parsed.logo_small === 'string' ? parsed.logo_small : '',
    }
  } catch {
    // 隐私模式 / 缓存被人为改坏：不影响页面，回落默认
    return fallback
  }
}

/** 路由 meta 里的原始标题（含品牌占位符） */
let titleBase = DEFAULT_SITE_NAME
/** 当前站点名（模块级镜像，供 setTitleBase 在无 pinia 上下文时也能渲染标题） */
let currentName = readCache().name

function renderTitle() {
  document.title = titleBase.split(BRAND_PLACEHOLDER).join(currentName)
}

/**
 * 登记路由原始标题并渲染 document.title。
 *
 * 由 `router.afterEach` 调用；不依赖 pinia，测试里单独用路由也不会抛错。
 * 之所以保留「原始标题」而不是直接改 document.title：站点名本身可能包含占位符
 * （例如站点名叫「CubeShop 旗舰店」），反复替换会导致名称被追加。
 */
export function setTitleBase(metaTitle?: string) {
  titleBase = metaTitle || DEFAULT_SITE_NAME
  renderTitle()
}

/**
 * 当前站点名（模块级镜像的只读出口）
 *
 * 供需要拼标题但**可能没有 pinia 上下文**的场景使用（如单测直接调用 SEO 工具函数）；
 * 组件内仍应优先用 `useSiteStore().name`。
 */
export function currentSiteName(): string {
  return currentName
}

export const useSiteStore = defineStore('web-site', () => {
  const cached = readCache()
  const name = ref(cached.name)
  const logo = ref(cached.logo)
  const logoSmall = ref(cached.logo_small)
  /** 是否已成功回源过（供需要区分「默认值」与「已加载」的场景使用） */
  const loaded = ref(false)

  /** 小 logo 缺失时回落大 logo，供移动端顶栏消费 */
  const mobileLogo = computed(() => logoSmall.value || logo.value)

  /** 大 logo 缺失时回落小 logo，供登录页/注册页等醒目位置消费 */
  const brandLogo = computed(() => logo.value || logoSmall.value)

  async function load() {
    try {
      const { data } = await getSiteConfig()
      const info: SiteInfo = data.data
      name.value = info.name || DEFAULT_SITE_NAME
      logo.value = info.logo || ''
      logoSmall.value = info.logo_small || ''
      loaded.value = true
      currentName = name.value
      persist()
      renderTitle()
    } catch {
      // 拉取失败：保留缓存/默认值，不打断页面渲染
    }
  }

  function persist() {
    try {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ name: name.value, logo: logo.value, logo_small: logoSmall.value }),
      )
    } catch {
      // 忽略无痕模式下的写入失败
    }
  }

  return { name, logo, logoSmall, mobileLogo, brandLogo, loaded, load }
})
