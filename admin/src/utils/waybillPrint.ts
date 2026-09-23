import request from '@/api/request'
import { getWaybillChannel, reissueWaybill } from '@/api/order'
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

/**
 * 补出 / 重打电子面单后直接打开打印页。
 *
 * 用于「历史运单 / 无模板运单」——这些点「打印面单」会 40022。本函数：
 *   1. 取当前面单渠道：不可用直接拦截（40022 友好提示）；
 *   2. 真实渠道（快递100 等）重出将产生新单号 / 可能计费 → window.confirm 二次确认；
 *      Mock 演示渠道确定性同号、无费用，仅轻量确认；
 *   3. confirm 之后、仍在用户手势内**先同步开窗**（避免补出请求返回后再开窗被弹窗拦截），
 *      再调 reissueWaybill 写回模板，最后拉取面单 HTML 写入该窗口。
 */
export async function reissueWaybillPrint(shippingId: number): Promise<void> {
  const { show } = useToast()
  const base = import.meta.env.VITE_API_BASE_URL || '/api'

  let label = ''
  let available = false
  try {
    const { data } = await getWaybillChannel()
    label = data.data.label ?? ''
    available = data.data.available
  } catch {
    // 取渠道失败则放行，交由端点判断是否可用
  }

  if (!available) {
    show('当前面单渠道不可用或未配置，无法补出', 'error')
    return
  }

  const isMock = label.includes('Mock') || label.includes('演示') || label.includes('本地')
  const warn = isMock
    ? `将通过「${label}」补出面单（演示渠道，安全无费用），确定继续？`
    : `将通过「${label}」补出电子面单，真实渠道可能产生新单号与计费，确定继续？`
  if (!window.confirm(warn)) {
    return
  }

  // ⚠️ 必须在用户手势内同步开窗；随后的网络请求不在手势内，否则窗口会被拦截
  const win = window.open('', '_blank')
  if (!win) {
    show('弹窗被浏览器拦截，请允许本站弹窗后重试', 'error')
    return
  }
  win.document.write(
    '<html><body style="font-family:sans-serif;color:#888;padding:24px">正在补出面单…</body></html>',
  )

  try {
    await reissueWaybill(shippingId)
    // 补出成功，此时已有模板，拉取并打印页写入同一窗口
    const { data: blob } = await request.get<Blob>(
      `${base}/admin/shippings/${shippingId}/waybill`,
      { responseType: 'blob' },
    )
    const text = await blob.text()
    win.document.open()
    win.document.write(text)
    win.document.close()
  } catch (e: any) {
    win.close()
    const msg = e?.response?.data?.message || '面单补出失败'
    show(msg, 'error')
  }
}
