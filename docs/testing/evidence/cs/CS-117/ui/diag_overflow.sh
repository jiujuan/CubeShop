#!/usr/bin/env bash
# CS-117 缺陷 #2（P1）复现 / 复验脚本：真浏览器下测量 375px 横向溢出
#
# 用法：bash diag_overflow.sh [路径] [标签] [视口WxH]
#   例：bash diag_overflow.sh /service-center after-375 375x812
#       bash diag_overflow.sh /service-center after-1440 1440x900
#
# 前置：web dev server 运行于 [::1]:3000（可用 WEB= 覆盖）；买家账号 uibuyer / Test@1234
# 输出：一行 CAPTCHA=xxxx，一行 JSON（innerW / scrollW / clientW / 越界元素清单）
#
# 说明：脚本自包含（关闭 → 登录 → 导航 → 设视口 → eval 测量），每步带 timeout，
#       规避 agent-browser daemon 状态漂移；登录验证码取自登录页内联 SVG（decode_captcha.py）。
set -u
TARGET="${1:-/service-center}"
LABEL="${2:-measure}"
VP="${3:-375x812}"
W=${VP%x*}; H=${VP#*x}
WEB="${WEB:-http://[::1]:3000}"
ROOT="D:/codeproject/PHP/CubeShop"
FORM_DIR="$ROOT/.workbuddy/tmp"
FORM="$FORM_DIR/diag_form.html"
DEC="$ROOT/docs/testing/evidence/cs/CS-117/decode_captcha.py"
mkdir -p "$FORM_DIR"
JS="(()=>{const w=innerWidth;const bad=[...document.querySelectorAll('body *')].filter(function(e){const r=e.getBoundingClientRect();return r.width>0&&(r.right>w+1||r.left<-1)});return JSON.stringify({label:'$LABEL',path:location.pathname,innerW:w,scrollW:document.documentElement.scrollWidth,clientW:document.documentElement.clientWidth,bodyScrollW:document.body.scrollWidth,overflowCount:bad.length,items:bad.slice(0,14).map(function(e){const r=e.getBoundingClientRect();return e.tagName+'|'+(e.className||'').toString().slice(0,70)+'|'+Math.round(r.left)+'~'+Math.round(r.right)})})})()"

agent-browser close >/dev/null 2>&1
timeout 30 agent-browser open "$WEB/login" >/dev/null 2>&1
timeout 20 agent-browser set viewport "$W" "$H" >/dev/null 2>&1
timeout 20 agent-browser wait 3500 >/dev/null 2>&1
timeout 20 agent-browser get html 'body' > "$FORM" 2>/dev/null
CAP=$(python "$DEC" "$FORM" 2>/dev/null)
timeout 20 agent-browser fill 'input[placeholder="用户名 / 手机号"]' "uibuyer" >/dev/null 2>&1
timeout 20 agent-browser fill 'input[placeholder="密码"]' "Test@1234" >/dev/null 2>&1
timeout 20 agent-browser fill 'input[placeholder="验证码"]' "$CAP" >/dev/null 2>&1
timeout 20 agent-browser find text "登 录" click >/dev/null 2>&1
timeout 20 agent-browser wait 3500 >/dev/null 2>&1
timeout 30 agent-browser open "$WEB$TARGET" >/dev/null 2>&1
timeout 20 agent-browser wait 3000 >/dev/null 2>&1
timeout 20 agent-browser set viewport "$W" "$H" >/dev/null 2>&1
timeout 20 agent-browser wait 1500 >/dev/null 2>&1
echo "CAPTCHA=$CAP"
timeout 25 agent-browser eval "$JS" 2>&1
