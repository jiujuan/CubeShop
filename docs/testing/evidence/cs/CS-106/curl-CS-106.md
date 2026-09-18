# CS-106 用户端工单六接口 —— 请求/响应记录

> 完整可执行链路见 `../CS-117/curl-CS-117.md`（步骤 3~4、8、10）。
> 买家端 `sanctum` 为无状态守卫，`beforeEach` 经 `/auth/register` 取真实 token。

| # | 方法 路径 | 入参 | 关键响应 | 实测 |
|---|-----------|------|----------|------|
| 1 | `GET /api/cs/ticket-types` | — | `data[].{id,name,code,require_order}` | 200；只返回 `is_active=true`（实测 8 类，隐藏类不外泄） |
| 2 | `POST /api/cs/tickets` | `type_id`、`title`、`content`、`order_id?`、`images?`(≤9)、`contact?` | `data.ticket.{id,ticket_no,status}` | 201；`ticket_no` 形如 `TK20260917000006`，`status=pending` |
| 3 | `GET /api/cs/tickets` | `status?`(all/pending/processing/waiting_user/completed/closed)、`page?`、`per_page?`(≤50) | `data.list[]`、`data.pagination` | 200；仅本人工单 |
| 4 | `GET /api/cs/tickets/{id}` | — | `data.ticket`（含 `messages`）、`data.order` | 200；`messages` 已过滤 `is_internal` |
| 5 | `POST /api/cs/tickets/{id}/messages` | `{content?, images?}` | `data.ticket`（含 messages） | 200；`waiting_user` 下回复自动回 `processing` |
| 6 | `POST /api/cs/tickets/{id}/close` | — | `data.status=closed` | 200；重复关闭 → `409 / 40009` |

```bash
BASE=http://127.0.0.1:8000/api
AUTH="Authorization: Bearer $TOK"

curl -s $BASE/cs/ticket-types -H "$AUTH"
curl -s -X POST $BASE/cs/tickets -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"type_id":2,"title":"包裹一直没收到","content":"下单三天了还没物流信息","order_id":1}'
curl -s "$BASE/cs/tickets?status=pending" -H "$AUTH"
curl -s $BASE/cs/tickets/1 -H "$AUTH"
curl -s -X POST $BASE/cs/tickets/1/messages -H "$AUTH" -H 'Content-Type: application/json' -d '{"content":"好的，谢谢"}'
curl -s -X POST $BASE/cs/tickets/1/close -H "$AUTH"
```

## 越权与内部备注核对（AC-106）

| 场景 | 期望 | 实测 |
|------|------|------|
| 买家读他人工单详情 | 404 | ✅ `CsPermissionMatrixTest` |
| 买家调用 `/admin/cs/*` | 403（`code=40003`） | ✅ 同上 |
| 客服内部备注出现在用户端消息流 | **不出现** | ✅ 用户端 `is_internal=true` 计数 = 0，且消息文本不含内部内容（`smoke-CS-117-http.txt` 步骤 6） |
| 内部备注触发用户通知 | **不触发** | ✅ 写入内部备注后 `/me/notifications` 仍为 0 条 |
| 缺 `order_id` 的 `require_order` 类型 | 422 | ✅ 控制器在服务层校验前拦截 |
| 连续建单/回复超过 10 次/分钟 | 429 | 路由 `throttle:10,1` |
