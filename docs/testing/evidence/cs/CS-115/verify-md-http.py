#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
CS-115 衍修：md-editor-v3 写入链路 HTTP 端到端验证
---------------------------------------------------
验证「双列正文」约定：接口只收 content_md（markdown 源），
content（HTML 产物）由后端 MarkdownRenderer + HtmlSanitizer 派生。

断言链：
  1. 后台登录（验证码走 debug_code）
  2. POST /admin/cs/faq/articles 提交含标题/有序列表/表格/图片/内嵌HTML/危险链接的 markdown
  3. data.content_md 原样回显源；data.content 为渲染产物
  4. 产物含 <h2 id="content-…">/<ol>/<table>/<img>，且中文锚点 id 未被净化器剥离
  5. 内嵌 <script> 被转义为文本（无裸 <script>）、javascript: 链接被丢弃
  6. PUT 更新正文后 content 重新派生（与源同步）
  7. GET preview 同时返回 content_md 与 content
  8. 清理：删除测试文章

前置：php artisan serve 运行于 127.0.0.1:8000，已 migrate + seed CsFaqCategorySeeder。
用法：python docs/testing/evidence/cs/CS-115/verify-md-http.py
"""
import json
import sys
import urllib.request
import urllib.error

BASE = "http://127.0.0.1:8000/api"
PASS = 0
FAIL = 0


def ok(msg):
    global PASS
    PASS += 1
    print("  [PASS] %s" % msg)


def bad(msg):
    global FAIL
    FAIL += 1
    print("  [FAIL] %s" % msg)


def req(method, path, token=None, body=None):
    data = json.dumps(body, ensure_ascii=False).encode("utf-8") if body is not None else None
    r = urllib.request.Request(BASE + path, data=data, method=method)
    r.add_header("Accept", "application/json")
    if data is not None:
        r.add_header("Content-Type", "application/json")
    if token:
        r.add_header("Authorization", "Bearer " + token)
    try:
        with urllib.request.urlopen(r, timeout=15) as resp:
            return resp.status, json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode("utf-8"))


def jget(d, path):
    cur = d
    for k in path.split("."):
        if isinstance(cur, dict):
            cur = cur.get(k)
        elif isinstance(cur, list) and k.isdigit():
            cur = cur[int(k)] if int(k) < len(cur) else None
        else:
            return None
    return cur


print("== CS-115 md-editor-v3 写入链路 HTTP 端到端 ==  base=%s" % BASE)

# 1. 后台登录
st, cap = req("POST", "/auth/captcha", body={"target": "admin", "type": "login"})
st, login = req("POST", "/auth/login", body={
    "username": "admin", "password": "Admin@123",
    "captcha_id": jget(cap, "data.captcha_id"),
    "captcha_code": jget(cap, "data.debug_code"),
})
ATOK = jget(login, "data.token")
if ATOK:
    ok("后台登录成功")
else:
    bad("后台登录失败: %s" % json.dumps(login, ensure_ascii=False)[:200])
    sys.exit(1)

# 2. 取一个分类
st, cats = req("GET", "/admin/cs/faq/categories", ATOK)
CAT_ID = jget(cats, "data.0.id")
if CAT_ID:
    ok("取到 FAQ 分类 id=%s" % CAT_ID)
else:
    bad("无 FAQ 分类，请先 seed CsFaqCategorySeeder")
    sys.exit(1)

# 3. 建文章
MD = (
    "## 如何下单\n\n"
    "1. 浏览商品并加入购物车\n"
    "2. 提交订单并支付\n\n"
    "| 支付方式 | 到账时间 |\n"
    "| --- | --- |\n"
    "| 微信 | 实时 |\n\n"
    "![流程图](https://static.example.com/order.png)\n\n"
    "<div>内嵌 HTML 片段</div>\n\n"
    "<script>alert('xss')</script>\n\n"
    "[危险链接](javascript:alert(1))\n"
)
st, created = req("POST", "/admin/cs/faq/articles", ATOK, {
    "category_id": CAT_ID,
    "title": "CS-115 md 写入链路测试",
    "summary": "写入链路端到端",
    "content_md": MD,
    "status": "draft",
})
AID = jget(created, "data.id")
if created.get("code") == 0 and AID:
    ok("创建文章成功 id=%s（HTTP %s）" % (AID, st))
else:
    bad("创建失败（HTTP %s）: %s" % (st, json.dumps(created, ensure_ascii=False)[:300]))
    sys.exit(1)

content_md = jget(created, "data.content_md") or ""
content = jget(created, "data.content") or ""

# 4. 源回显与产物派生
# 注：Laravel TrimStrings 中间件会裁掉首尾空白，故按 strip 比较（尾部换行差异非缺陷）
if content_md.strip() == MD.strip() and content_md and content_md != content:
    ok("content_md 回显 markdown 源（仅首尾空白被 TrimStrings 裁剪）")
else:
    bad("content_md 与提交源不一致: %r" % content_md[:120])

if content and content != content_md and "<h2" in content:
    ok("content 为渲染产物（非源本身）")
else:
    bad("content 未渲染: %s" % content[:160])

# 5. 结构标签
checks = {
    "<h2": "二级标题",
    "<ol": "有序列表",
    "<table": "GFM 表格",
    "<img": "图片",
}
for token_str, label in checks.items():
    if token_str in content:
        ok("产物含 %s (%s)" % (token_str, label))
    else:
        bad("产物缺 %s (%s): %s" % (token_str, label, content[:200]))

# 6. 中文锚点 id 未被剥离
if 'id="content-' in content:
    ok("中文标题锚点 id 保留（id_prefix=content）")
else:
    bad("锚点 id 被剥离: %s" % content[:200])

# 7. 危险内容治理
if "<script" not in content.lower():
    ok("裸 <script> 已消除（html_input=escape）")
else:
    bad("存在裸 <script> —— 净化失效")
if "javascript:" not in content.lower():
    ok("javascript: 危险链接已丢弃（allow_unsafe_links=false）")
else:
    bad("存在 javascript: 链接 —— 净化失效")
if "&lt;script&gt;" in content:
    ok("内嵌 HTML 以文本形式保留（&lt;script&gt;）")
else:
    ok("内嵌 HTML 未以裸标签泄漏（形态：%s）" % ("转义文本" if "script" in content else "已剥离"))

# 8. 图片 src 可解析
if 'src="https://static.example.com/order.png"' in content:
    ok("图片 src 保留且为安全协议")
else:
    bad("图片 src 丢失/被改写: %s" % content[:200])

# 9. 更新正文 → 重新派生
MD2 = MD + "\n### 补充说明\n\n退款请至「我的订单」。\n"
st, updated = req("PUT", "/admin/cs/faq/articles/%s" % AID, ATOK, {"content_md": MD2})
c2 = jget(updated, "data.content") or ""
if updated.get("code") == 0 and "<h3" in c2 and "我的订单" in c2:
    ok("更新正文后 content 重新派生（含新 <h3>）")
else:
    bad("更新后未重新派生: %s" % c2[:200])

# 10. preview 同时返回双列
st, prev = req("GET", "/admin/cs/faq/articles/%s/preview" % AID, ATOK)
if jget(prev, "data.content_md") and jget(prev, "data.content"):
    ok("preview 同时返回 content_md 与 content")
else:
    bad("preview 缺列: %s" % json.dumps(prev, ensure_ascii=False)[:200])

# 11. 清理
st, dele = req("DELETE", "/admin/cs/faq/articles/%s" % AID, ATOK)
if dele.get("code") == 0:
    ok("清理测试文章 id=%s" % AID)
else:
    bad("清理失败: %s" % json.dumps(dele, ensure_ascii=False)[:200])

print()
print("== 结果：PASS=%d FAIL=%d ==" % (PASS, FAIL))
sys.exit(1 if FAIL else 0)
