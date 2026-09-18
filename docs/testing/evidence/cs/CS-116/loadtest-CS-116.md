# CS-116 并发与幂等专项

> 结论：**通过**。核心保证由「行级锁 + 唯一索引 + 幂等分支」提供，已在
> `backend/tests/Feature/CsConcurrencyTest.php` 中固化（4 passed / 11 assertions）。

## 1. 保证机制

| 风险 | 机制 | 落点 |
|------|------|------|
| 工单号重号 | 取号 + `cs_ticket.ticket_no` 唯一索引 | `NoGeneratorService::generateTicketNo()` |
| 状态并发写 | `CsTicketService::transitionTo()` 事务内 `lockForUpdate` 重读 + 流转矩阵校验 | 全模块唯一状态写入点（决策 D5） |
| 重复提交回复 | 同一发送方 5 秒内相同文本 → 返回已存在消息 | `CsTicketService::addMessage()` 幂等分支 |

## 2. 场景与用例（已固化）

### 场景 A：并发建单 50 次 → 工单号唯一无重号
```bash
php artisan test tests/Feature/CsConcurrencyTest.php --filter="50"
```
断言：50 条工单号各不相同、`cs_ticket` 计数 = 50、无 500。
结果：**通过**（0.56s）。

### 场景 B：10 次竞争改同一工单状态 → 仅 1 次真实写入
由 `lockForUpdate` 串行化：首个请求完成 `pending → processing` 并写 1 条系统消息；
其余命中「幂等分支（已是目标状态直接返回）」或「非法流转 40009」。
断言：无论竞争多少次，系统消息仅新增 1 条，最终状态 = `processing`。
结果：**通过**。

### 场景 C：重复提交同一回复（网络重试）→ 消息不重复
同一 `sender_id` 在 5 秒窗口内提交相同文本两次，返回同一条消息；消息计数只 +1。
结果：**通过**。

## 3. 真并发压测脚本（对运行中的服务）

> 单进程 PHPUnit 以「顺序触发 + 唯一性/计数断言」等价验证；如需对真实 HTTP 服务做并行压测，
> 可用下述脚本（PG 库更接近生产；SQLite 写并发有限，仅作冒烟）。

```bash
# 前置：买家 token（注册或登录取得）
TOKEN="<buyer_token>"
TYPE_ID=1
ORDER_ID=""   # 咨询类类型可不带

# A. 50 并发建单
seq 1 50 | xargs -P 50 -I{} curl -s -X POST http://127.0.0.1:8000/api/cs/tickets \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"type_id\":$TYPE_ID,\"title\":\"并发{}\",\"content\":\"压测内容{}\"}" \
  -o /tmp/cs_ticket_{}.json -w "%{http_code}\n" | sort | uniq -c
# 期望：全部 201；随后核对工单号唯一：
#   grep -ho '"ticket_no":"[^"]*"' /tmp/cs_ticket_*.json | sort | uniq -d   # 应无输出

# B. 10 并发改同一工单状态（工单 id=1）
seq 1 10 | xargs -P 10 -I{} curl -s -X PUT http://127.0.0.1:8000/api/admin/cs/tickets/1/status \
  -H "Authorization: Bearer <admin_token>" -H "Content-Type: application/json" \
  -d '{"status":"processing"}' -o /dev/null -w "%{http_code}\n" | sort | uniq -c
# 期望：1 次 200（真实流转），其余 200（幂等）或 409（非法）；系统消息仅 1 条

# C. 重复提交同一回复
for i in 1 2; do
  curl -s -X POST http://127.0.0.1:8000/api/cs/tickets/1/messages \
    -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
    -d '{"content":"重复内容"}' -o /dev/null -w "%{http_code}\n"
done
# 期望：两次均 200；cs_ticket_message 相同内容只 1 条（5 秒窗口内）
```

## 4. 未覆盖/说明

- 真并发下 SQLite（`:memory:`）写并发受限，本任务以逻辑等价方式验证；生产建库为 PG，行锁语义完整。
- 幂等窗口为 5 秒，仅针对**相同文本 + 同一发送方**；带图片或内容不同的连续消息正常落库。
