#!/usr/bin/env bash
# CS-202 证据脚本：工单详情订单快照 —— HTTP 端到端（后台 + 买家端）
#
# 前置：后端已 `php artisan serve`（默认 8000）、开发库 PG `cubeshop`、工单 1 已关联订单。
# 用法：bash curl-CS-202.sh            # 产出 curl-CS-202.md
set -u

API="${API:-http://127.0.0.1:8000/api}"
BACKEND="${BACKEND:-D:/codeproject/PHP/CubeShop/backend}"
TICKET_ID="${TICKET_ID:-1}"
OUT="${OUT:-curl-CS-202.md}"
PHP="${PHP:-php}"
# 本机取证绕过代理：用环境变量而非 `--noproxy *` —— 后者作为变量值展开时
# `*` 会被 shell 通配符扩展成当前目录文件列表，导致 curl 参数错乱。
export no_proxy='*'
export NO_PROXY='*'
CURL="curl -s"

# 从 stdin 的 JSON 里按点号路径取值（data.items.0.title）
jpath () {
  python -c "
import sys, json
d = json.load(sys.stdin)
for k in sys.argv[1].split('.'):
    d = d[int(k)] if isinstance(d, list) else (d.get(k) if isinstance(d, dict) else None)
print('' if d is None else d)
" "$1"
}

pretty () { python -c "import sys,json;print(json.dumps(json.load(sys.stdin),indent=2,ensure_ascii=False))"; }

# 在 $BACKEND 下执行 tinker 并取回标记值
tinker_val () { # $1=php 代码（需 echo 'MARK=...'） $2=标记名
  ( cd "$BACKEND" && $PHP artisan tinker --execute="$1" 2>/dev/null ) | sed -n "s/^$2=//p" | tail -1
}

cd "$(dirname "$0")" || exit 1

echo "== 1. 后台登录（验证码走 debug_code）=="
CAP=$($CURL -X POST "$API/auth/captcha" -H 'Content-Type: application/json' -d '{"target":"admin","type":"login"}')
LOGIN=$($CURL -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d "{\"username\":\"admin\",\"password\":\"Admin@123\",\"captcha_id\":\"$(echo "$CAP" | jpath data.captcha_id)\",\"captcha_code\":\"$(echo "$CAP" | jpath data.debug_code)\"}")
ATOK=$(echo "$LOGIN" | jpath data.token)
echo "   admin token: ${ATOK:0:12}…"

echo "== 2. 买家 token（tinker 铸 Sanctum token，避免依赖买家密码）=="
BTOK=$(tinker_val "
\$uid = (int) DB::table('cs_ticket')->where('id', $TICKET_ID)->value('user_id');
\$u = App\Models\User::find(\$uid);
echo 'BTOK='.\$u->createToken('cs202-evidence')->plainTextToken;
" BTOK)
echo "   buyer token: ${BTOK:0:12}…"

PLAIN_PHONE=$(tinker_val "
\$oid = (int) DB::table('cs_ticket')->where('id', $TICKET_ID)->value('order_id');
\$a = json_decode((string) DB::table('orders')->where('id', \$oid)->value('address_snapshot'), true) ?: [];
echo 'PHONE='.(\$a['contact_phone'] ?? '');
" PHONE)
echo "   订单收货手机（明文，用于泄漏检查）: $PLAIN_PHONE"

echo "== 3. 请求两端详情接口 =="
ADMIN_JSON=$($CURL -H "Authorization: Bearer $ATOK" -H 'Accept: application/json' "$API/admin/cs/tickets/$TICKET_ID")
BUYER_JSON=$($CURL -H "Authorization: Bearer $BTOK" -H 'Accept: application/json' "$API/cs/tickets/$TICKET_ID")

ADMIN_SNAP=$(echo "$ADMIN_JSON" | python -c "import sys,json;print(json.dumps(json.load(sys.stdin)['data']['order_snapshot'],ensure_ascii=False))")
BUYER_SNAP=$(echo "$BUYER_JSON" | python -c "import sys,json;print(json.dumps(json.load(sys.stdin)['data']['order_snapshot'],ensure_ascii=False))")

{
  echo "# CS-202 证据：工单详情订单快照 HTTP 端到端"
  echo
  echo "- 时间：$(date '+%Y-%m-%d %H:%M:%S')"
  echo "- 接口：\`GET /api/admin/cs/tickets/$TICKET_ID\`（后台）· \`GET /api/cs/tickets/$TICKET_ID\`（买家端）"
  echo "- 环境：\`php artisan serve\` :8000 + 开发库 PG \`cubeshop\`"
  echo
  echo "## 1. 断言的结论"
  echo
  echo '| 检查项 | 结果 |'
  echo '|--------|------|'

  check () { # $1=标签 $2=0/1
    if [ "$2" = "1" ]; then echo "| $1 | PASS |"; else echo "| $1 | FAIL |"; fi
  }

  check "后台响应含 order_snapshot 节点" "$(echo "$ADMIN_JSON" | jpath data.order_snapshot.order_no >/dev/null && [ -n "$(echo "$ADMIN_JSON" | jpath data.order_snapshot.order_no)" ] && echo 1 || echo 0)"
  check "后台商品清单非空" "$([ "$(echo "$ADMIN_SNAP" | python -c 'import sys,json;print(len(json.load(sys.stdin)["items"]))')" -gt 0 ] && echo 1 || echo 0)"
  check "后台物流含最新轨迹" "$(echo "$ADMIN_SNAP" | python -c 'import sys,json;d=json.load(sys.stdin);print(1 if d["shipping"] and d["shipping"]["latest_trace"] else 0)')"
  check "后台退款记录非空且含 admin_remark（客服内部字段）" "$(echo "$ADMIN_SNAP" | python -c 'import sys,json;d=json.load(sys.stdin);print(1 if d["refunds"] and "admin_remark" in d["refunds"][0] else 0)')"
  check "后台含跳转参数 jump" "$(echo "$ADMIN_SNAP" | python -c 'import sys,json;print(1 if "jump" in json.load(sys.stdin) else 0)')"
  check "买家端不含 jump" "$(echo "$BUYER_SNAP" | python -c 'import sys,json;print(0 if "jump" in json.load(sys.stdin) else 1)')"
  check "买家端退款不含 admin_remark" "$(echo "$BUYER_SNAP" | python -c 'import sys,json;d=json.load(sys.stdin);print(0 if d["refunds"] and "admin_remark" in d["refunds"][0] else 1)')"
  check "买家端响应正文不含内部备注明文" "$(echo "$BUYER_JSON" | grep -q '客服内部' && echo 0 || echo 1)"
  check "两端快照均不含收货手机明文（$PLAIN_PHONE）" "$( { echo "$ADMIN_SNAP"; echo "$BUYER_SNAP"; } | grep -q "$PLAIN_PHONE" && echo 0 || echo 1)"
  check "旧 order 节点形状兼容（5 键）" "$([ "$(echo "$ADMIN_JSON" | python -c 'import sys,json;print(",".join(sorted(json.load(sys.stdin)["data"]["order"].keys())))')" = "created_at,order_no,pay_amount,product_image,status" ] && echo 1 || echo 0)"
  echo
  echo "## 2. 后台 \`order_snapshot\` 响应全文"
  echo
  echo '```json'
  echo "$ADMIN_SNAP" | pretty
  echo '```'
  echo
  echo "## 3. 买家端 \`order_snapshot\` 响应全文（精简版）"
  echo
  echo '```json'
  echo "$BUYER_SNAP" | pretty
  echo '```'
  echo
  echo "## 4. 后台 \`order\` 旧节点（兼容形状）"
  echo
  echo '```json'
  echo "$ADMIN_JSON" | python -c "import sys,json;print(json.dumps(json.load(sys.stdin)['data']['order'],indent=2,ensure_ascii=False))"
  echo '```'
  echo
  echo "> 买家端 token 由 tinker 现场铸造（\`createToken('cs202-evidence')\`），仅用于本次取证。"
} > "$OUT"

echo "== 4. 产出 $OUT =="
sed -n '1,25p' "$OUT"
