# CS-107 工单图片上传与站内通知 —— 请求/响应记录

> 上传复用 `FileUploadService::uploadImage($file, 'cs')`；通知复用 `NotificationService`。
> 完整链路见 `../CS-117/curl-CS-117.md`（步骤 3 上传 2 张凭证、步骤 6~7 通知断言）。

## 1. 图片上传

| 项 | 值 |
|----|----|
| 接口 | `POST /api/cs/upload-image`（`auth:sanctum` + `throttle:30,1`） |
| 入参 | `image`（file，`image` 规则，≤5 MB） |
| 响应 | `{code:0, data:{url:"http://localhost:8000/storage/uploads/cs/<ymd>/<rand>.png"}}` |
| 落盘 | `storage/app/public/uploads/cs/Ymd/`，经 `php artisan storage:link` 暴露为 `/storage/...` |

```bash
curl -s -X POST $BASE/cs/upload-image -H "$AUTH" -F "image=@./proof.png"
# => {"code":0,"message":"ok","data":{"url":"http://localhost:8000/storage/uploads/cs/20260917/xxxx.png"}}
```

实测（`smoke-CS-117-http.txt` 步骤 3）：连传 2 张均 200，URL 前缀与目录正确；传入 `.txt` 返回 422；未登录返回 401。
凭证 URL 数组随建单/回复的 `images` 字段落库（≤9 张，超出 → 40000）。

## 2. 站内通知（notifications 表）

发送方统一走 `CsNotificationService`，异常一律降级不阻断主流程（`Log::warning` 兜底）。

| 触发 | 方法 | 收件人 | `receiver_type` | `type` | 标题 | link |
|------|------|--------|-----------------|--------|------|------|
| 建单 | `notifyNewTicket()` → `sendToRole('operator')` | 运营角色全部 `sys_user` | `admin` | `cs_ticket_new` | 新服务工单待处理 | `/cs/tickets/{id}` |
| 客服**公开**回复 | `notifyStaffReply()` | 工单 `user_id` | `customer` | `cs_ticket_reply` | 客服回复了您的工单 | `/service-center/tickets/{id}` |
| 状态变更 | `notifyStatusChanged()` | 工单 `user_id` | `customer` | `cs_ticket_status` | 工单状态更新 | 同上 |
| 客服**内部备注** | — | **不发送** | — | — | — | — |

```bash
# 买家查收（分页 data.list）
curl -s $BASE/me/notifications -H "$AUTH"
```

实测（`sql-CS-107.md` 为真实表记录；`smoke-CS-117-http.txt` 步骤 6~7 为接口断言）：

| 断言 | 结果 |
|------|------|
| 建单后出现 `cs_ticket_new`（收件人 `admin`） | ✅ |
| 内部备注后买家通知数仍为 0 | ✅（**AC-107.3 硬断言**） |
| 客服公开回复后买家收到 1 条 `cs_ticket_reply` | ✅ |
| 状态变更后买家收到 `cs_ticket_status` | ✅ |

> 已知不一致（见 `acceptance-CS-117.md` 未解决问题 #3）：`notifyNewTicket` 发给 `operator` 角色，
> 但 `RolePermissionSeeder` 未给 `operator` 任何 `cs.*` 权限（迁移 `..._000039` 却授予了全部 3 个），
> 导致全新安装环境下运营收到通知却打不开工单。
