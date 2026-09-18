#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# SEC-01 / SEC-02 验收脚本
#
# SEC-01  沙箱支付 /payments/sandbox/{paymentNo} 无鉴权无限流，未登录者可将任意
#         支付单置为「支付成功」= 0 元购。
# SEC-02  PAY_SIGN_SECRET 存在硬编码默认值，回调签名可被任意伪造。
#
# 验收分层：
#   A 真实 HTTP（local 服务）：正常链路未受影响 —— 沙箱可用
#   B 路由注册层：APP_ENV=production 的路由表不含沙箱路由（等价线上 404）
#   C 真实 HTTP：伪造回调（历史默认密钥 / 空密钥）一律不入账
#   D 真实 HTTP：正确签名的回调仍可入账（避免误伤真实渠道）
#
# 说明：B 之所以用 `route:list` 而不是再起一个 production 服务，是因为当前本机
#       PHP 8.5 与 vendor 内 Carbon 3.8.4 不兼容（CarbonPeriod::getIterator 声明冲突），
#       任何**新起**的 HTTP 进程都会 500——与本次修复无关。见 docs 验收记录。
#
# 用法：bash docs/testing/security/verify-payment-security.sh [base_url]
#       base_url 默认 http://127.0.0.1:8000（需为可用的 local 服务）
# ---------------------------------------------------------------------------
set -uo pipefail

cd "$(dirname "$0")/../../../backend" || exit 1

BASE="${1:-http://127.0.0.1:8000}"
SECRET="$(grep '^PAY_SIGN_SECRET=' .env | cut -d'=' -f2)"

PASS=0
FAIL=0
pass() { echo "  [PASS] $1"; PASS=$((PASS + 1)); }
fail() { echo "  [FAIL] $1"; FAIL=$((FAIL + 1)); }
tinker() { php artisan tinker --execute="$1" 2>/dev/null | tail -1 | tr -d '\r'; }
field() { grep -o "\"$1\": *\"[^\"]*\"" | head -1 | cut -d'"' -f4; }

echo "== 目标服务：$BASE =="
if [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/health")" != "200" ]; then
    echo "  [SKIP] 服务不可用。请先在 PHP ≤8.4 或修复 Carbon 兼容后启动：php artisan serve --port=8000"
    exit 2
fi

echo "== 准备数据 =="
TOKEN="$(tinker "\$u = App\Models\User::first(); echo \$u->createToken('sec-verify')->plainTextToken;")"
ADDR="$(tinker "\$u = App\Models\User::first(); echo App\Models\UserAddress::where('user_id', \$u->id)->value('id');")"
CANDIDATES="$(tinker "echo App\Models\Inventory::where('stock', '>', 5)->pluck('sku_id')->take(8)->implode(' ');")"
if [ -z "$TOKEN" ] || [ -z "$ADDR" ] || [ -z "$CANDIDATES" ]; then
    echo "  [ERROR] 开发库缺少基础数据（用户/地址/库存），请先执行 php artisan migrate --seed"
    exit 1
fi

AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json')

# 逐个试加购，取第一个可下单的 SKU（规避下架商品/属性缺失等干扰）
SKU=""
for s in $CANDIDATES; do
    R=$(curl -s -X POST "$BASE/api/cart" "${AUTH[@]}" -d "{\"sku_id\":$s,\"quantity\":1}")
    if echo "$R" | grep -q '"code": *0'; then
        SKU="$s"
        break
    fi
done
if [ -z "$SKU" ]; then
    echo "  [ERROR] 没有可加入购物车的 SKU（候选：$CANDIDATES）"
    exit 1
fi
echo "  sku=$SKU address=$ADDR"
new_order() {
    curl -s -X POST "$BASE/api/cart" "${AUTH[@]}" -d "{\"sku_id\":$SKU,\"quantity\":1}" >/dev/null
    curl -s -X POST "$BASE/api/orders" "${AUTH[@]}" -d "{\"address_id\":$ADDR}"
}
pay_for() { curl -s -X POST "$BASE/api/payments" "${AUTH[@]}" -d "{\"order_no\":\"$1\",\"channel\":\"mock\"}"; }
order_status() { tinker "echo App\Models\Order::where('order_no', '$1')->value('status');"; }

# ---------------------------------------------------------------------------
echo
echo "== A. 正常链路未受影响（回归保护） =="
O1=$(new_order | field order_no)
P1=$(pay_for "$O1" | field payment_no)
echo "  order=$O1 payment=$P1"
if [ -z "$O1" ] || [ -z "$P1" ]; then
    echo "  [ERROR] 下单或发起支付失败，无法继续"
    exit 1
fi
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/payments/sandbox/$P1" -H 'Accept: application/json')
ST=$(order_status "$O1")
[ "$CODE" = "200" ] && [ "$ST" = "pending_ship" ] \
    && pass "沙箱支付成功，订单转 pending_ship（HTTP $CODE）" \
    || fail "沙箱链路被破坏（HTTP $CODE, status=$ST）"

# ---------------------------------------------------------------------------
echo
echo "== B. SEC-01：生产环境路由表不含沙箱路由 =="
if APP_ENV=production php artisan route:list 2>/dev/null | grep -qi "payments/sandbox"; then
    fail "生产环境仍注册了 /api/payments/sandbox（0 元购入口未关闭）"
else
    pass "生产路由表无 payments/sandbox ⇒ 线上访问返回 404"
fi
if APP_ENV=local php artisan route:list 2>/dev/null | grep -qi "payments/sandbox"; then
    pass "local 环境仍保留沙箱路由（开发联调不受影响）"
else
    fail "local 环境沙箱路由丢失，开发联调会被阻断"
fi

# ---------------------------------------------------------------------------
echo
echo "== C. SEC-02：伪造回调不入账 =="
O2=$(new_order | field order_no)
P2=$(pay_for "$O2" | field payment_no)
AMT=$(tinker "echo App\Models\Payment::where('payment_no', '$P2')->value('amount');")
echo "  order=$O2 payment=$P2 amount=$AMT"

callback() { # $1=trade_no $2=sign
    # 注意：Windows 原生 curl 写不了 Git Bash 的 /tmp 路径，改用管道直接解析响应
    curl -s -X POST "$BASE/api/payments/callback/mock" \
        -H 'Content-Type: application/json' -H 'Accept: application/json' \
        -d "{\"payment_no\":\"$P2\",\"channel_trade_no\":\"$1\",\"amount\":\"$AMT\",\"status\":\"success\",\"sign\":\"$2\"}" \
        | grep -o '"ok": *[a-z]*' | head -1 | tr -d ' '
}

LEGACY=$(php -r "echo hash_hmac('sha256', implode('|', ['$P2','HACK-LEGACY','$AMT','success']), 'cubeshop-sandbox-secret');")
OK=$(callback "HACK-LEGACY" "$LEGACY")
ST=$(order_status "$O2")
[ "$OK" = '"ok":false' ] && [ "$ST" = "pending_payment" ] \
    && pass "历史默认密钥签名的回调被拒绝（$OK），订单未入账" \
    || fail "默认密钥签名仍可入账（$OK, status=$ST）—— SEC-02 未修复"

BLIND=$(php -r "echo hash_hmac('sha256', implode('|', ['$P2','HACK-EMPTY','$AMT','success']), '');")
OK=$(callback "HACK-EMPTY" "$BLIND")
ST=$(order_status "$O2")
[ "$OK" = '"ok":false' ] && [ "$ST" = "pending_payment" ] \
    && pass "空密钥签名的回调被拒绝（验签 fail-closed）" \
    || fail "空密钥签名仍可入账（$OK, status=$ST）"

# ---------------------------------------------------------------------------
echo
echo "== D. SEC-02：正确签名仍可入账（避免误伤真实渠道） =="
GOOD=$(php -r "echo hash_hmac('sha256', implode('|', ['$P2','SEC-VERIFY-1','$AMT','success']), '$SECRET');")
OK=$(callback "SEC-VERIFY-1" "$GOOD")
ST=$(order_status "$O2")
[ "$OK" = '"ok":true' ] && [ "$ST" = "pending_ship" ] \
    && pass "正确签名回调正常入账（$OK），订单转 pending_ship" \
    || fail "正确签名回调被误杀（$OK, status=$ST）—— 修复过度，真实渠道会受影响"

# ---------------------------------------------------------------------------
echo
echo "================ 汇总 ================"
echo "  PASS: $PASS   FAIL: $FAIL"
[ "$FAIL" -eq 0 ] && echo "  结论：SEC-01 / SEC-02 修复通过验收" || echo "  结论：存在失败项，请检查上方 [FAIL]"
exit "$FAIL"
