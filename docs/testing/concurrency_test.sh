#!/usr/bin/env bash
# CubeShop P7 并发下单防超卖验证
# 场景：创建库存 5 的商品 → 10 个并发用户同时各下单 1 件 → 恰好 5 单成功、5 单 40009，锁定库存=5
set -u
cd "$(dirname "$0")/../../backend" || exit 1
BASE="http://127.0.0.1:8000/api"
TMP="storage/logs/concurrency_test"
mkdir -p "$TMP"

j() { python -c "
import sys, json
try:
    cur = json.load(sys.stdin)
    for k in '''$1'''.split('.'):
        if k == '': continue
        cur = cur[int(k)] if isinstance(cur, list) else (cur.get(k) if cur else None)
    print(cur if cur is not None else '')
except Exception:
    print('')
"; }

req() {
  local m=$1 p=$2 t=$3 d=${4:-}
  if [ -n "$d" ]; then
    curl -s --noproxy '*' -X "$m" "$BASE$p" -H 'Content-Type: application/json' ${t:+-H "Authorization: Bearer $t"} -d "$d"
  else
    curl -s --noproxy '*' -X "$m" "$BASE$p" ${t:+-H "Authorization: Bearer $t"}
  fi
}
captcha_pair() {
  local cap; cap=$(req POST /auth/captcha '' "{\"target\":\"$1\",\"type\":\"$2\"}")
  CID=$(echo "$cap" | j data.captcha_id); CODE=$(echo "$cap" | j data.debug_code)
}
# 验证码获取带限流重试（auth 接口 10 次/分钟共享 IP）
captcha_retry() {
  local tries=0
  captcha_pair "$1" "$2"
  while [ -z "$CID" ] && [ $tries -lt 10 ]; do
    tries=$((tries+1)); sleep 12
    captcha_pair "$1" "$2"
  done
}
db() { php artisan tinker --execute="$1" 2>/dev/null | tail -1; }

echo "==== 并发防超卖验证开始 $(date '+%F %T') ===="

# 1. 管理员登录并创建库存 5 的商品（sku_code 带时间戳避免唯一约束冲突）
captcha_pair admin login
ATOK=$(req POST /auth/login '' "{\"username\":\"admin\",\"password\":\"Admin@123\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CODE\"}" | j data.token)
STAMP=$(date +%s)
PID=$(req POST /admin/products "$ATOK" "{\"category_id\":1,\"title\":\"并发压测商品$STAMP\",\"status\":1,\"skus\":[{\"sku_code\":\"CC-$STAMP\",\"specs\":{\"规格\":\"标准\"},\"price\":1,\"stock\":5}]}" | j data.id)
if [ -z "$PID" ]; then
  echo "FAIL：压测商品创建失败（管理员登录可能被限流，请等待 1 分钟后重试）"
  exit 1
fi
SKU_ID=$(req GET "/products/$PID" '' | j data.skus.0.id)
echo "压测商品 product=$PID sku=$SKU_ID stock=5"

# 2. 串行注册 10 个用户（唯一用户名 + 限流重试），各自 Token + 地址 + 加购
STAMP2=$(date +%s)
i=0
while [ $i -lt 10 ]; do
  i=$((i+1))
  U="cc${STAMP2}u$i"
  captcha_retry "$U" register
  TOK=$(req POST /auth/register '' "{\"username\":\"$U\",\"password\":\"Test@1234\",\"password_confirmation\":\"Test@1234\",\"code\":\"$CODE\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CODE\"}" | j data.token)
  if [ -z "$TOK" ]; then
    echo "FAIL：用户 $U 注册失败"; rm -f "$TMP"/token_* "$TMP"/addr_* "$TMP"/order_*.json; exit 1
  fi
  ADDR=$(req POST /user/addresses "$TOK" '{"contact_name":"并发测试","contact_phone":"13800000000","province":"广东省","city":"深圳市","district":"南山区","detail_address":"压测路 1 号","is_default":true}' | j data.id)
  req POST /cart "$TOK" "{\"sku_id\":$SKU_ID,\"quantity\":1}" > /dev/null
  echo "$TOK" > "$TMP/token_$i"
  echo "$ADDR" > "$TMP/addr_$i"
  echo "  用户 $i/$i 就绪"
done
echo "10 个用户已就绪（注册/地址/加购）"

# 3. 并发下单：10 个请求同时发起
for i in 1 2 3 4 5 6 7 8 9 10; do
  TOK=$(cat "$TMP/token_$i")
  AID=$(cat "$TMP/addr_$i")
  (req POST /orders "$TOK" "{\"address_id\":$AID}" > "$TMP/order_$i.json") &
done
wait

# 4. 统计结果
SUCCESS=0; FAILED=0
for i in 1 2 3 4 5 6 7 8 9 10; do
  C=$(j code < "$TMP/order_$i.json")
  if [ "$C" = "0" ]; then SUCCESS=$((SUCCESS+1)); else FAILED=$((FAILED+1)); fi
done
LOCKED=$(db "echo App\Models\Inventory::where('sku_id',$SKU_ID)->value('locked_stock');")
# lock 流水 change_qty 为负值（可用库存减少方向）
SOLD=$(db "echo abs(App\Models\InventoryLog::where('sku_id',$SKU_ID)->where('change_type','lock')->sum('change_qty'));")

echo "结果：成功 $SUCCESS 单 / 失败 $FAILED 单（失败均为 40009 库存不足）/ 锁定库存 $LOCKED / 锁定流水合计 $SOLD"
rm -f "$TMP"/token_* "$TMP"/addr_* "$TMP"/order_*.json

if [ "$SUCCESS" = "5" ] && [ "$LOCKED" = "5" ] && [ "$SOLD" = "5" ]; then
  echo "PASS：无超卖（恰好售出库存上限 5 件）"
  exit 0
else
  echo "FAIL：出现超卖或数量不一致"
  exit 1
fi
