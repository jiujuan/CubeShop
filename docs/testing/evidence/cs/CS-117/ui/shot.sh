#!/usr/bin/env bash
# CS-117 浏览器通道截图：每次调用自包含（关闭 → 登录 → 导航 → 截图），规避 daemon 状态漂移。
# 用法：bash shot.sh <登录后路径> <输出png> [视口 WxH]
set -u
TARGET="$1"; OUT="$2"; VP="${3:-1440x900}"
W=${VP%x*}; H=${VP#*x}
WEB="${WEB:-http://[::1]:3000}"
FORM="D:/codeproject/PHP/CubeShop/_form.html"
DEC="D:/codeproject/PHP/CubeShop/docs/testing/evidence/cs/CS-117/decode_captcha.py"

agent-browser close >/dev/null 2>&1
timeout 30 agent-browser open "$WEB/login" >/dev/null 2>&1
timeout 30 agent-browser set viewport "$W" "$H" >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1
timeout 30 agent-browser get html 'body' > "$FORM" 2>/dev/null
CAP=$(python "$DEC" "$FORM" 2>/dev/null)
timeout 30 agent-browser fill 'input[placeholder="用户名 / 手机号"]' "uibuyer" >/dev/null 2>&1
timeout 30 agent-browser fill 'input[placeholder="密码"]' "Test@1234" >/dev/null 2>&1
timeout 30 agent-browser fill 'input[placeholder="验证码"]' "$CAP" >/dev/null 2>&1
timeout 30 agent-browser find text "登 录" click >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1

if [ "$TARGET" != ":home" ]; then
  timeout 30 agent-browser open "$WEB$TARGET" >/dev/null 2>&1
  timeout 30 agent-browser wait 3000 >/dev/null 2>&1
fi

timeout 60 agent-browser screenshot "$OUT" >/dev/null 2>&1
if [ -f "${OUT#D:}" ] || [ -f "$OUT" ]; then
  echo "OK  captcha=$CAP -> $OUT"
else
  echo "FAIL captcha=$CAP -> $OUT"
fi
