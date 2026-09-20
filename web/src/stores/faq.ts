import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { getFaqCategories, type FaqCategory } from '@/api/cs'

/** 扁平化后的栏目节点（带缩进深度，供侧栏渲染） */
export interface FlatFaqCategory {
  node: FaqCategory
  depth: number
}

/**
 * 帮助中心栏目树的单一真源（CMS-201）
 *
 * 为什么收敛到 store 而不是各视图各自请求：
 * - 分类页、列表页侧栏、详情页面包屑三处都要这棵树，各自请求会**出现三份可能不一致的数据**
 *   （后台刚改了栏目，切页时才刷新，面包屑和侧栏对不上）；
 * - 栏目树是低频变更的公共数据，缓存一次即可，体验上也不需要每次进页面都闪一下。
 *
 * 与 `stores/cart.ts` 同一体例；口径一律以「回源」为准，前端不自造层级。
 */
export const useFaqStore = defineStore('web-faq', () => {
  const tree = ref<FaqCategory[]>([])
  const loading = ref(false)

  /** 是否已成功加载过（失败不算，避免把空树当成有效缓存） */
  const loaded = ref(false)

  /** 拉取栏目树；已加载过则直接复用（`force` 可强制回源） */
  async function loadTree(force = false) {
    if (loaded.value && !force) return
    if (loading.value) return

    loading.value = true
    try {
      const { data } = await getFaqCategories()
      tree.value = data.data ?? []
      loaded.value = true
    } catch {
      // 拉取失败不写入缓存：下次仍会重试，也不会把界面停在"空树"这个假状态
    } finally {
      loading.value = false
    }
  }

  /** 深度优先展平（保留层级顺序与深度，侧栏直接用） */
  const flat = computed<FlatFaqCategory[]>(() => {
    const rows: FlatFaqCategory[] = []
    const walk = (nodes: FaqCategory[], depth: number) => {
      for (const node of nodes) {
        rows.push({ node, depth })
        if (node.children?.length) walk(node.children, depth + 1)
      }
    }
    walk(tree.value, 0)

    return rows
  })

  /** 按 id 找栏目（含子栏目） */
  function findById(id: number | null | undefined): FaqCategory | null {
    if (id === null || id === undefined) return null

    return flat.value.find((row) => row.node.id === id)?.node ?? null
  }

  /**
   * 面包屑链路：从根到该栏目
   *
   * 树已在手，链路本地算即可 —— 为此加一个后端接口属于多余往返，
   * 且两级数据可能不一致。找不到时返回空数组（调用方只渲染上层面包屑）。
   */
  function breadcrumbOf(id: number | null | undefined): FaqCategory[] {
    if (id === null || id === undefined) return []

    const walk = (nodes: FaqCategory[], trail: FaqCategory[]): FaqCategory[] | null => {
      for (const node of nodes) {
        const next = [...trail, node]
        if (node.id === id) return next
        const hit = node.children?.length ? walk(node.children, next) : null
        if (hit) return hit
      }
      return null
    }

    return walk(tree.value, []) ?? []
  }

  /** 当前栏目的兄弟（含自身），供详情页做同级跳转 */
  function siblingsOf(id: number | null | undefined): FaqCategory[] {
    const node = findById(id)
    if (!node) return []

    return node.parent_id === 0
      ? tree.value
      : (breadcrumbOf(node.parent_id).at(-1)?.children ?? [])
  }

  return { tree, flat, loading, loaded, loadTree, findById, breadcrumbOf, siblingsOf }
})
