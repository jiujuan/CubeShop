import { defineStore } from 'pinia'
import { ref } from 'vue'
import { addToCart, getCartCount } from '@/api/user'
import { useAuthStore } from '@/stores/auth'

/**
 * 购物车角标数量的单一真源。
 *
 * 背景（2026-09-19 修复）：角标原先由 `ShopHeader` 内部 `cartCount` ref 持有，只有
 * `DetailView` 通过模板 ref 手动调 `refreshCartCount()`，因此
 *  - 购物车页删除 / 改数量后角标不动；
 *  - 列表页 / 首页 `ProductCard` 加购后角标不动。
 * 改为集中式 store 后，所有变更点只需调 store 方法，顶栏自动同步。
 *
 * 口径：一律以「回源」为准（`GET /cart/count` 只统计「商品上架 + SKU 启用」项），
 * 前端不自算，避免与后端口径漂移（例如「库存不足」项后端仍计入、前端 `valid=false`）。
 */
export const useCartStore = defineStore('web-cart', () => {
  const count = ref(0)

  /** 回源刷新角标；未登录直接置 0（接口需鉴权） */
  async function refresh() {
    const auth = useAuthStore()
    if (!auth.token) {
      count.value = 0
      return
    }
    try {
      const { data } = await getCartCount()
      count.value = data.data.count
    } catch {
      // 拉取失败不清零：避免网络抖动把角标误清，保留上一次值
    }
  }

  /** 加购并刷新角标（供商品卡 / 详情页复用） */
  async function add(skuId: string, quantity = 1) {
    await addToCart(skuId, quantity)
    await refresh()
  }

  /** 登出 / token 失效时清零 */
  function reset() {
    count.value = 0
  }

  return { count, refresh, add, reset }
})
