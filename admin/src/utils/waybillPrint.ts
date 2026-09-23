import request from '@/api/request'
import { useToast } from '@/composables/useToast'

/**
 * 打开电子面单打印页（新标签页）。
 *
 * 后台 SPA 用 Bearer Token 认证，直接 window.open(endpointUrl) 发起的导航
 * 不会携带 Token（会被 401 拦截），因此这里先经已认证的 request 拉取 HTML，
 * 再写入一个新标签页：
 *   1. 同步 `window.open('', '_blank')` 拿到句柄 —— 必须在用户手势调用栈内，
 *      否则会被浏览器弹窗拦截；
 *   2. 拿到 HTML 后 `document.write` 进该标签页（同源，打印按钮可用）。
 *
 * 端点成功返回 text/html；失败（如运单无面单模板 40022）返回 JSON，
 * 此时关闭空白页并 toast 提示，而非打开一个错误页。
 */
export async function openWaybillPrint(shippingId: number): Promise<void> {
  const { show } = useToast()
  const base = import.meta.env.VITE_API_BASE_URL || '/api'

  const win = window.open('', '_blank')
  if (!win) {
    show('弹窗被浏览器拦截，请允许本站弹窗后重试', 'error')
    return
  }
  win.document.write(
    '<html><body style="font-family:sans-serif;color:#888;padding:24px">正在加载面单…</body></html>',
  )

  try {
    const { data: blob } = await request.get<Blob>(
      `${base}/admin/shippings/${shippingId}/waybill`,
      { responseType: 'blob' },
    )

    const text = await blob.text()
    const isHtml = blob.type ? blob.type.startsWith('text/html') : !text.trimStart().startsWith('{')

    if (!isHtml) {
      win.close()
      let msg = '该运单暂无面单模板，无法打印'
      try {
        const json = JSON.parse(text)
        if (json && json.message) msg = json.message
      } catch {
        // 非 JSON：保留默认提示
      }
      show(msg, 'error')
      return
    }

    win.document.open()
    win.document.write(text)
    win.document.close()
  } catch {
    win.close()
    show('面单加载失败，请稍后重试', 'error')
  }
}
