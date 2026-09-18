import { describe, expect, it } from 'vitest'
import { formatRefundLogFields } from '@/lib/refundLog'

/**
 * 退款处理流水格式化（纯函数）
 * 覆盖：中文键值、类型枚举、金额、图片识别、实收明细行、JSON 兜底、非 JSON 回退。
 */
describe('formatRefundLogFields', () => {
  it('把已知键转成中文标签，type 枚举与金额做本地化', () => {
    const fields = formatRefundLogFields(null, {
      refund_no: 'RF1',
      type: 'return_refund',
      amount: '88.00',
      reason: '商品破损',
    })
    const byKey = Object.fromEntries(fields.map((f) => [f.key, f]))

    expect(byKey.refund_no).toMatchObject({ label: '退款单号', kind: 'text', text: 'RF1' })
    expect(byKey.type).toMatchObject({ label: '退款类型', text: '退货退款' })
    expect(byKey.amount).toMatchObject({ label: '退款金额', kind: 'money', text: '¥88.00' })
    expect(byKey.reason).toMatchObject({ label: '退款理由', text: '商品破损' })
  })

  it('图片数组识别为 images（含 admin_images / images 与扩展名兜底）', () => {
    const fields = formatRefundLogFields(null, {
      images: ['/storage/a.jpg', '/storage/b.png'],
      admin_images: ['/storage/c.webp'],
      attachments: ['/storage/d.jpeg'],
    })
    const byKey = Object.fromEntries(fields.map((f) => [f.key, f]))

    expect(byKey.images.kind).toBe('images')
    expect(byKey.images.images).toEqual(['/storage/a.jpg', '/storage/b.png'])
    expect(byKey.admin_images.label).toBe('说明图片')
    expect(byKey.admin_images.kind).toBe('images')
    // 非图片键名但值为图片扩展名 → 也识别为图片
    expect(byKey.attachments.kind).toBe('images')
  })

  it('实收明细数组渲染为多行文本并翻译商品状态', () => {
    const fields = formatRefundLogFields(null, {
      received_details: [
        { sku_id: 8, quantity: 2, condition: 'good' },
        { sku_id: 9, quantity: 1, condition: 'defective' },
      ],
    })
    const detail = fields.find((f) => f.key === 'received_details')!

    expect(detail.kind).toBe('lines')
    expect(detail.label).toBe('实收明细')
    expect(detail.lines).toEqual(['SKU#8 × 2（正品）', 'SKU#9 × 1（残次）'])
  })

  it('未知键回退键名，空值被过滤，嵌套对象按 JSON 兜底', () => {
    const fields = formatRefundLogFields(null, {
      unknown_key: 'v',
      empty: null,
      blank: '',
      nested: { a: 1 },
    })
    const byKey = Object.fromEntries(fields.map((f) => [f.key, f]))

    expect(byKey.unknown_key).toMatchObject({ label: 'unknown_key', kind: 'text', text: 'v' })
    expect(byKey.empty).toBeUndefined()
    expect(byKey.blank).toBeUndefined()
    expect(byKey.nested.kind).toBe('json')
    expect(byKey.nested.raw).toBe('{"a":1}')
  })

  it('content_data 缺失时回退解析 content JSON；非 JSON 则原样文本展示', () => {
    const parsed = formatRefundLogFields('{"reason":"不想要了"}')
    expect(parsed).toHaveLength(1)
    expect(parsed[0]).toMatchObject({ label: '退款理由', text: '不想要了' })

    const raw = formatRefundLogFields('纯文本日志')
    expect(raw).toEqual([{ key: '_text', label: '', kind: 'text', text: '纯文本日志' }])

    expect(formatRefundLogFields(null)).toEqual([])
  })
})
