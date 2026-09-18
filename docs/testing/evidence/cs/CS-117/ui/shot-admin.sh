#!/usr/bin/env bash
# CS-114/115 后台（admin）浏览器通道截图：登录 → 导航 → 截图（自包含）。
# 用法：bash shot-admin.sh <后台路径> <输出png> [视口 WxH] [点击选择器]
# 可用 ADM_USER / ADM_PASS 覆盖登录账号（默认超管，例：ADM_USER=kefu01 ADM_PASS=Kefu@1234 截客服视角）
set -u
TARGET="$1"; OUT="$2"; VP="${3:-1600x1000}"; CLICK="${4:-}"
W=${VP%x*}; H=${VP#*x}
ADM="${ADM:-http://127.0.0.1:5173}"
USER="${ADM_USER:-admin}"; PASS="${ADM_PASS:-Admin@123}"
FORM="D:/codeproject/PHP/CubeShop/_admin.html"
DEC="D:/codeproject/PHP/CubeShop/docs/testing/evidence/cs/CS-117/decode_captcha.py"

agent-browser close >/dev/null 2>&1
timeout 30 agent-browser open "$ADM/login" >/dev/null 2>&1
timeout 30 agent-browser set viewport "$W" "$H" >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1
timeout 30 agent-browser get html 'body' > "$FORM" 2>/dev/null
CAP=$(python "$DEC" "$FORM" 2>/dev/null)
timeout 30 agent-browser fill 'input[placeholder="请输入用户名或手机号"]' "$USER" >/dev/null 2>&1
timeout 30 agent-browser fill 'input[placeholder="请输入密码"]' "$PASS" >/dev/null 2>&1
timeout 30 agent-browser fill 'input[placeholder="请输入验证码"]' "$CAP" >/dev/null 2>&1
timeout 30 agent-browser click 'button[type="submit"]' >/dev/null 2>&1
timeout 30 agent-browser wait 4000 >/dev/null 2>&1

timeout 30 agent-browser open "$ADM$TARGET" >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1

if [ -n "$CLICK" ]; then
  timeout 30 agent-browser find first "$CLICK" click >/dev/null 2>&1 || timeout 30 agent-browser click "$CLICK" >/dev/null 2>&1
  timeout 30 agent-browser wait 2500 >/dev/null 2>&1
fi

timeout 60 agent-browser screenshot "$OUT" >/dev/null 2>&1

if [ -f "$OUT" ]; then echo "OK  captcha=$CAP -> $OUT"; else echo "FAIL captcha=$CAP -> $OUT"; fi
