import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { renderCsTemplate } from '@/utils/csTemplate'

const {
  getCsQuickRepliesMock, getCsTicketTypesMock, createCsQuickReplyMock, updateCsQuickReplyMock, deleteCsQuickReplyMock,
} = vi.hoisted(() => ({
  getCsQuickRepliesMock: vi.fn(),
  getCsTicketTypesMock: vi.fn(),
  createCsQuickReplyMock: vi.fn(),
  updateCsQuickReplyMock: vi.fn(),
  deleteCsQuickReplyMock: vi.fn(),
}))

vi.mock('@/api/cs', () => ({
  getCsQuickReplies: getCsQuickRepliesMock,
  getCsTicketTypes: getCsTicketTypesMock,
  createCsQuickReply: createCsQuickReplyMock,
  updateCsQuickReply: updateCsQuickReplyMock,
  deleteCsQuickReply: deleteCsQuickReplyMock,
}))

import CsQuickReplyView from '@/views/cs/CsQuickReplyView.vue'

function freshPinia(permissions: string[] = ['cs.faq.manage']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  return pinia
}

const TYPES = [
  { id: 1, name: '售后', code: 'aftersale', require_order: true },
  { id: 2, name: '售前', code: 'pre', require_order: false },
]

const ROWS = [
  { id: 1, type_id: null, type_name: null, title: '通用开场', content: '您好，很高兴为您服务', sort: 1, created_by: 1, updated_by: null, created_at: '', updated_at: '' },
  { id: 2, type_id: 1, type_name: '售后', title: '售后专属', content: '您的订单已发出', sort: 2, created_by: 1, updated_by: null, created_at: '', updated_at: '' },
  { id: 3, type_id: 2, type_name: '售前', title: '售前专属', content: '欢迎咨询', sort: 3, created_by: 1, updated_by: null, created_at: '', updated_at: '' },
]

beforeEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
  getCsTicketTypesMock.mockResolvedValue({ data: { data: TYPES } })
})

async function mountView() {
  const wrapper = mount(CsQuickReplyView, { global: { plugins: [freshPinia()] } })
  await flushPromises()
  return wrapper
}

describe('快捷回复管理页 CsQuickReplyView（CS-204）', () => {
  it('模板列表按类型分组渲染（通用 + 各类型）', async () => {
    getCsQuickRepliesMock.mockResolvedValue({ data: { data: ROWS } })
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="cs-qr-group-0"]').exists()).toBe(true) // 通用
    expect(wrapper.find('[data-testid="cs-qr-group-1"]').exists()).toBe(true) // 售后
    expect(wrapper.find('[data-testid="cs-qr-group-2"]').exists()).toBe(true) // 售前
    expect(wrapper.find('[data-testid="cs-qr-row-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-qr-row-3"]').exists()).toBe(true)
  })

  it('按类型筛选只展示该组', async () => {
    getCsQuickRepliesMock.mockResolvedValue({ data: { data: ROWS } })
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-qr-filter"]').setValue(2) // 售前
    await flushPromises()

    expect(wrapper.find('[data-testid="cs-qr-group-0"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="cs-qr-group-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="cs-qr-group-2"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-qr-row-3"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cs-qr-row-1"]').exists()).toBe(false)
  })

  it('新增模板：填写表单并提交调用 createCsQuickReply', async () => {
    getCsQuickRepliesMock.mockResolvedValue({ data: { data: [] } })
    createCsQuickReplyMock.mockResolvedValue({ data: { data: ROWS[0] } })
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-qr-create"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="cs-qr-modal"]').exists()).toBe(true)

    await wrapper.find('[data-testid="cs-qr-form-title"]').setValue('新增模板')
    await wrapper.find('[data-testid="cs-qr-form-content"]').setValue('这是内容')
    await wrapper.find('[data-testid="cs-qr-form-type"]').setValue('1')
    await wrapper.find('[data-testid="cs-qr-form-sort"]').setValue('5')
    await wrapper.find('[data-testid="cs-qr-form-save"]').trigger('click')
    await flushPromises()

    expect(createCsQuickReplyMock).toHaveBeenCalledWith({
      title: '新增模板', content: '这是内容', type_id: 1, sort: 5,
    })
  })

  it('编辑模板：弹窗回填原值并提交调用 updateCsQuickReply', async () => {
    getCsQuickRepliesMock.mockResolvedValue({ data: { data: ROWS } })
    updateCsQuickReplyMock.mockResolvedValue({ data: { data: ROWS[1] } })
    const wrapper = await mountView()

    await wrapper.findAll('[data-testid="cs-qr-edit"]').at(1)!.trigger('click') // 售后专属
    await flushPromises()

    expect((wrapper.find('[data-testid="cs-qr-form-title"]').element as HTMLInputElement).value).toBe('售后专属')
    expect((wrapper.find('[data-testid="cs-qr-form-content"]').element as HTMLTextAreaElement).value).toBe('您的订单已发出')

    await wrapper.find('[data-testid="cs-qr-form-title"]').setValue('售后专属(改)')
    await wrapper.find('[data-testid="cs-qr-form-save"]').trigger('click')
    await flushPromises()

    expect(updateCsQuickReplyMock).toHaveBeenCalledWith(2, expect.objectContaining({ title: '售后专属(改)', type_id: 1 }))
  })

  it('删除模板：二次确认后调用 deleteCsQuickReply', async () => {
    getCsQuickRepliesMock.mockResolvedValue({ data: { data: ROWS } })
    deleteCsQuickReplyMock.mockResolvedValue({ data: { data: null } })
    const wrapper = await mountView()

    await wrapper.find('[data-testid="cs-qr-delete"]').trigger('click')
    await flushPromises()
    // ConfirmDialog 使用 Teleport 到 body，需在 document.body 中查找确认按钮
    expect(document.body.querySelector('[data-testid="confirm-ok"]')).not.toBeNull()

    document.body.querySelector<HTMLButtonElement>('[data-testid="confirm-ok"]')!.click()
    await flushPromises()

    expect(deleteCsQuickReplyMock).toHaveBeenCalledWith(1)
  })

  it('变量替换工具与后端 render() 口径一致', () => {
    const out = renderCsTemplate('亲爱{user_nickname}您好，工单{ticket_no}订单{order_no}', {
      user_nickname: '小明', ticket_no: 'TK1', order_no: 'NO2',
    })
    expect(out).toBe('亲爱小明您好，工单TK1订单NO2')

    // 缺失变量替换为空串
    expect(renderCsTemplate('{user_nickname}/{ticket_no}/{order_no}', {})).toBe('//')
  })
})
