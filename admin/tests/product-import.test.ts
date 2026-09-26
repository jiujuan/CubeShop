import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { importProductsMock, downloadTemplateMock } = vi.hoisted(() => ({
  importProductsMock: vi.fn(),
  downloadTemplateMock: vi.fn(),
}))

vi.mock('@/api/product', () => ({
  importProducts: importProductsMock,
  downloadProductImportTemplate: downloadTemplateMock,
}))

import ProductImportView from '@/views/product/ProductImportView.vue'

beforeEach(() => {
  importProductsMock.mockReset()
  downloadTemplateMock.mockReset()
  downloadTemplateMock.mockResolvedValue(undefined)
})

function mountView() {
  return mount(ProductImportView)
}

/** 造一个 xlsx 后缀的 File，绕过组件的后缀校验 */
function makeFile(name = 'products.xlsx') {
  return new File(['dummy'], name, { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
}

async function selectFile(w: ReturnType<typeof mountView>, file = makeFile()) {
  const input = w.find('[data-testid="import-file-input"]')
  Object.defineProperty(input.element, 'files', { value: [file], writable: false })
  await input.trigger('change')
  return file
}

describe('商品批量导入页 ProductImportView', () => {
  it('渲染行数上限提示与两个模式切换', () => {
    const w = mountView()

    expect(w.find('[data-testid="import-limit-note"]').text()).toContain('300')
    expect(w.find('[data-testid="import-limit-note"]').text()).toContain('1000')
    expect(w.find('[data-testid="import-mode-create"]').exists()).toBe(true)
    expect(w.find('[data-testid="import-mode-update"]').exists()).toBe(true)
  })

  it('切换模式后下载模板带对应 mode', async () => {
    const w = mountView()

    await w.find('[data-testid="import-mode-update"]').trigger('click')
    await w.find('[data-testid="import-download-template"]').trigger('click')
    await flushPromises()

    expect(downloadTemplateMock).toHaveBeenCalledWith('update')

    await w.find('[data-testid="import-mode-create"]').trigger('click')
    await w.find('[data-testid="import-download-template"]').trigger('click')
    await flushPromises()
    expect(downloadTemplateMock).toHaveBeenLastCalledWith('create')
  })

  it('上传成功后展示新建商品数与 SKU 数', async () => {
    importProductsMock.mockResolvedValue({
      data: { data: { mode: 'create', total: 3, success: 3, products: 1, failed: [] } },
    })
    const w = mountView()
    const file = await selectFile(w)

    await w.find('[data-testid="import-upload"]').trigger('click')
    await flushPromises()

    expect(importProductsMock).toHaveBeenCalledWith(file, 'create')
    expect(w.find('[data-testid="import-success"]').text()).toContain('新建 1 个商品')
    expect(w.find('[data-testid="import-success"]').text()).toContain('3 个 SKU')
  })

  it('预校验失败时展示逐行失败明细', async () => {
    importProductsMock.mockRejectedValue({
      message: '校验未全部通过，未导入任何数据',
      data: {
        data: {
          mode: 'create',
          total: 2,
          success: 0,
          failed: [
            { row: 3, code: 'P001', sku_code: 'SKU-2', reason: '分类「配件」存在多个同名分类，请改填分类ID' },
          ],
        },
      },
    })
    const w = mountView()
    await selectFile(w)

    await w.find('[data-testid="import-upload"]').trigger('click')
    await flushPromises()

    const failed = w.find('[data-testid="import-failed"]')
    expect(failed.exists()).toBe(true)
    expect(failed.text()).toContain('未导入任何数据')
    expect(failed.text()).toContain('多个同名分类')
    expect(failed.text()).toContain('P001')
  })

  it('上传非 xlsx 文件时给出提示且不调用接口', async () => {
    const w = mountView()
    await selectFile(w, makeFile('products.csv'))

    expect(w.find('[data-testid="import-error"]').text()).toContain('仅支持 .xlsx')
    expect((w.find('[data-testid="import-upload"]').element as HTMLButtonElement).disabled).toBe(true)
    expect(importProductsMock).not.toHaveBeenCalled()
  })
})
