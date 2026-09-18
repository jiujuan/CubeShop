# CS-101 客服中心核心五表 —— 建表字段 / 索引核对

迁移：`database/migrations/2026_09_17_000038_create_cs_ticket_tables.php`
实测环境：PostgreSQL `cubeshop`（`php artisan migrate:status` 输出见 `migrate-status-CS-101.txt`）

## 1. 五张表

| 表 | 用途 |
|----|------|
| `cs_ticket_type` | 工单类型（含 `require_order` 是否必须关联订单） |
| `cs_ticket` | 工单主表（状态、处理人、时间锚点、关闭原因） |
| `cs_ticket_message` | 工单消息（`sender_type` user/staff/system，`is_internal` 内部备注） |
| `cs_faq_category` | 帮助中心分类 |
| `cs_faq_article` | 帮助中心文章（draft/published/offline） |

## 2. 索引清单（PG `pg_indexes` 实测）

```sql
select tablename, indexname from pg_indexes where tablename like 'cs_%' order by tablename, indexname;
```

```
cs_faq_article   | cs_faq_article_category_id_status_sort_index
cs_faq_article   | cs_faq_article_pkey
cs_faq_article   | cs_faq_article_status_is_hot_index
cs_faq_category  | cs_faq_category_is_active_sort_index
cs_faq_category  | cs_faq_category_pkey
cs_ticket        | cs_ticket_assignee_id_status_index
cs_ticket        | cs_ticket_order_id_index
cs_ticket        | cs_ticket_pkey
cs_ticket        | cs_ticket_status_created_at_index
cs_ticket        | cs_ticket_ticket_no_unique        ← 唯一（CS-116 并发不变量）
cs_ticket        | cs_ticket_type_id_index
cs_ticket        | cs_ticket_user_id_status_index
cs_ticket_message| cs_ticket_message_pkey
cs_ticket_message| cs_ticket_message_ticket_id_created_at_index
cs_ticket_message| cs_ticket_message_ticket_id_is_internal_index   ← 内部备注过滤
cs_ticket_type   | cs_ticket_type_code_unique
cs_ticket_type   | cs_ticket_type_is_active_sort_index
cs_ticket_type   | cs_ticket_type_pkey
```

## 3. `cs_ticket` 字段核对

| 字段 | 类型/约束 | 说明 |
|------|-----------|------|
| `ticket_no` | varchar(32) UNIQUE | `TK` + ymd + 6 位序列 |
| `user_id` | unsignedBigInteger | 买家 `users.id` |
| `type_id` | unsignedBigInteger | 工单类型 |
| `order_id` | nullable | 关联订单（`require_order=true` 的类型必填） |
| `title` / `content` | varchar(128) / text | 标题 / 描述 |
| `status` | varchar(16) default `pending` | 5 态 + 关闭 |
| `priority` | int | 0 普通 / 1 紧急…（`priorityLabel()`） |
| `assignee_id` | nullable | 处理客服 `sys_user.id` |
| `contact` | varchar(64) nullable | 联系方式 |
| `first_replied_at` | timestamp nullable | 首条**非内部**客服消息时间（首响锚点） |
| `last_message_at` | timestamp nullable | 最后消息时间 |
| `completed_at` / `closed_at` | timestamp nullable | 终态时间锚点 |
| `close_reason` | varchar(32) nullable | user/staff/system/timeout |

## 4. 断言化验证

`tests/Feature/CsSchemaTest.php`（5 passed / 43 assertions）固化本文件的要点：

```bash
php artisan test tests/Feature/CsSchemaTest.php --no-coverage
```

| 用例 | 断言 |
|------|------|
| 核心五表齐全 | 5 张表均存在 |
| `cs_ticket` 关键字段与默认值 | 18 个字段存在 |
| `cs_ticket_message` 关键字段 | 7 个字段存在 |
| `cs_faq_article` 关键字段与状态默认 draft | 11 个字段 + 默认值 |
| `ticket_no` 唯一索引生效 | 重号写入抛 `QueryException` |

> 双库结果一致：SQLite `pest-CS-101.txt` 与 PG `pest-pgsql-CS-101.txt` 均为 5 passed。
