#!/usr/bin/env bash
# =============================================================
# CubeShop 冒烟测试（TestPlan v1.0 §5）
# 前置：artisan serve 运行于 127.0.0.1:8000，数据库已迁移
# 用法：bash docs/testing/smoke_test.sh
# =============================================================
set -u
BASE="http://127.0.0.1:8000/api"
PASS=0; FAIL=0

ok()   { echo "  ✓ $1"; PASS=$((PASS+1)); }
bad()  { echo "  ✗ $1"; FAIL=$((FAIL+1)); }
j()    { python -c "import sys,json;print(eval(\"sys.stdin.read()\" and json.load(sys.stdin).get('$1')))" 2>/dev/null; }
jpath(){ python -c "
import sys,json
d=json.load(sys.stdin)
cur=d
for k in '$1'.split('.'):
    if isinstance(cur,list): cur=cur[int(k)]
    else: cur=(cur or {}).get(k)
print(cur if cur is not None else '')" 2>/dev/null; }

req() { # method path token data
  local m=$1 p=$2 t=$3 d=${4:-}
  if [ -n "$t" ]; then
    if [ -n "$d" ]; then curl -s --noproxy '*' -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Content-Type: application/json' -d "$d"
    else curl -s --noproxy '*' -X "$m" "$BASE$p" -H "Authorization: Bearer $t"; fi
  else
    curl -s --noproxy '*' -X "$m" "$BASE$p" -H 'Content-Type: application/json' -d "$d"
  fi
}

captcha_pair() { # target type
  local cap; cap=$(req POST /auth/captcha '' "{\"target\":\"$1\",\"type\":\"$2\"}")
  CAP_ID=$(echo "$cap" | jpath data.captcha_id)
  CAP_CODE=$(echo "$cap" | jpath data.debug_code)
}

echo "== CubeShop 冒烟测试 =="
echo "--- 1. 健康检查"
H=$(req GET /health '' '')
[ "$(echo "$H" | jpath data.status)" = "ok" ] && ok "health ok" || bad "health: $H"

echo "--- 2. 注册 → 登录"
U="smoke$(date +%s)"
captcha_pair "$U" register
TOKEN=$(req POST /auth/register '' "{\"username\":\"$U\",\"password\":\"Test@1234\",\"password_confirmation\":\"Test@1234\",\"code\":\"$CAP_CODE\",\"captcha_id\":\"$CAP_ID\"}" | jpath data.token)
[ -n "$TOKEN" ] && ok "注册成功" || { bad "注册失败"; exit 1; }

captcha_pair "$U" login
LTOKEN=$(req POST /auth/login '' "{\"username\":\"$U\",\"password\":\"Test@1234\",\"captcha_id\":\"$CAP_ID\",\"captcha_code\":\"$CAP_CODE\"}" | jpath data.token)
[ -n "$LTOKEN" ] && ok "登录成功" || { bad "登录失败"; exit 1; }
TOKEN="$LTOKEN"

echo "--- 3. 商品浏览"
LIST=$(req GET "/products?page=1&page_size=1" '' '')
[ "$(echo "$LIST" | jpath code)" = "0" ] && ok "商品列表" || bad "商品列表: $(echo "$LIST" | head -c 120)"
FIRST=$(echo "$LIST" | jpath data.list.0.id)
if [ -n "$FIRST" ]; then
  DETAIL=$(req GET "/products/$FIRST" '' '')
  [ "$(echo "$DETAIL" | jpath code)" = "0" ] && ok "商品详情(id=$FIRST)" || bad "商品详情"
else
  echo "  (无种子商品，跳过详情)"
fi

echo "--- 4. 管理员建测试商品 → 加购 → 下单 → 支付"
captcha_pair admin login
ATOK=$(req POST /auth/login '' "{\"username\":\"admin\",\"password\":\"Admin@123\",\"captcha_id\":\"$CAP_ID\",\"captcha_code\":\"$CAP_CODE\"}" | jpath data.token)
[ -n "$ATOK" ] && ok "管理员登录" || { bad "管理员登录失败"; exit 1; }

SKU_CODE="SMOKE-$(date +%s)"
PID=$(req POST /admin/products "$ATOK" "{\"category_id\":1,\"title\":\"冒烟测试商品\",\"status\":1,\"skus\":[{\"sku_code\":\"$SKU_CODE\",\"specs\":{\"规格\":\"标准\"},\"price\":12.5,\"stock\":5}]}" | jpath data.id)
if [ -z "$PID" ]; then bad "创建商品失败"; exit 1; fi
ok "创建商品(id=$PID)"
SKU_ID=$(req GET "/admin/products/$PID" "$ATOK" '' | jpath data.skus.0.id)

ADDR=$(req POST /user/addresses "$TOKEN" '{"contact_name":"冒烟","contact_phone":"13800001234","province":"广东省","city":"深圳市","district":"南山区","detail_address":"冒烟路1号"}' | jpath data.id)
[ -n "$ADDR" ] && ok "新增地址" || { bad "新增地址失败"; exit 1; }

ADD=$(req POST /cart "$TOKEN" "{\"sku_id\":$SKU_ID,\"quantity\":2}")
[ "$(echo "$ADD" | jpath code)" = "0" ] && ok "加入购物车" || bad "加购: $(echo "$ADD" | head -c 120)"

ORDER=$(req POST /orders "$TOKEN" "{\"address_id\":$ADDR,\"remark\":\"冒烟\"}")
ORDER_ID=$(echo "$ORDER" | jpath data.order_id)
ORDER_NO=$(echo "$ORDER" | jpath data.order_no)
[ -n "$ORDER_ID" ] && ok "下单成功(no=$ORDER_NO)" || { bad "下单: $(echo "$ORDER" | head -c 120)"; exit 1; }

PAY=$(req POST /payments "$TOKEN" "{\"order_no\":\"$ORDER_NO\",\"channel\":\"wechat\"}")
PAY_NO=$(echo "$PAY" | jpath data.payment_no)
[ -z "$PAY_NO" ] && PAY_NO=$(echo "$PAY" | jpath data.pay_params.payment_no)
[ -n "$PAY_NO" ] && ok "发起支付(no=$PAY_NO)" || { bad "发起支付: $(echo "$PAY" | head -c 120)"; exit 1; }

SANDBOX=$(req POST "/payments/sandbox/$PAY_NO" "$TOKEN" '')
[ "$(echo "$SANDBOX" | jpath code)" = "0" ] && ok "沙箱支付成功" || bad "沙箱支付: $(echo "$SANDBOX" | head -c 120)"

STATUS=$(req GET "/orders/$ORDER_ID" "$TOKEN" '' | jpath data.status)
[ "$STATUS" = "paid" ] && ok "订单状态 paid" || bad "订单状态: $STATUS"

echo "--- 5. 管理端发货"
SHIP=$(req POST "/admin/orders/$ORDER_ID/ship" "$ATOK" '{"company":"顺丰","tracking_no":"SMOKE001"}')
[ "$(echo "$SHIP" | jpath code)" = "0" ] && ok "发货成功" || bad "发货: $(echo "$SHIP" | head -c 120)"

echo "--- 6. 退出登录"
OUT=$(req POST /auth/logout "$TOKEN" '')
[ "$(echo "$OUT" | jpath code)" = "0" ] && ok "退出登录" || bad "退出: $(echo "$OUT" | head -c 80)"

echo
echo "=== 冒烟结果：PASS $PASS / FAIL $FAIL ==="
[ "$FAIL" = "0" ]
