/**
 * 地区字典（admin 端消费者）
 *
 * 数据源：由公共地区字典服务分发 —— shared/region-dict/regions.json
 * （执行 `python shared/region-dict/tools/sync.py` 后落地到 src/data/regions.tree.json）
 *
 * 设计要点：
 *  - 动态 import：字典独立 chunk，打开地址表单时才加载，不进主包；
 *  - 进程内缓存：同一会话只解析一次；
 *  - 只读 API：任何"写地区"的需求都应回到字典包改源数据，前端不持有第二份地区数据。
 */
export interface RegionNode {
  code: string
  name: string
  children?: RegionNode[]
}

let treePromise: Promise<RegionNode[]> | null = null

/** 加载完整三级字典（省 → 市 → 区） */
export function loadRegionTree(): Promise<RegionNode[]> {
  if (!treePromise) {
    treePromise = import('@/data/regions.tree.json').then(
      (m) => ((m as { default?: RegionNode[] }).default ?? (m as unknown as RegionNode[])) as RegionNode[],
    )
  }
  return treePromise
}

let provincesPromise: Promise<RegionNode[]> | null = null

/** 轻量省级列表（独立小 chunk，只要省的场景优先用它，勿拉全树） */
export function loadProvinces(): Promise<RegionNode[]> {
  if (!provincesPromise) {
    provincesPromise = import('@/data/provinces.json').then(
      (m) => ((m as { default?: RegionNode[] }).default ?? (m as unknown as RegionNode[])) as RegionNode[],
    )
  }
  return provincesPromise
}

/** 省级列表 */
export async function listProvinces(): Promise<RegionNode[]> {
  return loadProvinces()
}

/** 某省下级市列表 */
export async function listCities(provinceCode: string): Promise<RegionNode[]> {
  if (!provinceCode) return []
  const tree = await loadRegionTree()
  return tree.find((p) => p.code === provinceCode)?.children ?? []
}

/** 某市下辖区县列表 */
export async function listDistricts(provinceCode: string, cityCode: string): Promise<RegionNode[]> {
  if (!provinceCode || !cityCode) return []
  const cities = await listCities(provinceCode)
  return cities.find((c) => c.code === cityCode)?.children ?? []
}

/** code → 名称（三级均可，找不到返回 null） */
export async function nameOfCode(code: string): Promise<string | null> {
  if (!code) return null
  const tree = await loadRegionTree()
  for (const p of tree) {
    if (p.code === code) return p.name
    for (const c of p.children ?? []) {
      if (c.code === code) return c.name
      for (const a of c.children ?? []) {
        if (a.code === code) return a.name
      }
    }
  }
  return null
}

/**
 * 名称 → code（限定层级，避免同名区县跨省误命中；找不到返回 null）
 * province / city 参数为上级 code，用于缩小查找范围。
 */
export async function codeOfName(name: string, parentCodes?: { province?: string; city?: string }): Promise<string | null> {
  if (!name) return null
  const tree = await loadRegionTree()
  const provinces = parentCodes?.province ? tree.filter((p) => p.code === parentCodes.province) : tree

  if (!parentCodes?.province) {
    // 未限定层级：先找省，再找市，最后找区
    const hit = provinces.find((p) => p.name === name)
    if (hit) return hit.code
  }
  for (const p of provinces) {
    for (const c of p.children ?? []) {
      if (parentCodes?.province && !parentCodes?.city && c.name === name) return c.code
      if (parentCodes?.city && c.code === parentCodes.city) {
        const area = (c.children ?? []).find((a) => a.name === name)
        if (area) return area.code
      }
      if (!parentCodes?.city) {
        for (const a of c.children ?? []) {
          if (a.name === name) return a.code
        }
      }
    }
  }
  return null
}

/** 是否为字典内的合法省级名称（含「中国香港/中国澳门/中国台湾」等省级条目） */
export async function isKnownProvinceName(name: string): Promise<boolean> {
  if (!name) return false
  const tree = await loadRegionTree()
  return tree.some((p) => p.name === name)
}
