#!/usr/bin/env bash
# =============================================================
# CS-117 一期端到端冒烟 —— 接口通道（10 步）
# 前置：php artisan serve 运行于 127.0.0.1:8000；已 migrate + seed CsTicketTypeSeeder/CsFaqCategorySeeder
# 用法：bash docs/testing/evidence/cs/CS-117/run-e2e-http.sh
# 说明：脚本会新建 1 个测试买家、1 个测试商品、1 笔已支付订单与 1 张工单（开发库副作用）；
#       并会把 1 篇示例 FAQ 从 draft 发布为 published（验证「后台维护即时生效」）。
#       所有断言走 JSON 解析（Laravel 默认转义 Unicode，不能用 grep 匹配中文）。
# =============================================================
set -u
BASE="${BASE:-http://127.0.0.1:8000/api}"
PASS=0; FAIL=0

ok()  { echo "  [PASS] $1"; PASS=$((PASS+1)); }
bad() { echo "  [FAIL] $1"; FAIL=$((FAIL+1)); }

# JSON 取值：jpath data.ticket.status
# 注意：Windows 下 sys.stdin 默认按 cp936 解码，必须显式读 buffer 再按 UTF-8 解码，
#       否则含中文的响应会被解成乱码，导致中文比对静默失效。
jpath(){ python -c "
import sys,json
try: d=json.loads(sys.stdin.buffer.read().decode('utf-8','replace'))
except Exception: print(''); sys.exit()
cur=d
for k in '$1'.split('.'):
    if isinstance(cur,list): cur=cur[int(k)] if k.isdigit() and int(k)<len(cur) else None
    elif isinstance(cur,dict): cur=cur.get(k)
    else: cur=None
print(cur if cur is not None else '')" 2>/dev/null; }

# 数组统计：jcount <条件表达式（item 变量名固定为 m）> <json-path-to-list>
jcount(){ python -c "
import sys,json
try: d=json.loads(sys.stdin.buffer.read().decode('utf-8','replace'))
except Exception: print(0); sys.exit()
cur=d
for k in '$2'.split('.'):
    if isinstance(cur,dict): cur=cur.get(k)
    elif isinstance(cur,list) and k.isdigit(): cur=cur[int(k)]
    else: cur=None
items=cur if isinstance(cur,list) else (cur or {}).get('list',[]) if isinstance(cur,dict) else []
print(sum(1 for m in items if $1))" 2>/dev/null; }

req() { # method path token [json]
  local m=$1 p=$2 t=${3:-} d=${4:-}
  if [ -n "$t" ] && [ -n "$d" ]; then
    curl -s --noproxy '*' -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Content-Type: application/json' -d "$d"
  elif [ -n "$t" ]; then
    curl -s --noproxy '*' -X "$m" "$BASE$p" -H "Authorization: Bearer $t"
  elif [ -n "$d" ]; then
    curl -s --noproxy '*' -X "$m" "$BASE$p" -H 'Content-Type: application/json' -d "$d"
  else
    curl -s --noproxy '*' -X "$m" "$BASE$p"
  fi
}
upload() { curl -s --noproxy '*' -X POST "$BASE$1" -H "Authorization: Bearer $2" -F "image=@$3"; }

echo "== CS-117 端到端冒烟（接口通道）== base=$BASE"

echo "--- 0. 前置：后台登录 / 买家注册 / 已支付订单 / 发布 FAQ"
CAP=$(req POST /auth/captcha '' '{"target":"admin","type":"login"}')
ATOK=$(req POST /auth/login '' "{\"username\":\"admin\",\"password\":\"Admin@123\",\"captcha_id\":\"$(echo "$CAP"|jpath data.captcha_id)\",\"captcha_code\":\"$(echo "$CAP"|jpath data.debug_code)\"}" | jpath data.token)
[ -n "$ATOK" ] && ok "后台登录成功" || { bad "后台登录失败"; exit 1; }

U="cse2e$(date +%s)"
CAP=$(req POST /auth/captcha '' "{\"target\":\"$U\",\"type\":\"register\"}")
TOK=$(req POST /auth/register '' "{\"username\":\"$U\",\"password\":\"Test@1234\",\"password_confirmation\":\"Test@1234\",\"code\":\"$(echo "$CAP"|jpath data.debug_code)\",\"captcha_id\":\"$(echo "$CAP"|jpath data.captcha_id)\"}" | jpath data.token)
[ -n "$TOK" ] && ok "买家注册成功($U)" || { bad "买家注册失败"; exit 1; }

SKU="CSE2E-$(date +%s)"
PID=$(req POST /admin/products "$ATOK" "{\"category_id\":1,\"title\":\"CS冒烟商品\",\"status\":1,\"skus\":[{\"sku_code\":\"$SKU\",\"specs\":{\"规格\":\"标准\"},\"price\":20,\"stock\":5}]}" | jpath data.id)
SKU_ID=$(req GET "/admin/products/$PID" "$ATOK" | jpath data.skus.0.id)
ADDR=$(req POST /user/addresses "$TOK" '{"contact_name":"CS冒烟","contact_phone":"13800001234","province":"广东省","city":"深圳市","district":"南山区","detail_address":"冒烟路1号"}' | jpath data.id)
req POST /cart "$TOK" "{\"sku_id\":$SKU_ID,\"quantity\":1}" > /dev/null
ORD=$(req POST /orders "$TOK" "{\"address_id\":$ADDR,\"remark\":\"CS冒烟\"}")
ORDER_ID=$(echo "$ORD" | jpath data.order_id); ORDER_NO=$(echo "$ORD" | jpath data.order_no)
PAY_NO=$(req POST /payments "$TOK" "{\"order_no\":\"$ORDER_NO\",\"channel\":\"wechat\"}" | jpath data.payment_no)
req POST "/payments/sandbox/$PAY_NO" "$TOK" > /dev/null
OSTATUS=$(req GET "/orders/$ORDER_ID" "$TOK" | jpath data.status)
[ "$OSTATUS" = "pending_ship" ] && ok "已支付订单就绪(no=$ORDER_NO)" || { bad "订单状态异常: $OSTATUS"; exit 1; }

# 发布 FAQ 草稿（示例文章默认 draft）—— 顺带验证「后台维护即时生效」；幂等，无草稿则跳过
DRAFTS=$(req GET "/admin/cs/faq/articles?status=draft&per_page=50" "$ATOK")
DN=$(echo "$DRAFTS" | jcount "True" data.list)
if [ "${DN:-0}" -gt 0 ] 2>/dev/null; then
  for i in $(seq 0 $((DN-1))); do
    req POST "/admin/cs/faq/articles/$(echo "$DRAFTS" | jpath data.list.$i.id)/publish" "$ATOK" > /dev/null
  done
  ok "后台发布 $DN 篇 FAQ 草稿（后台维护即时生效）"
else
  ok "FAQ 无草稿（前次已发布，跳过）"
fi

TYPES=$(req GET /cs/ticket-types "$TOK")
LOG_ID=$(echo "$TYPES" | python -c "import sys,json;print(next((t['id'] for t in json.loads(sys.stdin.buffer.read().decode('utf-8','replace'))['data'] if t['code']=='logistics'),''))" 2>/dev/null)
[ -n "$LOG_ID" ] && ok "工单类型「物流问题」id=$LOG_ID" || { bad "未找到 logistics 类型"; exit 1; }

echo "--- 步骤 1：服务中心首页读 FAQ 分类"
CATN=$(req GET /cs/faq/categories "$TOK" | python -c "import sys,json;print(len(json.loads(sys.stdin.buffer.read().decode('utf-8','replace'))['data']))" 2>/dev/null)
[ "${CATN:-0}" -gt 0 ] 2>/dev/null && ok "分类数=$CATN" || bad "分类为空"

echo "--- 步骤 2：搜索关键词 → 命中文章 → 详情 → 提交「有帮助」"
ART=$(req GET "/cs/faq/articles?keyword=%E7%89%A9%E6%B5%81" "$TOK")
ART_ID=$(echo "$ART" | jpath data.list.0.id)
[ -n "$ART_ID" ] && ok "搜索命中文章 id=$ART_ID" || bad "搜索无结果"
DET=$(req GET "/cs/faq/articles/$ART_ID" "$TOK")
[ -n "$(echo "$DET" | jpath data.article.id)" ] && ok "详情可读" || bad "详情读取失败"
FB=$(req POST "/cs/faq/articles/$ART_ID/feedback" "$TOK" '{"helpful":true}')
[ "$(echo "$FB" | jpath code)" = "0" ] && ok "「有帮助」已记录(helpful_count=$(echo "$FB" | jpath data.helpful_count))" || bad "反馈失败"

echo "--- 步骤 3：联系客服 → 物流问题 → 关联订单 → 上传 2 张凭证 → 提交"
# 1×1 透明 PNG，写到系统临时目录（curl 为原生程序，须用 Windows 可解析路径）
IMG1=$(python -c "
import base64,os,tempfile
p=os.path.join(tempfile.gettempdir(),'cs_e2e_1.png')
open(p,'wb').write(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='))
print(p.replace(chr(92),'/'))" 2>/dev/null)
IMG2=$(python -c "
import base64,os,tempfile
p=os.path.join(tempfile.gettempdir(),'cs_e2e_2.png')
open(p,'wb').write(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='))
print(p.replace(chr(92),'/'))" 2>/dev/null)
U1=$(upload /cs/upload-image "$TOK" "$IMG1" | jpath data.url)
U2=$(upload /cs/upload-image "$TOK" "$IMG2" | jpath data.url)
[ -n "$U1" ] && [ -n "$U2" ] && ok "凭证上传 2 张（$U1）" || bad "凭证上传失败（img1=$IMG1 url1=$U1）"

TICKET=$(req POST /cs/tickets "$TOK" "{\"type_id\":$LOG_ID,\"title\":\"包裹一直没收到\",\"content\":\"下单三天了还没物流信息\",\"order_id\":$ORDER_ID,\"images\":[\"$U1\",\"$U2\"]}")
TID=$(echo "$TICKET" | jpath data.ticket.id)
TNO=$(echo "$TICKET" | jpath data.ticket.ticket_no)

echo "--- 步骤 4：工单号 / pending / 我的列表可见"
case "$TNO" in TK*) ok "工单号=$TNO" ;; *) bad "工单号异常: $TNO" ;; esac
[ "$(echo "$TICKET" | jpath data.ticket.status)" = "pending" ] && ok "初始状态=pending" || bad "初始状态异常"
MINE=$(req GET "/cs/tickets?status=pending" "$TOK")
[ "$(echo "$MINE" | jcount "m.get('ticket_no')=='$TNO'" data.list)" = "1" ] && ok "我的工单列表可见" || bad "列表不可见"

echo "--- 步骤 5：后台待处理数 + 打开工单"
PC=$(req GET "/admin/cs/tickets?status=pending" "$ATOK" | jpath data.meta.pending_count)
[ "${PC:-0}" -ge 1 ] 2>/dev/null && ok "待处理数=$PC" || bad "待处理数异常: $PC"
ADET=$(req GET "/admin/cs/tickets/$TID" "$ATOK")
[ "$(echo "$ADET" | jpath data.ticket.status)" = "pending" ] && ok "后台详情可打开（user_summary.id=$(echo "$ADET"|jpath data.user_summary.id)）" || bad "后台详情异常"

echo "--- 步骤 6：内部备注（用户端不可见、不通知）"
req POST "/admin/cs/tickets/$TID/messages" "$ATOK" '{"content":"内部核查：仓库漏发","is_internal":true}' > /dev/null
BVIEW=$(req GET "/cs/tickets/$TID" "$TOK")
[ "$(echo "$BVIEW" | jcount "m.get('is_internal')" data.ticket.messages)" = "0" ] \
  && ok "用户端无任何内部备注" || bad "内部备注泄漏到用户端"
[ "$(echo "$BVIEW" | jcount "'内部核查' in (m.get('content') or '')" data.ticket.messages)" = "0" ] \
  && ok "用户端消息文本不含内部内容" || bad "内部内容泄漏"
[ "$(echo "$BVIEW" | jpath data.ticket.status)" = "pending" ] && ok "内部备注不改状态" || bad "内部备注误改状态"
[ "$(req GET "/me/notifications" "$TOK" | jcount "'工单' in (m.get('title') or '')" data.list)" = "0" ] \
  && ok "内部备注未通知买家" || bad "内部备注误发通知"

echo "--- 步骤 7：客服回复 → pending→processing + first_replied_at + 站内信"
req POST "/admin/cs/tickets/$TID/messages" "$ATOK" '{"content":"您好，已联系仓库补发，预计明日发出"}' > /dev/null
ADET=$(req GET "/admin/cs/tickets/$TID" "$ATOK")
[ "$(echo "$ADET" | jpath data.ticket.status)" = "processing" ] && ok "状态 pending → processing" || bad "状态未流转: $(echo "$ADET"|jpath data.ticket.status)"
[ -n "$(echo "$ADET" | jpath data.ticket.first_replied_at)" ] && ok "first_replied_at 已写入($(echo "$ADET"|jpath data.ticket.first_replied_at))" || bad "first_replied_at 为空"
[ "$(req GET "/me/notifications" "$TOK" | jcount "'客服回复' in (m.get('title') or '')" data.list)" = "1" ] \
  && ok "买家收到站内信（cs_ticket_reply ×1）" || bad "买家未收到站内信"

echo "--- 步骤 8：置「等待用户回复」→ 用户追加回复 → 回 processing"
req PUT "/admin/cs/tickets/$TID/status" "$ATOK" '{"status":"waiting_user"}' > /dev/null
[ "$(req GET "/admin/cs/tickets/$TID" "$ATOK" | jpath data.ticket.status)" = "waiting_user" ] && ok "状态 → waiting_user" || bad "等待回复设置失败"
req POST "/cs/tickets/$TID/messages" "$TOK" '{"content":"好的，麻烦尽快，谢谢"}' > /dev/null
R=$(req GET "/admin/cs/tickets/$TID" "$ATOK" | jpath data.ticket.status)
[ "$R" = "processing" ] && ok "用户回复后 → processing" || bad "用户回复未触发流转(实际=$R)"

echo "--- 步骤 9：客服标记已完成 → 用户端可见完成 + 系统消息"
req PUT "/admin/cs/tickets/$TID/status" "$ATOK" '{"status":"completed"}' > /dev/null
BDET=$(req GET "/cs/tickets/$TID" "$TOK")
[ "$(echo "$BDET" | jpath data.ticket.status)" = "completed" ] && ok "状态 → completed" || bad "完成失败"
[ "$(echo "$BDET" | jcount "m.get('sender_type')=='system'" data.ticket.messages)" -ge 2 ] 2>/dev/null \
  && ok "用户端可见系统消息（$(echo "$BDET" | jcount "m.get('sender_type')=='system'" data.ticket.messages) 条）" || bad "无系统消息"

echo "--- 步骤 10：用户关闭 → closed → 再次关闭 40009"
C1=$(req POST "/cs/tickets/$TID/close" "$TOK")
[ "$(echo "$C1" | jpath data.status)" = "closed" ] && ok "关闭成功 → closed" || bad "关闭失败"
C2=$(req POST "/cs/tickets/$TID/close" "$TOK")
[ "$(echo "$C2" | jpath code)" = "40009" ] && ok "再次关闭 → 40009" || bad "重复关闭未拦截: $(echo "$C2" | head -c 100)"

echo
echo "== 结果：PASS=$PASS FAIL=$FAIL =="
[ "$FAIL" -eq 0 ] && echo "=> 端到端 10 步全过" || echo "=> 存在失败项"
exit "$FAIL"
