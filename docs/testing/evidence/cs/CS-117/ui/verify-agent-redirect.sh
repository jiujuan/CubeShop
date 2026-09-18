#!/usr/bin/env bash
# 验证客服角色登录落地与 /dashboard 守卫回退（CS-117 缺陷 #4 / P2）。
# 截图看不到 URL，故本脚本用 eval 取 location.pathname 作为可证明证据。
# 用法：ADM_USER=kefu01 ADM_PASS=Kefu@1234 bash verify-agent-redirect.sh
set -u
ADM="${ADM:-http://127.0.0.1:5173}"
USER="${ADM_USER:-kefu01}"; PASS="${ADM_PASS:-Kefu@1234}"
FORM="D:/codeproject/PHP/CubeShop/_verify.html"
DEC="D:/codeproject/PHP/CubeShop/docs/testing/evidence/cs/CS-117/decode_captcha.py"

echo "== 账号: $USER =="

agent-browser close >/dev/null 2>&1
timeout 30 agent-browser open "$ADM/login" >/dev/null 2>&1
timeout 30 agent-browser set viewport 1600 1000 >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1
timeout 30 agent-browser get html 'body' > "$FORM" 2>/dev/null
CAP=$(python "$DEC" "$FORM" 2>/dev/null)
echo "captcha=$CAP"
timeout 30 agent-browser fill 'input[placeholder="请输入用户名或手机号"]' "$USER" >/dev/null 2>&1
timeout 30 agent-browser fill 'input[placeholder="请输入密码"]' "$PASS" >/dev/null 2>&1
timeout 30 agent-browser fill 'input[placeholder="请输入验证码"]' "$CAP" >/dev/null 2>&1
timeout 30 agent-browser click 'button[type="submit"]' >/dev/null 2>&1
timeout 30 agent-browser wait 4500 >/dev/null 2>&1

echo -n "登录后 pathname : "
timeout 30 agent-browser eval "location.pathname" 2>&1

echo -n "直接访问 /dashboard 后 pathname : "
timeout 30 agent-browser open "$ADM/dashboard" >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1
timeout 30 agent-browser eval "location.pathname" 2>&1

echo -n "侧栏是否出现「工作台」入口 : "
timeout 30 agent-browser eval "document.body.innerText.includes('工作台')" 2>&1

timeout 30 agent-browser close >/dev/null 2>&1
echo "== done =="
