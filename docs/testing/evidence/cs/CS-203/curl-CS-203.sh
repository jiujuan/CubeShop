#!/usr/bin/env bash
# CS-203 证据脚本：快捷回复模板接口 —— HTTP 端到端
#
# 前置：后端已 `php artisan serve`（默认 8000）、开发库 PG `cubeshop`。
# 用法：bash curl-CS-203.sh            # 产出 curl-CS-203.md
set -u

API="${API:-http://127.0.0.1:8000/api}"
BACKEND="${BACKEND:-D:/codeproject/PHP/CubeShop/backend}"
OUT="${OUT:-curl-CS-203.md}"
PHP="${PHP:-php}"
# 本机取证绕过代理：用环境变量而非 `--noproxy *`
export no_proxy='*'
export NO_PROXY='*'
CURL="curl -s"

jpath () {
  python -c "
import sys, json
d = json.load(sys.stdin)
for k in sys.argv[1].split('.'):
    d = d[int(k)] if isinstance(d, list) else (d.get(k) if isinstance(d, dict) else None)
print('' if d is None else d)
" "$1"
}

cd "$(dirname "$0")" || exit 1

echo "== 1. 后台登录（admin / 含 cs.faq.manage）=="
CAP=$($CURL -X POST "$API/auth/captcha" -H 'Content-Type: application/json' -d '{"target":"admin","type":"login"}')
ATOK=$($CURL -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d "{\"username\":\"admin\",\"password\":\"Admin@123\",\"captcha_id\":\"$(echo "$CAP" | jpath data.captcha_id)\",\"captcha_code\":\"$(echo "$CAP" | jpath data.debug_code)\"}" | jpath data.token)
echo "   admin token: ${ATOK:0:12}…"

echo "== 2. operator 登录（无 cs.* 权限，预期 403）=="
OCAP=$($CURL -X POST "$API/auth/captcha" -H 'Content-Type: application/json' -d '{"target":"admin","type":"login"}')
OTOK=$($CURL -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d "{\"username\":\"operator\",\"password\":\"Operator@123\",\"captcha_id\":\"$(echo "$OCAP" | jpath data.captcha_id)\",\"captcha_code\":\"$(echo "$OCAP" | jpath data.debug_code)\"}" | jpath data.token)
echo "   operator token: ${OTOK:0:12}…"

AUTH="Authorization: Bearer $ATOK"
OAUTH="Authorization: Bearer $OTOK"

# 准备一个工单类型用于 type_id 过滤
TYPE_ID=$(cd "$BACKEND" && $PHP artisan tinker --execute="echo (int) (App\Models\CsTicketType::first()?->id ?? App\Models\CsTicketType::create(['name'=>'售前','code'=>'pre','is_active'=>true,'sort'=>1])->id);" 2>/dev/null | tr -d "\r")
echo "   工单类型 id=$TYPE_ID"

{
echo "# CS-203 快捷回复模板接口 —— HTTP 端到端取证"
echo
echo "> 后端 \`php artisan serve\` @ $API，开发库 PG \`cubeshop\`。以下为真实响应快照（token 已截断）。"
echo
echo "## 1. 创建通用模板（POST，预期 201）"
CREATE=$($CURL -X POST "$API/admin/cs/quick-replies" -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"title":"通用开场","content":"您好，很高兴为您服务","type_id":null,"sort":1}')
echo '```json'
echo "$CREATE" | python -c "import sys,json;d=json.load(sys.stdin);print(json.dumps({'code':d.get('code'),'data':{'id':d.get('data',{}).get('id'),'title':d.get('data',{}).get('title'),'type_id':d.get('data',{}).get('type_id'),'sort':d.get('data',{}).get('sort'),'created_by':d.get('data',{}).get('created_by')}},ensure_ascii=False,indent=2))"
echo '```'
CID=$(echo "$CREATE" | jpath data.id)
echo

echo "## 2. 创建类型专属模板（POST，预期 201）"
CREATE2=$($CURL -X POST "$API/admin/cs/quick-replies" -H "$AUTH" -H 'Content-Type: application/json' \
  -d "{\"title\":\"售后专属\",\"content\":\"您的订单正在处理中\",\"type_id\":$TYPE_ID,\"sort\":2}")
echo '```json'
echo "$CREATE2" | python -c "import sys,json;d=json.load(sys.stdin);print(json.dumps({'code':d.get('code'),'data':{'id':d.get('data',{}).get('id'),'title':d.get('data',{}).get('title'),'type_id':d.get('data',{}).get('type_id'),'type_name':d.get('data',{}).get('type_name')}},ensure_ascii=False,indent=2))"
echo '```'
TID=$(echo "$CREATE2" | jpath data.id)
echo

echo "## 3. 管理列表（GET，预期 200，全量 + type_name）"
LIST=$($CURL "$API/admin/cs/quick-replies" -H "$AUTH")
echo '```json'
echo "$LIST" | python -c "import sys,json;d=json.load(sys.stdin);print(json.dumps({'code':d.get('code'),'count':len(d.get('data',[])),'titles':[x.get('title') for x in d.get('data',[])],'type_names':[x.get('type_name') for x in d.get('data',[])]},ensure_ascii=False,indent=2))"
echo '```'
echo

echo "## 4. 工作台下拉（GET ?type_id=，预期返回通用 + 该类型专属）"
WS=$($CURL "$API/admin/cs/quick-replies?type_id=$TYPE_ID" -H "$AUTH")
echo '```json'
echo "$WS" | python -c "import sys,json;d=json.load(sys.stdin);print(json.dumps({'code':d.get('code'),'count':len(d.get('data',[])),'titles':[x.get('title') for x in d.get('data',[])]},ensure_ascii=False,indent=2))"
echo '```'
echo

echo "## 5. 编辑模板（PUT，预期 200，content 保持不变）"
EDIT=$($CURL -X PUT "$API/admin/cs/quick-replies/$CID" -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"title":"通用开场(改)","sort":9}')
echo '```json'
echo "$EDIT" | python -c "import sys,json;d=json.load(sys.stdin);print(json.dumps({'code':d.get('code'),'data':{'title':d.get('data',{}).get('title'),'sort':d.get('data',{}).get('sort'),'content':d.get('data',{}).get('content'),'updated_by':d.get('data',{}).get('updated_by')}},ensure_ascii=False,indent=2))"
echo '```'
echo

echo "## 6. 非法 type_id（POST，预期 422）"
BAD=$($CURL -X POST "$API/admin/cs/quick-replies" -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"title":"x","content":"y","type_id":999999}')
echo '```json'
echo "$BAD" | python -c "import sys,json;d=json.load(sys.stdin);print(json.dumps({'code':d.get('code'),'http':d.get('code') and '见状态码'},ensure_ascii=False))" 2>/dev/null
echo "HTTP 状态码：$($CURL -o /dev/null -w '%{http_code}' -X POST "$API/admin/cs/quick-replies" -H "$AUTH" -H 'Content-Type: application/json' -d '{"title":"x","content":"y","type_id":999999}')"
echo '```'
echo

echo "## 7. 内容超长（POST，预期 422）"
LONG=$(python -c "print('长'*2001)")
CLONG=$($CURL -o /dev/null -w '%{http_code}' -X POST "$API/admin/cs/quick-replies" -H "$AUTH" -H 'Content-Type: application/json' \
  -d "{\"title\":\"x\",\"content\":\"$LONG\"}")
echo "HTTP 状态码：$CLONG"
echo

echo "## 8. operator 无权限（GET / POST，预期 403）"
G403=$($CURL -o /dev/null -w '%{http_code}' "$API/admin/cs/quick-replies" -H "$OAUTH")
P403=$($CURL -o /dev/null -w '%{http_code}' -X POST "$API/admin/cs/quick-replies" -H "$OAUTH" -H 'Content-Type: application/json' -d '{"title":"x","content":"y"}')
echo "GET=$G403 POST=$P403"
echo

echo "## 9. 删除模板（DELETE，预期 200）"
DEL=$($CURL -o /dev/null -w '%{http_code}' -X DELETE "$API/admin/cs/quick-replies/$TID" -H "$AUTH")
echo "DELETE $TID => HTTP $DEL"
echo

echo "## 断言汇总"
PASS=0; FAIL=0
chk(){ if [ "$2" = "$3" ]; then echo "✅ $1 ($2)"; PASS=$((PASS+1)); else echo "❌ $1 期望 $3 实际 $2"; FAIL=$((FAIL+1)); fi; }
chk "创建通用模板 201"  "$($CURL -o /dev/null -w '%{http_code}' -X POST "$API/admin/cs/quick-replies" -H "$AUTH" -H 'Content-Type: application/json' -d '{"title":"临时","content":"c","type_id":null}')" "201"
chk "管理列表 200"      "$($CURL -o /dev/null -w '%{http_code}' "$API/admin/cs/quick-replies" -H "$AUTH")" "200"
chk "工作台下拉 200"    "$($CURL -o /dev/null -w '%{http_code}' "$API/admin/cs/quick-replies?type_id=$TYPE_ID" -H "$AUTH")" "200"
chk "非法 type_id 422"  "$($CURL -o /dev/null -w '%{http_code}' -X POST "$API/admin/cs/quick-replies" -H "$AUTH" -H 'Content-Type: application/json' -d '{"title":"x","content":"y","type_id":999999}')" "422"
chk "内容超长 422"      "$CLONG" "422"
chk "operator GET 403"  "$G403" "403"
chk "operator POST 403" "$P403" "403"
chk "删除 200"          "$DEL" "200"
echo
echo "**结果：$PASS 通过 / $FAIL 失败**"
} > "$OUT"

echo "证据已写入 $OUT"
