# CS-116 数据一致性核对（SQL）

> 结论：**通过**。下列 5 条核对在自动化用例
> `tests/Feature/CsConcurrencyTest.php::数据一致性核对：无孤儿消息、时间锚点无矛盾`
> 中已固化（断言全部为 0），此处同时给出可在开发库直接执行的等价 SQL。

## 0. 表结构速查（`2026_09_17_000038_create_cs_ticket_tables.php`）

| 表 | 关键列 |
|----|--------|
| `cs_ticket` | `ticket_no`(unique)、`user_id`、`type_id`、`order_id`、`status`、`assignee_id`、`first_replied_at`、`last_message_at`、`completed_at`、`closed_at`、`close_reason` |
| `cs_ticket_message` | `ticket_id`、`sender_type`(user/staff/system)、`sender_id`、`content`、`is_internal`、`created_at` |
| `cs_faq_category` | `name`、`sort`、`is_active` |
| `cs_faq_article` | `category_id`、`status`(draft/published/offline)、`published_at`、`is_hot` |

## 1. 孤儿消息（无对应工单）为 0

```sql
SELECT COUNT(*) AS orphan_messages
FROM cs_ticket_message m
LEFT JOIN cs_ticket t ON t.id = m.ticket_id
WHERE t.id IS NULL;
-- 期望：0
```

## 2. 终态时间锚点无矛盾

```sql
-- completed 必须写 completed_at
SELECT COUNT(*) AS completed_without_time
FROM cs_ticket WHERE status = 'completed' AND completed_at IS NULL;   -- 期望：0

-- closed 必须写 closed_at
SELECT COUNT(*) AS closed_without_time
FROM cs_ticket WHERE status = 'closed' AND closed_at IS NULL;         -- 期望：0
```

## 3. 首响锚点一致性（`first_replied_at` 只在首条客服非内部消息后写入）

```sql
-- 存在客服公开回复、但 first_replied_at 为空的工单
SELECT COUNT(*) AS reply_without_first_replied_at
FROM cs_ticket t
WHERE t.first_replied_at IS NULL
  AND EXISTS (
    SELECT 1 FROM cs_ticket_message m
    WHERE m.ticket_id = t.id
      AND m.sender_type = 'staff'
      AND m.is_internal = 0
  );
-- 期望：0
```

反向核对（有 `first_replied_at` 但无任何客服公开回复）也应为 0：

```sql
SELECT COUNT(*) AS anchor_without_reply
FROM cs_ticket t
WHERE t.first_replied_at IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM cs_ticket_message m
    WHERE m.ticket_id = t.id AND m.sender_type = 'staff' AND m.is_internal = 0
  );
-- 期望：0
```

## 4. 状态分布 × 消息数（人工巡检视图）

```sql
SELECT t.status,
       COUNT(*)                                   AS ticket_count,
       COALESCE(SUM(m.cnt), 0)                    AS message_count,
       SUM(CASE WHEN t.first_replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied_count
FROM cs_ticket t
LEFT JOIN (
    SELECT ticket_id, COUNT(*) AS cnt FROM cs_ticket_message GROUP BY ticket_id
) m ON m.ticket_id = t.id
GROUP BY t.status
ORDER BY t.status;
```

## 5. 内部备注不外泄（用户端不可见）

```sql
-- 统计内部备注条数（应只出现在客服后台视图）
SELECT COUNT(*) AS internal_notes FROM cs_ticket_message WHERE is_internal = 1;
```

服务层 `CsTicketService::messagesFor($ticket, $isStaff)` 对买家传入 `$isStaff = false`，
SQL 层面对买家的查询恒带 `is_internal = 0` 条件；第 6 步 E2E 冒烟（`smoke-CS-117.md`）
亦有硬断言。

## 6. 结论

| 核对项 | 期望 | 实测 |
|--------|------|------|
| 孤儿消息 | 0 | 0 |
| `completed` 无 `completed_at` | 0 | 0 |
| `closed` 无 `closed_at` | 0 | 0 |
| 有客服回复但无 `first_replied_at` | 0 | 0 |
| 有 `first_replied_at` 但无客服回复 | 0 | 0 |

> 说明：`CsConcurrencyTest` 中「数据一致性核对」用例以 `DB::table(...)` 直接执行上述
> ①②③ 三类核对（SQLite/PG 双库通用），断言全部为 0。真实并发写入的压测见
> `loadtest-CS-116.md`。
