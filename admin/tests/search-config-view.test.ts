import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import permission from '@/directives/permission'
import { useAuthStore } from '@/stores/auth'

const {
  getSearchConfigMock,
  updateSearchConfigMock,
  getSearchKeywordsMock,
  reindexSearchMock,
} = vi.hoisted(() => ({
  getSearchConfigMock: vi.fn(),
  updateSearchConfigMock: vi.fn(),
  getSearchKeywordsMock: vi.fn(),
  reindexSearchMock: vi.fn(),
}))

vi.mock('@/api/search', () => ({
  getSearchConfig: getSearchConfigMock,
  updateSearchConfig: updateSearchConfigMock,
  getSearchKeywords: getSearchKeywordsMock,
  reindexSearch: reindexSearchMock,
}))

import SearchConfigView from '@/views/system/SearchConfigView.vue'

/** 与后端 GET /admin/search/config 契约一致的形态 */
const configFixture = () => ({
  engine: '',
  active_engine: 'postgres',
  degraded: false,
  engines: [
    { key: '', label: '自动（PG 可用则用，否则降级 LIKE）', available: true, current: true },
    { key: 'postgres', label: 'PostgreSQL 原生全文检索', available: true, current: false },
    { key: 'like', label: 'LIKE 降级引擎（等同改造前行为）', available: true, current: false },
  ],
  switches: {
    'search.index_taxonomy_names': '1',
    'search.cache_ttl': '60',
    'search.expose_debug': '0',
  },
  index_version: 3,
})

const keywordsFixture = () => ({
  list: [
    { id: 1, keyword: '沙发', hit_count: 12, result_count: 8, last_hit_at: '2026-09-24 08:00:00', status: 1, updated_at: null },
    { id: 2, keyword: '北欧沙发', hit_count: 5, result_count: 0, last_hit_at: '2026-09-24 07:00:00', status: 1, updated_at: null },
  ],
  pagination: { page: 1, page_size: 20, total: 2, total_pages: 1, has_more: false },
})

function freshPinia(permissions: string[] = ['search.manage']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  // ⚠️ 用 operator 而非 super_admin：hasPermission 对 super_admin 短路放行，
  // 会架空「无 search.manage 时按钮禁用」的用例。
  auth.user = {
    id: 1,
    username: 'operator',
    nickname: null,
    avatar: null,
    phone: null,
    email: null,
    roles: ['operator'],
    permissions,
  }
  return pinia
}

const globalCfg = (pinia: ReturnType<typeof freshPinia>) => ({
  plugins: [pinia],
  directives: { permission },
})

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getSearchConfigMock.mockResolvedValue({ data: { code: 0, data: configFixture() } })
  getSearchKeywordsMock.mockResolvedValue({ data: { code: 0, data: keywordsFixture() } })
  updateSearchConfigMock.mockResolvedValue({
    data: { code: 0, data: { engine: 'postgres', switches: configFixture().switches, index_version: 4 } },
  })
  reindexSearchMock.mockResolvedValue({
    data: { code: 0, data: { updated: 44, index_version: 5, elapsed_ms: 120 } },
  })
})

async function mountView(permissions?: string[]) {
  const wrapper = mount(SearchConfigView, { global: globalCfg(freshPinia(permissions)) })
  await flushPromises()
  return wrapper
}

describe('搜索配置页 SearchConfigView（S1-09 后半）', () => {
  it('渲染引擎选项与开关初值，标记当前生效引擎', async () => {
    const wrapper = await mountView()

    const radios = wrapper.findAll('[data-testid^="search-engine-"]')
    expect(radios).toHaveLength(3)
    // 当前配置 engine='' → 自动选中「自动」
    expect((radios[0].element as HTMLInputElement).checked).toBe(true)

    expect((wrapper.find('[data-testid="search-switch-search.index_taxonomy_names"]').element as HTMLInputElement).checked).toBe(true)
    expect((wrapper.find('[data-testid="search-input-search.cache_ttl"]').element as HTMLInputElement).value).toBe('60')

    // 索引版本展示
    expect(wrapper.text()).toContain('v3')
  })

  it('未做改动时保存按钮禁用；改动开关后允许保存，且只提交变化了的键', async () => {
    const wrapper = await mountView()

    expect((wrapper.find('[data-testid="search-save"]').element as HTMLButtonElement).disabled).toBe(true)

    await wrapper.find('[data-testid="search-input-search.cache_ttl"]').setValue('120')
    expect((wrapper.find('[data-testid="search-save"]').element as HTMLButtonElement).disabled).toBe(false)

    await wrapper.find('[data-testid="search-save"]').trigger('click')
    await flushPromises()

    // 只提交改动的键；engine 未动不提交
    expect(updateSearchConfigMock).toHaveBeenCalledTimes(1)
    const payload = updateSearchConfigMock.mock.calls[0][0]
    expect(payload.engine).toBeUndefined()
    expect(payload.switches).toEqual({ 'search.cache_ttl': '120' })
    // 重新拉取配置
    expect(getSearchConfigMock).toHaveBeenCalledTimes(2)
  })

  it('切换引擎时提交 engine=auto 表示「自动」，而不是空串（空串会被中间件吃成 null）', async () => {
    // 从 postgres 切回自动
    getSearchConfigMock.mockResolvedValue({
      data: { code: 0, data: { ...configFixture(), engine: 'postgres', active_engine: 'postgres' } },
    })
    const wrapper = await mountView()

    await wrapper.find('[data-testid="search-engine-auto"]').setValue()
    await wrapper.find('[data-testid="search-save"]').trigger('click')
    await flushPromises()

    expect(updateSearchConfigMock.mock.calls[0][0].engine).toBe('auto')
    expect(updateSearchConfigMock.mock.calls[0][0].switches).toBeUndefined()
  })

  it('PG 不可用（degraded）时显示降级提示条', async () => {
    getSearchConfigMock.mockResolvedValue({
      data: {
        code: 0,
        data: { ...configFixture(), engine: 'postgres', active_engine: 'like', degraded: true },
      },
    })
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="search-degraded"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="search-degraded"]').text()).toContain('降级')
  })

  it('改「品牌/分类名入索引」出现重建提示，保存后随重新加载消失', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="search-switch-search.index_taxonomy_names"]').setValue(false)
    expect(wrapper.find('[data-testid="search-taxonomy-dirty"]').exists()).toBe(true)

    await wrapper.find('[data-testid="search-save"]').trigger('click')
    await flushPromises()

    // 保存后 load() 拿到的是未改动的初值 → 提示条消失
    expect(wrapper.find('[data-testid="search-taxonomy-dirty"]').exists()).toBe(false)
  })

  it('无 search.manage 权限：保存与重建按钮禁用，但页面可见', async () => {
    const wrapper = await mountView([])

    expect((wrapper.find('[data-testid="search-save"]').element as HTMLButtonElement).disabled).toBe(true)
    expect((wrapper.find('[data-testid="search-reindex-open"]').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('热搜词列表渲染；「有人搜但搜不到」的行高亮并计数', async () => {
    const wrapper = await mountView()

    const rows = wrapper.findAll('tbody tr[data-testid^="search-kw-row-"]')
    expect(rows).toHaveLength(2)
    // result_count=0 且 hit_count>0 → 标注「搜不到」
    expect(wrapper.find('[data-testid="search-kw-row-2"]').text()).toContain('搜不到')
    expect(wrapper.find('[data-testid="search-kw-row-1"]').text()).not.toContain('搜不到')
    expect(wrapper.text()).toContain('1 个「有人搜但搜不到」')
  })

  it('热搜词筛选框回车触发查询', async () => {
    const wrapper = await mountView()
    expect(getSearchKeywordsMock).toHaveBeenCalledTimes(1)

    await wrapper.find('[data-testid="search-kw-filter"]').setValue('沙发')
    await wrapper.find('[data-testid="search-kw-filter"]').trigger('keyup.enter')

    expect(getSearchKeywordsMock).toHaveBeenCalledTimes(2)
    expect(getSearchKeywordsMock.mock.calls[1][0]).toMatchObject({ keyword: '沙发', page: 1 })
  })

  it('重建索引：打开确认弹层 → 确认执行 → 显示统计并刷新配置', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="search-reindex-open"]').trigger('click')
    expect(reindexSearchMock).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="search-reindex-open"]').trigger('click')
    // ConfirmDialog teleport 到 body，用 document 查询
    const confirmBtn = [...document.querySelectorAll('button')].find((b) => b.textContent?.includes('开始重建'))
    expect(confirmBtn).toBeTruthy()
    confirmBtn!.click()
    await flushPromises()

    expect(reindexSearchMock).toHaveBeenCalledTimes(1)
    // 提示条带统计，配置重新加载（index_version 可能已变）
    expect(getSearchConfigMock).toHaveBeenCalledTimes(2)
    const tip = wrapper.find('[data-testid="search-tip"]')
    expect(tip.exists()).toBe(true)
    expect(tip.text()).toContain('44 条')
  })
})
