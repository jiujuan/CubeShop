import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const src = readFileSync(resolve(process.cwd(), 'src/layouts/AdminLayout.vue'), 'utf-8')

/** 提取 icons 映射表里所有已注册的图标组件名 */
function extractRegisteredIcons(): Set<string> {
  const start = src.indexOf('const icons: Record<string, unknown> = {')
  const block = src.slice(start)
  const end = block.indexOf('\n}')
  const body = block.slice(0, end)
  const keys = new Set<string>()
  for (const m of body.matchAll(/^\s*([A-Z]\w+),/gm)) keys.add(m[1])
  return keys
}

/** 提取 menuGroups 中所有菜单项声明的 icon 名 */
function extractMenuIcons(): string[] {
  return [...src.matchAll(/icon:\s*'([A-Z]\w+)'/g)].map((m) => m[1])
}

describe('后台侧栏菜单图标注册', () => {
  it('每个菜单项的 icon 都已注册到 icons 映射表（防止图标不显示）', () => {
    const registered = extractRegisteredIcons()
    const menuIcons = extractMenuIcons()
    expect(menuIcons.length).toBeGreaterThan(0)
    const missing = menuIcons.filter((icon) => !registered.has(icon))
    expect(missing, `以下菜单 icon 未注册：${missing.join('、')}`).toEqual([])
  })

  it('营销管理菜单的 Ticket 图标已注册', () => {
    const registered = extractRegisteredIcons()
    expect(registered.has('Ticket')).toBe(true)
  })
})
