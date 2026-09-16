import type { CouponType, CouponScope } from '@/api/coupon'

/**
 * 券面额文案：
 *  - percent：percent 为百分比整数（90=9折，85=8.5折）
 *  - fixed：¥{amount}
 */
export function couponValueText(c: { type: CouponType | null; amount?: number | null; percent?: number | null }): string {
  if (c.type === 'percent') {
    const p = Number(c.percent ?? 0)
    const zhe = p / 10
    const text = Number.isInteger(zhe) ? zhe.toFixed(0) : zhe.toFixed(1)
    return `${text}折`
  }
  return `¥${Number(c.amount ?? 0).toFixed(0)}`
}

/** 使用门槛文案：min_spend>0 为「满{min}可用」，否则「无门槛」 */
export function couponConditionText(c: { min_spend?: number }): string {
  const m = Number(c.min_spend ?? 0)
  return m > 0 ? `满${m}可用` : '无门槛'
}

/** 适用范围文案（补充 SCOPE_LABELS 之外的细化描述） */
export function couponScopeText(scope: CouponScope | null | undefined): string {
  switch (scope) {
    case 'all':
      return '全场通用'
    case 'category':
      return '指定分类'
    case 'product':
      return '指定商品'
    default:
      return ''
  }
}
