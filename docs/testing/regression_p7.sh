#!/usr/bin/env bash
# CubeShop P7 全量回归测试脚本
# 覆盖 docs/design/CubeShop_TestCases_v1.0.md 中全部 P0 用例 + 少量 P1 抽样
# 用法：确保 artisan serve 运行在 127.0.0.1:8000、数据库已 migrate:fresh --seed，然后在任意目录执行本脚本
set -u
cd "$(dirname "$0")/../../backend" || exit 1
BASE="http://127.0.0.1:8000/api"
REPORT="$(dirname "$0")/Regression_Report_P7.md"
PASS=0; FAIL=0; LOG=()

ok()  { PASS=$((PASS+1)); LOG+=("PASS|${1}|${2}"); echo "  [PASS] ${1} ${2}"; }
bad() { FAIL=$((FAIL+1)); LOG+=("FAIL|${1}|${2}"); echo "  [FAIL] ${1} ${2}"; }

# 提取 JSON 字段（点路径，数组用数字下标）；失败输出 <extract-error>
j() { python -c "
import sys, json
try:
    cur = json.load(sys.stdin)
    for k in '''$1'''.split('.'):
        if k == '': continue
        cur = cur[int(k)] if isinstance(cur, list) else (cur.get(k) if cur else None)
    print(cur if cur is not None else '')
except Exception:
    print('<extract-error>')
"; }

# req METHOD PATH TOKEN [JSON_BODY] -> 响应体
req() {
  local m=$1 p=$2 t=$3 d=${4:-}
  if [ -n "$d" ]; then
    curl -s --noproxy '*' -X "$m" "$BASE$p" -H 'Content-Type: application/json' ${t:+-H "Authorization: Bearer $t"} -d "$d"
  else
    curl -s --noproxy '*' -X "$m" "$BASE$p" ${t:+-H "Authorization: Bearer $t"}
  fi
}
# 带查询参数的 GET：getq PATH 'key=value' [TOKEN]
getq() {
  local p=$1 kv=$2 t=${3:-}
  curl -s --noproxy '*' -G "$BASE$p" --data-urlencode "${kv%%=*}=${kv#*=}" ${t:+-H "Authorization: Bearer $t"}
}
# HTTP 状态码
code() { curl -s --noproxy '*' -o /dev/null -w '%{http_code}' -X "$1" "$BASE$2" ${3:+-H "Authorization: Bearer $3"} ${4:+-H 'Content-Type: application/json' -d "$4"}; }

captcha_pair() { # target type -> 设置 CID/CODE 全局变量
  local cap; cap=$(req POST /auth/captcha '' "{\"target\":\"$1\",\"type\":\"$2\"}")
  CID=$(echo "$cap" | j data.captcha_id)
  CODE=$(echo "$cap" | j data.debug_code)
}

db() { php artisan tinker --execute="$1" 2>/dev/null | tail -1; }

echo "==== CubeShop P7 回归测试开始 $(date '+%F %T') ===="

# ---------- 2.6 系统与安全 ----------
echo "--- TC-SYS 未登录访问（P0）"
H=$(code GET /user/profile)
[ "$H" = "401" ] && ok TC-SYS-001 "未登录访问返回 401" || bad TC-SYS-001 "未登录访问返回 $H"

# ---------- 2.1 用户与账号 ----------
echo "--- TC-USER 注册/登录/地址"
captcha_pair 13900001111 register
R=$(req POST /auth/register '' "{\"username\":\"13900001111\",\"password\":\"Test@1234\",\"password_confirmation\":\"Test@1234\",\"code\":\"$CODE\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CODE\"}")
[ "$(echo "$R" | j code)" = "0" ] && ok TC-USER-001 "注册成功" || bad TC-USER-001 "注册失败: $(echo "$R" | head -c 120)"

captcha_pair 13900001111 register
R=$(req POST /auth/register '' "{\"username\":\"13900001111\",\"password\":\"Test@1234\",\"password_confirmation\":\"Test@1234\",\"code\":\"$CODE\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CODE\"}")
[ "$(echo "$R" | j code)" != "0" ] && ok TC-USER-002 "重复注册被拒绝（code=$(echo "$R" | j code)）" || bad TC-USER-002 "重复注册未被拒绝"

captcha_pair 13900001111 login
R=$(req POST /auth/login '' "{\"username\":\"13900001111\",\"password\":\"Test@1234\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CODE\"}")
TOKEN=$(echo "$R" | j data.token)
[ "$(echo "$R" | j code)" = "0" ] && [ -n "$TOKEN" ] && ok TC-USER-004 "登录成功获得 Token" || bad TC-USER-004 "登录失败: $(echo "$R" | head -c 120)"

captcha_pair 13900001111 login
R=$(req POST /auth/login '' "{\"username\":\"13900001111\",\"password\":\"Wrong@123\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CODE\"}")
[ "$(echo "$R" | j code)" != "0" ] && ok TC-USER-005 "密码错误被拒绝" || bad TC-USER-005 "密码错误未被拒绝"

R=$(req POST /user/addresses "$TOKEN" '{"contact_name":"张三","contact_phone":"13900001111","province":"广东省","city":"深圳市","district":"南山区","detail_address":"科技园路 1 号","is_default":true}')
ADDR_ID=$(echo "$R" | j data.id)
[ "$(echo "$R" | j code)" = "0" ] && [ -n "$ADDR_ID" ] && ok TC-USER-007 "新增收货地址 id=$ADDR_ID" || bad TC-USER-007 "新增地址失败: $(echo "$R" | head -c 120)"

# ---------- 2.2 商品模块 ----------
echo "--- TC-PROD 浏览/搜索/详情"
R=$(req GET '/products?page_size=20' '')
N=$(echo "$R" | j data.pagination.total)
[ "$(echo "$R" | j code)" = "0" ] && [ "${N:-0}" -ge 8 ] && ok TC-PROD-001 "商品列表 total=$N" || bad TC-PROD-001 "商品列表异常 total=$N"

R=$(getq '/products' 'keyword=耳机' '')
T=$(echo "$R" | j data.list.0.title)
[ "$T" = "无线蓝牙耳机" ] && ok TC-PROD-002 "搜索「耳机」命中" || bad TC-PROD-002 "搜索结果异常: $T"

R=$(req GET /products/1 '')
S=$(echo "$R" | j data.skus.0.id)
[ "$(echo "$R" | j code)" = "0" ] && [ -n "$S" ] && ok TC-PROD-004 "商品详情含 SKU(sku1=$S)" || bad TC-PROD-004 "商品详情异常"

# ---------- 2.3 购物车模块 ----------
echo "--- TC-CART 加购/库存校验/改量/删除"
R=$(req POST /cart "$TOKEN" '{"sku_id":1,"quantity":1}')
[ "$(echo "$R" | j code)" = "0" ] && ok TC-CART-001 "加购成功 sku1 x1" || bad TC-CART-001 "加购失败: $(echo "$R" | head -c 120)"

R=$(req POST /cart "$TOKEN" '{"sku_id":2,"quantity":999}')
[ "$(echo "$R" | j code)" != "0" ] && ok TC-CART-002 "超库存加购被拒绝（code=$(echo "$R" | j code)）" || bad TC-CART-002 "超库存加购未被拒绝"

CID1=$(req GET /cart "$TOKEN" | j "data.items.0.id")
R=$(req PUT "/cart/$CID1" "$TOKEN" '{"quantity":3}')
Q=$(req GET /cart "$TOKEN" | j "data.items.0.quantity")
[ "$(echo "$R" | j code)" = "0" ] && [ "$Q" = "3" ] && ok TC-CART-003 "修改数量 1→3" || bad TC-CART-003 "修改数量失败: $Q"

req POST /cart "$TOKEN" '{"sku_id":3,"quantity":1}' > /dev/null
# 购物车列表按最新在前排序，不能按位置索引——按 sku_id 定位待删项
CID3=$(req GET /cart "$TOKEN" | python -c "
import sys, json
items = json.load(sys.stdin)['data']['items']
m = [i['id'] for i in items if i['sku_id'] == 3]
print(m[0] if m else '')")
R=$(req DELETE "/cart/$CID3" "$TOKEN")
[ "$(echo "$R" | j code)" = "0" ] && ok TC-CART-004 "删除购物车商品（sku3）" || bad TC-CART-004 "删除失败"

# ---------- 2.4 订单与支付 ----------
echo "--- TC-ORDER 下单/支付/取消/超时"
LOCK1=$(db 'echo App\Models\Inventory::where("sku_id",1)->value("locked_stock");')
R=$(req POST /orders "$TOKEN" "{\"address_id\":$ADDR_ID,\"remark\":\"回归测试订单\"}")
ORDER_A_NO=$(echo "$R" | j data.order_no); ORDER_A_ID=$(echo "$R" | j data.order_id)
PAY_A=$(echo "$R" | j data.pay_amount)
LOCK1B=$(db 'echo App\Models\Inventory::where("sku_id",1)->value("locked_stock");')
if [ "$(echo "$R" | j code)" = "0" ] && [ "$(echo "$R" | j data.status)" = "pending_payment" ] \
   && [ "$PAY_A" = "297.00" ] && [ "$LOCK1B" = "$((LOCK1+3))" ]; then
  ok TC-ORDER-001 "下单成功 快照金额=$PAY_A 锁库存 $LOCK1→$LOCK1B"
else
  bad TC-ORDER-001 "下单异常 pay=$PAY_A lock=$LOCK1→$LOCK1B"
fi

R=$(req POST /orders "$TOKEN" '{"address_id":999}')
[ "$(echo "$R" | j code)" != "0" ] && ok TC-ORDER-002 "无效地址下单被拒绝（code=$(echo "$R" | j code)）" || bad TC-ORDER-002 "无效地址下单未被拒绝"

R=$(req POST /payments "$TOKEN" "{\"order_no\":\"$ORDER_A_NO\",\"channel\":\"wechat\"}")
PAYNO_A=$(echo "$R" | j data.payment_no)
[ "$(echo "$R" | j code)" = "0" ] && [ -n "$PAYNO_A" ] || bad TC-ORDER-003 "发起支付失败: $(echo "$R" | head -c 120)"
R=$(req POST "/payments/sandbox/$PAYNO_A" '' '{"result":"success"}')
ST=$(req GET "/orders/by-no/$ORDER_A_NO" "$TOKEN" | j data.status)
PAID_AT=$(req GET "/orders/by-no/$ORDER_A_NO" "$TOKEN" | j data.paid_at)
[ "$ST" = "paid" ] && [ -n "$PAID_AT" ] && [ "$PAY_A" = "297.00" ] \
  && ok TC-ORDER-003 "沙箱支付成功 订单 paid paid_at=$PAID_AT" || bad TC-ORDER-003 "支付后状态=$ST"

R=$(req POST "/payments/sandbox/$PAYNO_A" '' '{"result":"success"}')
DUP_PAYMENTS=$(db "echo App\Models\Payment::where('order_id',$ORDER_A_ID)->count();")
ST2=$(req GET "/orders/by-no/$ORDER_A_NO" "$TOKEN" | j data.status)
[ "$(echo "$R" | j code)" = "0" ] && [ "$DUP_PAYMENTS" = "1" ] && [ "$ST2" = "paid" ] \
  && ok TC-ORDER-005 "重复回调幂等（支付单仍 $DUP_PAYMENTS 条）" || bad TC-ORDER-005 "幂等异常 payments=$DUP_PAYMENTS status=$ST2 resp=$(echo "$R" | head -c 80)"

# ORDER-004 支付失败：订单 B（sku3 x1 = 69 + 运费 10 = 79）
req POST /cart "$TOKEN" '{"sku_id":3,"quantity":1}' > /dev/null
R=$(req POST /orders "$TOKEN" "{\"address_id\":$ADDR_ID}")
ORDER_B_NO=$(echo "$R" | j data.order_no)
R=$(req POST /payments "$TOKEN" "{\"order_no\":\"$ORDER_B_NO\",\"channel\":\"alipay\"}")
PAYNO_B=$(echo "$R" | j data.payment_no)
req POST "/payments/sandbox/$PAYNO_B" '' '{"result":"failed"}' > /dev/null
ST_B=$(req GET "/orders/by-no/$ORDER_B_NO" "$TOKEN" | j data.status)
R=$(req POST /payments "$TOKEN" "{\"order_no\":\"$ORDER_B_NO\",\"channel\":\"alipay\"}")
PAYNO_B2=$(echo "$R" | j data.payment_no)
req POST "/payments/sandbox/$PAYNO_B2" '' '{"result":"success"}' > /dev/null
ST_B2=$(req GET "/orders/by-no/$ORDER_B_NO" "$TOKEN" | j data.status)
if [ "$ST_B" = "pending_payment" ] && [ "$(echo "$R" | j code)" = "0" ] && [ "$ST_B2" = "paid" ]; then
  ok TC-ORDER-004 "支付失败后订单仍待支付且可重新支付"
else
  bad TC-ORDER-004 "失败恢复异常 fail后=$ST_B 重试后=$ST_B2"
fi

# ORDER-006 取消待支付订单 C（sku4 x1）
req POST /cart "$TOKEN" '{"sku_id":4,"quantity":1}' > /dev/null
R=$(req POST /orders "$TOKEN" "{\"address_id\":$ADDR_ID}")
ORDER_C_ID=$(echo "$R" | j data.order_id)
LOCK4=$(db 'echo App\Models\Inventory::where("sku_id",4)->value("locked_stock");')
R=$(req POST "/orders/$ORDER_C_ID/cancel" "$TOKEN" '{"reason":"回归-不想买了"}')
LOCK4B=$(db 'echo App\Models\Inventory::where("sku_id",4)->value("locked_stock");')
ST_C=$(req GET "/orders/$ORDER_C_ID" "$TOKEN" | j data.status)
if [ "$(echo "$R" | j code)" = "0" ] && [ "$ST_C" = "cancelled" ] && [ "$LOCK4B" = "$((LOCK4-1))" ]; then
  ok TC-ORDER-006 "取消待支付订单 库存释放 $LOCK4→$LOCK4B"
else
  bad TC-ORDER-006 "取消异常 status=$ST_C lock=$LOCK4→$LOCK4B"
fi

# ORDER-008 列表与详情
R=$(getq '/orders' 'status=paid' "$TOKEN")
TOTAL_PAID=$(echo "$R" | j data.pagination.total)
R=$(req GET "/orders/$ORDER_A_ID" "$TOKEN")
SNAP=$(echo "$R" | j data.address_snapshot.contact_name)
ITEMS=$(echo "$R" | j data.items.0.product_title)
if [ "${TOTAL_PAID:-0}" -ge 2 ] && [ "$SNAP" = "张三" ] && [ -n "$ITEMS" ]; then
  ok TC-ORDER-008 "列表筛选 paid=$TOTAL_PAID 条，详情快照/明细完整"
else
  bad TC-ORDER-008 "列表/详情异常 paid=$TOTAL_PAID snap=$SNAP"
fi

# ORDER-010 超时自动取消 D（sku5 x1）
req POST /cart "$TOKEN" '{"sku_id":5,"quantity":1}' > /dev/null
R=$(req POST /orders "$TOKEN" "{\"address_id\":$ADDR_ID}")
ORDER_D_ID=$(echo "$R" | j data.order_id)
db "\DB::table('orders')->where('id',$ORDER_D_ID)->update(['created_at'=>now()->subMinutes(40)]);" > /dev/null
php artisan orders:cancel-expired > /dev/null 2>&1
ST_D=$(req GET "/orders/$ORDER_D_ID" "$TOKEN" | j data.status)
REASON=$(req GET "/orders/$ORDER_D_ID" "$TOKEN" | j data.cancel_reason)
[ "$ST_D" = "cancelled" ] && ok TC-ORDER-010 "超时自动取消 reason=$REASON" || bad TC-ORDER-010 "超时取消异常 status=$ST_D"

# ---------- 2.5 后台管理 ----------
echo "--- TC-ADMIN 登录/商品/发货"
captcha_pair admin login
R=$(req POST /auth/login '' "{\"username\":\"admin\",\"password\":\"Admin@123\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CODE\"}")
ATOKEN=$(echo "$R" | j data.token)

R=$(req POST /admin/products "$ATOKEN" '{"category_id":1,"title":"回归测试商品","subtitle":"P7 回归","status":1,"skus":[{"sku_code":"REG-001","specs":{"颜色":"黑"},"price":10.5,"stock":30}]}')
PID=$(echo "$R" | j data.id)
[ "$(echo "$R" | j code)" = "0" ] && [ -n "$PID" ] && ok TC-ADMIN-001 "新增商品 id=$PID" || bad TC-ADMIN-001 "新增商品失败: $(echo "$R" | head -c 120)"

R=$(req PUT "/admin/products/$PID" "$ATOKEN" '{"title":"回归测试商品(改)","status":0}')
OFF=$(getq '/products' 'keyword=回归测试商品(改)' '' | j data.pagination.total)
R=$(req PUT "/admin/products/$PID" "$ATOKEN" '{"status":1}')
ON=$(getq '/products' 'keyword=回归测试商品(改)' '' | j data.pagination.total)
if [ "$(echo "$R" | j code)" = "0" ] && [ "${OFF:-0}" = "0" ] && [ "${ON:-0}" -ge 1 ]; then
  ok TC-ADMIN-002 "编辑 + 上下架生效（下架不可见/上架可见）"
else
  bad TC-ADMIN-002 "上下架异常 off=$OFF on=$ON"
fi

R=$(req POST "/admin/orders/$ORDER_A_ID/ship" "$ATOKEN" '{"remark":"SF000111222"}')
ST_A=$(req GET "/orders/$ORDER_A_ID" "$TOKEN" | j data.status)
SA=$(req GET "/orders/$ORDER_A_ID" "$TOKEN" | j data.shipped_at)
[ "$(echo "$R" | j code)" = "0" ] && [ "$ST_A" = "shipped" ] && [ -n "$SA" ] \
  && ok TC-ADMIN-005 "发货成功 shipped_at=$SA" || bad TC-ADMIN-005 "发货异常 status=$ST_A"

# SYS-002 防重复提交（登录限流 5 次/分钟，超限返回 HTTP 429）
H=0
for i in 1 2 3 4 5 6; do
  captcha_pair 13900001111 login > /dev/null
  HC=$(code POST /auth/login '' "{\"username\":\"13900001111\",\"password\":\"Test@1234\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CODE\"}")
  [ "$HC" = "429" ] && H=1 && break
  sleep 0.2
done
[ "$H" = "1" ] && ok TC-SYS-002 "登录触发限流 429（第 ${i} 次）" || bad TC-SYS-002 "限流未生效（最后 HTTP=$HC）"

echo "==== 回归结束：PASS=$PASS FAIL=$FAIL ===="

# ---------- 生成报告 ----------
TOTAL=$((PASS+FAIL))
RATE=$(awk "BEGIN{if($TOTAL>0) printf \"%.1f\", $PASS*100/$TOTAL; else print \"N/A\"}")
{
  echo "# CubeShop P7 回归测试报告"
  echo
  echo "- 执行时间：$(date '+%F %T')"
  echo "- 测试范围：docs/design/CubeShop_TestCases_v1.0.md 全部 P0 用例 + TC-SYS-002 抽样"
  echo "- 测试环境：本地 PostgreSQL + Laravel 13.12（artisan serve，沙箱支付）"
  echo "- 结果：**PASS $PASS / FAIL $FAIL**（通过率 ${RATE}%）"
  echo
  echo "| 结果 | 用例 | 说明 |"
  echo "|---|---|---|"
  for line in "${LOG[@]}"; do
    IFS='|' read -r tc r note <<< "$line"
    echo "| $tc | $r | $note |"
  done
} > "$REPORT"
echo "报告已写入：$REPORT"
exit $([ "$FAIL" = "0" ] && echo 0 || echo 1)
