/**
 * 将字符串形式的对外标识（如 P2-11 后的 ULID public_id）稳定映射为 [0, mod) 的整数下标。
 * 替代 `id % mod`——当 id 为字符串时取模会得到 NaN，导致按 id 取模的展示（如商品 emoji 兜底）失效。
 */
export function hashIndex(id: string | number, mod: number): number {
  const s = String(id)
  let h = 0
  for (let i = 0; i < s.length; i++) {
    h = (h * 31 + s.charCodeAt(i)) >>> 0
  }
  return mod > 0 ? h % mod : 0
}
