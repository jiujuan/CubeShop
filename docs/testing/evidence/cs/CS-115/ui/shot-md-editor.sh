#!/usr/bin/env bash
# CS-112/CS-115 markdown 编辑器（md-editor-v3）验收截图
#
# 点击文章列表「编辑」打开弹窗（正文编辑器应显示 markdown 源，而非 HTML textarea + 标签工具条），
# 再点「新增文章」截一张空编辑器（可见工具栏）。
#
# 用法：bash shot-md-editor.sh <编辑弹窗png> <新增弹窗png> [编辑按钮选择器]
set -u
OUT_EDIT="$1"; OUT_CREATE="$2"
EDIT_SEL="${3:-[data-testid=cs-article-edit-3]}"
ADM="${ADM:-http://127.0.0.1:5173}"
USER="${ADM_USER:-admin}"; PASS="${ADM_PASS:-Admin@123}"
FORM="D:/codeproject/PHP/CubeShop/_mdshot.html"
DEC="D:/codeproject/PHP/CubeShop/docs/testing/evidence/cs/CS-117/decode_captcha.py"

agent-browser close >/dev/null 2>&1
timeout 30 agent-browser open "$ADM/login" >/dev/null 2>&1
timeout 30 agent-browser set viewport 1600 1000 >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1
timeout 30 agent-browser get html 'body' > "$FORM" 2>/dev/null
CAP=$(python "$DEC" "$FORM" 2>/dev/null)
timeout 30 agent-browser fill 'input[placeholder="请输入用户名或手机号"]' "$USER" >/dev/null 2>&1
timeout 30 agent-browser fill 'input[placeholder="请输入密码"]' "$PASS" >/dev/null 2>&1
timeout 30 agent-browser fill 'input[placeholder="请输入验证码"]' "$CAP" >/dev/null 2>&1
timeout 30 agent-browser click 'button[type="submit"]' >/dev/null 2>&1
timeout 30 agent-browser wait 4500 >/dev/null 2>&1

# 1) 编辑已有文章：正文编辑器应回显 markdown 源
timeout 30 agent-browser open "$ADM/cs/faq" >/dev/null 2>&1
timeout 30 agent-browser wait 4000 >/dev/null 2>&1
timeout 30 agent-browser find first "$EDIT_SEL" click >/dev/null 2>&1 || timeout 30 agent-browser click "$EDIT_SEL" >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1
timeout 90 agent-browser screenshot "$OUT_EDIT" >/dev/null 2>&1

# 2) 新增文章：空编辑器 + 工具栏
#    先重新打开列表页，避免弹窗还停在上一篇（否则截出来是同一张图 = 重复证据）
timeout 30 agent-browser open "$ADM/cs/faq" >/dev/null 2>&1
timeout 30 agent-browser wait 4000 >/dev/null 2>&1
timeout 30 agent-browser find first '[data-testid=cs-article-create]' click >/dev/null 2>&1 || timeout 30 agent-browser click '[data-testid=cs-article-create]' >/dev/null 2>&1
timeout 30 agent-browser wait 3500 >/dev/null 2>&1
timeout 90 agent-browser screenshot "$OUT_CREATE" >/dev/null 2>&1

timeout 30 agent-browser close >/dev/null 2>&1
rm -f "$FORM"

for f in "$OUT_EDIT" "$OUT_CREATE"; do
  if [ -f "$f" ]; then echo "OK   captcha=$CAP -> $f"; else echo "FAIL captcha=$CAP -> $f"; fi
done

# 证据自查：两张图若字节相同说明第二张没生效（重复证据）
if [ -f "$OUT_EDIT" ] && [ -f "$OUT_CREATE" ]; then
  if [ "$(md5sum < "$OUT_EDIT" | cut -d' ' -f1)" = "$(md5sum < "$OUT_CREATE" | cut -d' ' -f1)" ]; then
    echo "WARN 两张截图字节相同，第二张很可能是重复证据，请重跑"
  else
    echo "OK   两张截图不同（非重复证据）"
  fi
fi
