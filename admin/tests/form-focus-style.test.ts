import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

/**
 * 表单控件聚焦样式守卫（体例同 sidebar-icon.test.ts：读源文件做正则扫描）
 *
 * 站内约定：输入框/下拉框/多行文本聚焦时描边变主色蓝 —— `outline-none focus:border-[#1677ff]`
 * （营销管理页 CouponPanel / PromotionPanel 的既有写法）。
 * 内容管理页（栏目编辑弹窗、文章编辑抽屉、单页字段表单、区块编辑器）此前漏加，故加此守卫防回归。
 */
const TARGETS = [
  'src/views/cs/CsFaqView.vue',
  'src/components/PageFieldForm.vue',
  'src/components/PageBlockEditor.vue',
]

/** 需要聚焦描边的控件：文本类输入、下拉、多行文本（复选框/单选框/隐藏的文件域不适用） */
function extractFormControls(relPath: string): string[] {
  const src = readFileSync(resolve(process.cwd(), relPath), 'utf-8')

  return [...src.matchAll(/<(input|select|textarea)\b[^>]*>/g)]
    .map((m) => m[0])
    .filter((tag) => !/type="(checkbox|radio|file)"/.test(tag) && !/class="hidden"/.test(tag))
}

describe('内容管理页表单控件聚焦样式', () => {
  it('每个输入框/下拉框/多行文本都带 focus:border-[#1677ff]', () => {
    const missing: string[] = []

    for (const relPath of TARGETS) {
      for (const tag of extractFormControls(relPath)) {
        if (!tag.includes('focus:border-[#1677ff]')) {
          missing.push(`${relPath}: ${tag.replace(/\s+/g, ' ').slice(0, 100)}`)
        }
      }
    }

    expect(missing, `以下控件缺少聚焦描边（focus:border-[#1677ff]）：\n${missing.join('\n')}`).toEqual([])
  })

  it('扫描确实覆盖到了内容管理页的控件（防止正则失效导致空跑）', () => {
    const total = TARGETS.reduce((sum, relPath) => sum + extractFormControls(relPath).length, 0)
    expect(total).toBeGreaterThanOrEqual(12)
  })
})
