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
ORDER_ID=$(echo "$ORDER" | jpath data.order_id)   # 对外 public_id（前台路由用）
ORDER_NO=$(echo "$ORDER" | jpath data.order_no)
[ -n "$ORDER_ID" ] && ok "下单成功(no=$ORDER_NO)" || { bad "下单: $(echo "$ORDER" | head -c 120)"; exit 1; }

# 后台订单接口（accept/ship）使用 int 主键（后台前端亦然），按单号取回后台 id
ADMIN_OID=$(req GET "/admin/orders?order_no=$ORDER_NO" "$ATOK" '' | jpath data.list.0.id)
[ -n "$ADMIN_OID" ] && ok "取回后台订单 id($ADMIN_OID)" || { bad "查询后台订单失败"; exit 1; }

PAY=$(req POST /payments "$TOKEN" "{\"order_no\":\"$ORDER_NO\",\"channel\":\"wechat\"}")
PAY_NO=$(echo "$PAY" | jpath data.payment_no)
[ -z "$PAY_NO" ] && PAY_NO=$(echo "$PAY" | jpath data.pay_params.payment_no)
[ -n "$PAY_NO" ] && ok "发起支付(no=$PAY_NO)" || { bad "发起支付: $(echo "$PAY" | head -c 120)"; exit 1; }

SANDBOX=$(req POST "/payments/sandbox/$PAY_NO" "$TOKEN" '')
[ "$(echo "$SANDBOX" | jpath code)" = "0" ] && ok "沙箱支付成功" || bad "沙箱支付: $(echo "$SANDBOX" | head -c 120)"

STATUS=$(req GET "/orders/$ORDER_ID" "$TOKEN" '' | jpath data.status)
[ "$STATUS" = "pending_ship" ] && ok "支付后自动流转到待发货" || bad "订单状态: $STATUS"

echo "--- 5. 管理端受理备货（幂等）与发货"
ACCEPT=$(req POST "/admin/orders/$ADMIN_OID/accept" "$ATOK" '{"remark":"冒烟受理"}')
[ "$(echo "$ACCEPT" | jpath code)" = "0" ] && ok "受理备货幂等返回成功" || bad "受理备货: $(echo "$ACCEPT" | head -c 120)"
SLABEL=$(req GET "/admin/orders/$ADMIN_OID" "$ATOK" '' | jpath data.status_label)
[ "$SLABEL" = "待发货" ] && ok "后台状态标签 待发货" || bad "状态标签: $SLABEL"

# T-043 起必须传 express_company_code（启用字典）与 8~32 位运单号；单号按运行唯一避免二次运行撞唯一索引
SMOKE_TRACK="SMOKE$(date +%s)"
SHIP=$(req POST "/admin/orders/$ADMIN_OID/ship" "$ATOK" "{\"express_company_code\":\"SF\",\"tracking_no\":\"$SMOKE_TRACK\"}")
[ "$(echo "$SHIP" | jpath code)" = "0" ] && ok "发货成功($SMOKE_TRACK)" || bad "发货: $(echo "$SHIP" | head -c 160)"

# ---------- V1.1 增量用例（T-030 扩展） ----------
echo "--- 5b. 用户确认收货（V1.1 E02-A）"
CONFIRM=$(req POST "/orders/$ORDER_ID/confirm" "$TOKEN" '{}')
[ "$(echo "$CONFIRM" | jpath code)" = "0" ] && ok "确认收货" || bad "确认收货: $(echo "$CONFIRM" | head -c 120)"
STATUS2=$(req GET "/orders/$ORDER_ID" "$TOKEN" '' | jpath data.status)
[ "$STATUS2" = "completed" ] && ok "订单状态 completed" || bad "订单状态: $STATUS2"

echo "--- 5c. 商品评价（V1.1 F01）"
ITEM_ID=$(req GET "/orders/$ORDER_ID" "$TOKEN" '' | jpath data.items.0.id)
if [ -n "$ITEM_ID" ]; then
  REV=$(req POST "/orders/$ORDER_ID/items/$ITEM_ID/review" "$TOKEN" '{"rating":5,"content":"冒烟测试好评"}')
  [ "$(echo "$REV" | jpath code)" = "0" ] && ok "提交评价" || bad "评价: $(echo "$REV" | head -c 120)"
else
  echo "  (无订单明细，跳过评价)"
fi

echo "--- 5d. 收藏与足迹（V1.1 F05）"
FAV=$(req POST "/products/$FIRST/favorite" "$TOKEN" '')
[ "$(echo "$FAV" | jpath code)" = "0" ] && ok "收藏商品" || bad "收藏: $(echo "$FAV" | head -c 120)"
FAVLIST=$(req GET "/me/favorites" "$TOKEN" '' | jpath code)
[ "$FAVLIST" = "0" ] && ok "收藏列表" || bad "收藏列表"
TRACK=$(req POST "/products/$FIRST/track" "$TOKEN" '')
[ "$(echo "$TRACK" | jpath code)" = "0" ] && ok "足迹上报" || bad "足迹: $(echo "$TRACK" | head -c 120)"

echo "--- 5e. 站内通知未读数（V1.1 F02）"
UNREAD=$(req GET /me/notifications/unread-count "$TOKEN" '' | jpath data.count)
[ -n "$UNREAD" ] && ok "通知未读数($UNREAD)" || bad "通知未读数"

echo "--- 5f. 修改密码（V1.1 E05-B）"
PWDCHG=$(req POST /auth/password "$TOKEN" '{"old_password":"Test@1234","password":"Test@5678","password_confirmation":"Test@5678"}')
[ "$(echo "$PWDCHG" | jpath code)" = "0" ] && ok "修改密码" || bad "改密: $(echo "$PWDCHG" | head -c 120)"
# 其他设备 Token 已失效 → 新登录可用
captcha_pair "$U" login
NEWTOK=$(req POST /auth/login '' "{\"username\":\"$U\",\"password\":\"Test@5678\",\"captcha_id\":\"$CAP_ID\",\"captcha_code\":\"$CAP_CODE\"}" | jpath data.token)
[ -n "$NEWTOK" ] && ok "新密码可登录" || bad "新密码登录失败"
TOKEN="$NEWTOK"

echo "--- 5g. 头像上传（V1.1 E05-A）"
TMPIMG="./docs/testing/.smoke-avatar.png"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' | base64 -d > "$TMPIMG" 2>/dev/null
UP=$(curl -s --noproxy '*' -X POST "$BASE/user/upload" -H "Authorization: Bearer $TOKEN" -F "file=@$TMPIMG;type=image/png")
[ "$(echo "$UP" | jpath code)" = "0" ] && ok "头像上传" || bad "上传: $(echo "$UP" | head -c 120)"

echo "--- 5h. 余额充值（收银台 P6）"
BAL0=$(req GET /user/balance "$TOKEN" '' | jpath data.balance)
[ -n "$BAL0" ] && ok "余额查询（初始 ¥$BAL0）" || bad "余额查询"

RCH_CH=$(req GET "/payments/channels?scene=recharge" "$TOKEN" '' | jpath code)
[ "$RCH_CH" = "0" ] && ok "充值渠道列表（scene=recharge）" || bad "充值渠道列表"

RCH=$(req POST /user/balance/recharges "$TOKEN" '{"amount":"100.00","channel":"mock"}')
RCH_PAY=$(echo "$RCH" | jpath data.payment_no)
[ -n "$RCH_PAY" ] && ok "发起充值（$RCH_PAY）" || bad "发起充值: $(echo "$RCH" | head -c 120)"

RCH_SB=$(req POST "/payments/sandbox/$RCH_PAY" '' '')
[ "$(echo "$RCH_SB" | jpath code)" = "0" ] && ok "充值沙箱支付回调" || bad "充值沙箱: $(echo "$RCH_SB" | head -c 120)"

BAL1=$(req GET /user/balance "$TOKEN" '' | jpath data.balance)
[ "$BAL1" = "100.00" ] && ok "充值到账 ¥$BAL1" || bad "充值到账: $BAL1"

RCH_LIST=$(req GET /user/balance/recharges "$TOKEN" '' | jpath code)
[ "$RCH_LIST" = "0" ] && ok "充值记录列表" || bad "充值记录列表"
BLOG=$(req GET /user/balance/logs "$TOKEN" '' | jpath code)
[ "$BLOG" = "0" ] && ok "余额流水列表" || bad "余额流水列表"

echo "--- 5i. WMS 内部闭环（P0 配置 + P1 履约发货单）"
# 建仓
WH=$(req POST /admin/wms/warehouses "$ATOK" "{\"code\":\"WH_SMOKE_$(date +%s)\",\"name\":\"冒烟仓\",\"province\":\"广东省\",\"city\":\"深圳市\",\"district\":\"南山区\",\"address\":\"冒烟仓路1号\",\"status\":1}")
WH_ID=$(echo "$WH" | jpath data.id)
[ -n "$WH_ID" ] && ok "建仓(id=$WH_ID)" || bad "建仓: $(echo "$WH" | head -c 160)"

# 保存配置：沙箱 + **不配凭证** + same 映射 —— 按 P2 规则（凭证齐备才走真实网关）应走 Mock
CFG=$(req PUT "/admin/wms/warehouses/$WH_ID/config" "$ATOK" '{"provider":"cainiao","enabled":true,"auto_push":true,"auto_push_return":true,"push_retry_times":3,"sku_mapping_mode":"same","api_env":"sandbox","warehouse_code":"CN-WH-SMOKE","remark":"冒烟"}')
[ "$(echo "$CFG" | jpath code)" = "0" ] && ok "保存配置（未配凭证）" || bad "保存配置: $(echo "$CFG" | head -c 160)"
CB=$(echo "$CFG" | jpath data.callback_url)
[ -n "$CB" ] && ok "回调地址可生成" || bad "回调地址缺失"

# 连通性测试：未配凭证 → 走 Mock（沙箱账号未到位时可先跑通链路）
TC=$(req POST "/admin/wms/warehouses/$WH_ID/config/test" "$ATOK" '')
if [ "$(echo "$TC" | jpath data.success)" = "True" ] && [ "$(echo "$TC" | jpath data.mock)" = "True" ]; then
  ok "未配凭证 → 连通性走 Mock"
else
  bad "连通性(Mock): $(echo "$TC" | head -c 200)"
fi

# P2 / SEC-01：配了真实凭证但未配置网关地址 → fail-closed（绝不静默降级成 Mock 假成功）
WH2=$(req POST /admin/wms/warehouses "$ATOK" "{\"code\":\"WH_SMOKE_FC_$(date +%s)\",\"name\":\"冒烟failclosed仓\",\"status\":1}")
WH2_ID=$(echo "$WH2" | jpath data.id)
CFG2=$(req PUT "/admin/wms/warehouses/$WH2_ID/config" "$ATOK" '{"provider":"cainiao","enabled":true,"auto_push":false,"auto_push_return":false,"push_retry_times":3,"sku_mapping_mode":"same","app_key":"SMOKE_KEY","app_secret":"SMOKE_SECRET_WXYZ","api_env":"sandbox","warehouse_code":"CN-WH-FC","remark":"冒烟"}')
MASK=$(echo "$CFG2" | jpath data.app_secret_masked)
[ -n "$MASK" ] && ok "保存凭证(掩码=$MASK)" || bad "保存凭证: $(echo "$CFG2" | head -c 160)"
echo "$CFG2" | grep -q "SMOKE_SECRET_WXYZ" && bad "配置响应泄漏明文密钥" || ok "配置响应无明文密钥"
TC2=$(req POST "/admin/wms/warehouses/$WH2_ID/config/test" "$ATOK" '')
if [ "$(echo "$TC2" | jpath data.success)" = "False" ] && [ "$(echo "$TC2" | jpath data.mock)" != "True" ]; then
  ok "配凭证缺网关 → fail-closed（不静默 Mock）"
else
  bad "fail-closed 校验: $(echo "$TC2" | head -c 220)"
fi
# 关掉第二个仓，避免影响后续履约（配置解析按启用态的 id 升序取第一个）
req PUT "/admin/wms/warehouses/$WH2_ID/config" "$ATOK" '{"provider":"cainiao","enabled":false,"auto_push":false,"auto_push_return":false,"push_retry_times":3,"sku_mapping_mode":"same","api_env":"sandbox"}' >/dev/null

# SKU 映射：批量导入 1 成 1 败（逐行反馈）
BATCH=$(req POST "/admin/wms/warehouses/$WH_ID/sku-mappings/batch" "$ATOK" "{\"rows\":[{\"sku_code\":\"$SKU_CODE\",\"wms_sku_code\":\"WMS-$SKU_CODE\",\"barcode\":\"6900001\"},{\"sku_code\":\"NO-SUCH-SKU-SMOKE\",\"wms_sku_code\":\"X\"}]}")
BSUCC=$(echo "$BATCH" | jpath data.success_count); BFAIL=$(echo "$BATCH" | jpath data.failed_count)
[ "$BSUCC" = "1" ] && [ "$BFAIL" = "1" ] && ok "映射批量导入(成$BSUCC/败$BFAIL，逐行反馈)" || bad "映射导入: $(echo "$BATCH" | head -c 200)"
MLIST=$(req GET "/admin/wms/warehouses/$WH_ID/sku-mappings" "$ATOK" '')
[ "$(echo "$MLIST" | jpath code)" = "0" ] && ok "映射列表" || bad "映射列表"

# 新订单 → 支付 → 自动建发货单
req POST /cart "$TOKEN" "{\"sku_id\":$SKU_ID,\"quantity\":1}" >/dev/null
ORDER2=$(req POST /orders "$TOKEN" "{\"address_id\":$ADDR,\"remark\":\"WMS 冒烟\"}")
ORDER2_NO=$(echo "$ORDER2" | jpath data.order_no)
PAY2=$(req POST /payments "$TOKEN" "{\"order_no\":\"$ORDER2_NO\",\"channel\":\"wechat\"}")
PAY2_NO=$(echo "$PAY2" | jpath data.payment_no); [ -z "$PAY2_NO" ] && PAY2_NO=$(echo "$PAY2" | jpath data.pay_params.payment_no)
req POST "/payments/sandbox/$PAY2_NO" "$TOKEN" '' >/dev/null

FO_LIST=$(req GET "/admin/wms/fulfillment-orders?order_no=$ORDER2_NO" "$ATOK" '')
FO_ID=$(echo "$FO_LIST" | jpath data.list.0.id); FO_STATUS=$(echo "$FO_LIST" | jpath data.list.0.status)
[ -n "$FO_ID" ] && ok "支付后自动建发货单(id=$FO_ID 状态=$FO_STATUS)" || bad "自动建单: $(echo "$FO_LIST" | head -c 200)"

FO_DETAIL=$(req GET "/admin/wms/fulfillment-orders/$FO_ID" "$ATOK" '')
[ "$(echo "$FO_DETAIL" | jpath data.items.0.platform_sku_code)" = "$SKU_CODE" ] && ok "发货单详情含行项目" || bad "发货单详情: $(echo "$FO_DETAIL" | head -c 200)"

# 手工重推：未推送→成功；已推送→按状态机拒绝（40009），两者都属预期
PUSH=$(req POST "/admin/wms/fulfillment-orders/$FO_ID/push" "$ATOK" '')
PCODE=$(echo "$PUSH" | jpath code)
if [ "$PCODE" = "0" ]; then ok "手工重推成功"
elif [ "$PCODE" = "40009" ]; then ok "手工重推被状态机拒绝(已推送)"
else bad "重推: $(echo "$PUSH" | head -c 160)"; fi

# 取消发货单（出库前可取消，落审计）
CANCEL=$(req POST "/admin/wms/fulfillment-orders/$FO_ID/cancel" "$ATOK" '{"reason":"冒烟取消"}')
[ "$(echo "$CANCEL" | jpath data.status)" = "cancelled" ] && ok "取消发货单" || bad "取消: $(echo "$CANCEL" | head -c 160)"

# 关闭配置，避免影响后续人工发货用例
req PUT "/admin/wms/warehouses/$WH_ID/config" "$ATOK" '{"provider":"cainiao","enabled":false,"auto_push":false,"auto_push_return":false,"push_retry_times":3,"sku_mapping_mode":"same","api_env":"sandbox"}' >/dev/null
ok "关闭 WMS 配置（清理）"

echo "--- 6. 退出登录"
OUT=$(req POST /auth/logout "$TOKEN" '')
[ "$(echo "$OUT" | jpath code)" = "0" ] && ok "退出登录" || bad "退出: $(echo "$OUT" | head -c 80)"

echo
echo "=== 冒烟结果：PASS $PASS / FAIL $FAIL ==="
[ "$FAIL" = "0" ]
