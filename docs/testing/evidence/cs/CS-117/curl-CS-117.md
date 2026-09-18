# CS-117 接口通道 —— curl 逐步串链路

> 用途：无浏览器环境下可重复的端到端验证。等价自动化脚本见 `run-e2e-http.sh`，
> 输出见 `smoke-CS-117-http.txt`（28 PASS / 0 FAIL）。
>
> 前置：`php artisan serve` 运行于 `127.0.0.1:8000`，`APP_DEBUG=true`（验证码返回 `debug_code`），
> 已 migrate + seed `CsTicketTypeSeeder` / `CsFaqCategorySeeder`。

```bash
BASE=http://127.0.0.1:8000/api
J(){ python -c "import sys,json;d=json.loads(sys.stdin.buffer.read().decode('utf-8','replace'));print(eval('d'+sys.argv[1]))" "$1"; }
# 用法： echo "$RESP" | J "['data']['token']"
```

## 0. 认证

```bash
# 0.1 后台验证码 + 登录
CAP=$(curl -s -X POST $BASE/auth/captcha -H 'Content-Type: application/json' \
      -d '{"target":"admin","type":"login"}')
CID=$(echo "$CAP" | J "['data']['captcha_id']"); CCODE=$(echo "$CAP" | J "['data']['debug_code']")
ATOK=$(curl -s -X POST $BASE/auth/login -H 'Content-Type: application/json' \
      -d "{\"username\":\"admin\",\"password\":\"Admin@123\",\"captcha_id\":\"$CID\",\"captcha_code\":\"$CCODE\"}" \
      | J "['data']['token']")

# 0.2 买家注册（target 用用户名）
U="cse2e$(date +%s)"
CAP=$(curl -s -X POST $BASE/auth/captcha -H 'Content-Type: application/json' -d "{\"target\":\"$U\",\"type\":\"register\"}")
CID=$(echo "$CAP" | J "['data']['captcha_id']"); CCODE=$(echo "$CAP" | J "['data']['debug_code']")
TOK=$(curl -s -X POST $BASE/auth/register -H 'Content-Type: application/json' \
      -d "{\"username\":\"$U\",\"password\":\"Test@1234\",\"password_confirmation\":\"Test@1234\",\"code\":\"$CCODE\",\"captcha_id\":\"$CID\"}" \
      | J "['data']['token']")
echo "ATOK=${ATOK:0:12}... TOK=${TOK:0:12}..."
```
实测：两者均非空（HTTP 200，`code=0`）。

```bash
AUTH_B="Authorization: Bearer $TOK"; AUTH_A="Authorization: Bearer $ATOK"
JSON='Content-Type: application/json'
```

## 1. 服务中心首页：FAQ 分类

```bash
curl -s $BASE/cs/faq/categories -H "$AUTH_B" | J "['data']"
```
实测：5 个分类，每项含 `id / name / sort / published_count`。

## 2. 搜索 → 详情 → 「有帮助」

```bash
# 2.1 关键词搜索（未发布文章不可见；示例文章需先在后台发布）
curl -s "$BASE/cs/faq/articles?keyword=%E7%89%A9%E6%B5%81" -H "$AUTH_B" | J "['data']['list'][0]['id']"
# => 2

# 2.2 详情
curl -s $BASE/cs/faq/articles/2 -H "$AUTH_B" | J "['data']['article']['title']"
# => '下单后多久发货？如何查看物流？'
curl -s $BASE/cs/faq/articles/2 -H "$AUTH_B" | J "['data']['related']"
# => 同类其它已发布文章（已剔除自身）

# 2.3 提交「有帮助」
curl -s -X POST $BASE/cs/faq/articles/2/feedback -H "$AUTH_B" -H "$JSON" -d '{"helpful":true}' \
  | J "['data']['helpful_count']"
# => 递增（1 → 2 …）
```

后台发布（「后台维护即时生效」）：

```bash
curl -s -X POST $BASE/admin/cs/faq/articles/2/publish -H "$AUTH_A" | J "['data']['status']"
# => 'published'（再查用户端搜索即可命中）
```

## 3. 提单：物流类型 + 关联订单 + 2 张凭证

```bash
# 3.1 工单类型（取 logistics）
curl -s $BASE/cs/ticket-types -H "$AUTH_B" | J "[t['id'] for t in ['data'] for t in d['data'] if t['code']=='logistics'][0]" 2>/dev/null
# 简化：LOG_ID=$(curl -s $BASE/cs/ticket-types -H "$AUTH_B" | python -c "import sys,json;print(next(t['id'] for t in json.load(sys.stdin)['data'] if t['code']=='logistics'))")
# => 2

# 3.2 上传 2 张凭证（multipart）
curl -s -X POST $BASE/cs/upload-image -H "$AUTH_B" -F "image=@/path/v1.png" | J "['data']['url']"
# => http://localhost:8000/storage/uploads/cs/20260917/xxxx.png
# 重复一次拿第二个 URL

# 3.3 建单（order_id 必填，要求类型 require_order=true）
curl -s -X POST $BASE/cs/tickets -H "$AUTH_B" -H "$JSON" -d "{
  \"type_id\": $LOG_ID,
  \"title\": \"包裹一直没收到\",
  \"content\": \"下单三天了还没物流信息\",
  \"order_id\": $ORDER_ID,
  \"images\": [\"$U1\", \"$U2\"]
}" | J "['data']['ticket']['ticket_no']"
# => 'TK20260917000006'
```
实测：`201 Created`，`status=pending`，`ticket_no` 前缀 `TK`；未带 `order_id` 时 `422`。

## 4. 我的工单列表

```bash
curl -s "$BASE/cs/tickets?status=pending" -H "$AUTH_B" | J "['data']['list']"
```
实测：包含步骤 3 的工单号（`data.list[].ticket_no`）。

## 5. 后台工作台与详情

```bash
curl -s "$BASE/admin/cs/tickets?status=pending" -H "$AUTH_A" | J "['data']['meta']['pending_count']"
# => 1

curl -s $BASE/admin/cs/tickets/$TID -H "$AUTH_A" | J "['data']['ticket']['status']"
# => 'pending'
curl -s $BASE/admin/cs/tickets/$TID -H "$AUTH_A" | J "['data']['user_summary']"
curl -s $BASE/admin/cs/tickets/$TID -H "$AUTH_A" | J "['data']['order']['order_no']"
# => 关联订单摘要
curl -s $BASE/admin/cs/tickets/$TID -H "$AUTH_A" | J "['data']['actions']"
# => {'can_reply':.., 'can_complete':.., 'can_close':..}
```

## 6. 内部备注（用户端不可见、不通知）

```bash
curl -s -X POST $BASE/admin/cs/tickets/$TID/messages -H "$AUTH_A" -H "$JSON" \
  -d '{"content":"内部核查：仓库漏发","is_internal":true}' | J "['code']"     # => 0

# 用户端不可见（0 条 is_internal=true，且不含内部文本）
curl -s $BASE/cs/tickets/$TID -H "$AUTH_B" | J "[m for m in d['data']['ticket']['messages'] if m['is_internal']]"
# => []
# 状态不变
curl -s $BASE/cs/tickets/$TID -H "$AUTH_B" | J "['data']['ticket']['status']"   # => 'pending'
# 未通知买家
curl -s $BASE/me/notifications -H "$AUTH_B" | J "['data']['pagination']['total']" # => 0
```

## 7. 客服回复 → 自动流转 + 站内信

```bash
curl -s -X POST $BASE/admin/cs/tickets/$TID/messages -H "$AUTH_A" -H "$JSON" \
  -d '{"content":"您好，已联系仓库补发，预计明日发出"}' | J "['code']"   # => 0

curl -s $BASE/admin/cs/tickets/$TID -H "$AUTH_A" | J "['data']['ticket']['status']"
# => 'processing'（pending → processing 自动流转）
curl -s $BASE/admin/cs/tickets/$TID -H "$AUTH_A" | J "['data']['ticket']['first_replied_at']"
# => '2026-09-17T08:45:02.000000Z'
curl -s $BASE/me/notifications -H "$AUTH_B" | J "['data']['list'][0]['title']"
# => '客服回复了您的工单'（type=cs_ticket_reply）
```

## 8. 等待回复 → 用户回复回 processing

```bash
curl -s -X PUT $BASE/admin/cs/tickets/$TID/status -H "$AUTH_A" -H "$JSON" \
  -d '{"status":"waiting_user"}' | J "['data']['status']"        # => 'waiting_user'

curl -s -X POST $BASE/cs/tickets/$TID/messages -H "$AUTH_B" -H "$JSON" \
  -d '{"content":"好的，麻烦尽快，谢谢"}' | J "['code']"            # => 0

curl -s $BASE/admin/cs/tickets/$TID -H "$AUTH_A" | J "['data']['ticket']['status']"
# => 'processing'（waiting_user → processing 自动流转）
```

## 9. 完成

```bash
curl -s -X PUT $BASE/admin/cs/tickets/$TID/status -H "$AUTH_A" -H "$JSON" \
  -d '{"status":"completed"}' | J "['data']['status']"           # => 'completed'

curl -s $BASE/cs/tickets/$TID -H "$AUTH_B" | J "[m['sender_type'] for m in d['data']['ticket']['messages']]"
# => ['user','staff','user','system','system']（system = 等待回复 / 已完成）
```

## 10. 关闭与幂等

```bash
curl -s -X POST $BASE/cs/tickets/$TID/close -H "$AUTH_B" | J "['data']['status']"
# => 'closed'，close_reason='user'，closed_at 落库

curl -s -X POST $BASE/cs/tickets/$TID/close -H "$AUTH_B" | J "['code']"
# => 40009（409 Conflict，已关闭不可再流转）
```

## 附：负向断言速查

| 场景 | 期望 |
|------|------|
| 未登录访问 6 个用户端 CS 接口 | `401`，`code=40001` |
| 买家访问 `/admin/cs/*` | `403`，`code=40003` |
| 买家读他人工单 | `404` |
| 物流类型缺 `order_id` | `422` |
| 非法状态流转（如 `closed → processing`） | `409`，`code=40009` |
| 重复提交相同回复（5 秒内同发送方同文本） | 返回同一条消息，不重复落库 |

> 登录/验证码限流：登录 `5/min`；建单与回复 `10/min`。连测触发 `429` 时 `php artisan cache:clear` 并间隔数秒重试。
