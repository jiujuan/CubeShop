import request from './request'
import type { ApiResult } from './types'

/**
 * 站点基础信息（P-SiteConfig，公开接口，无需登录）
 *
 * 后台「系统设置 → 站点信息」可修改站点名称与大小 logo；
 * 保存时后端会失效配置缓存，故前台刷新即可见。
 */
export interface SiteInfo {
  /** 电商站点名称（后台未设置时后端回落 CubeShop） */
  name: string
  /** 大 logo 图片地址（桌面端顶栏）；留空串表示未设置 */
  logo: string
  /** 小 logo 图片地址（移动端顶栏）；留空串表示未设置 */
  logo_small: string
}

export function getSiteConfig() {
  return request.get<ApiResult<SiteInfo>>('/site/config')
}
