# CS-117 一期端到端冒烟（10 步）

> 结论：**接口通道 10 步全过**（28/28 断言）。自动化等价用例
> `backend/tests/Feature/CsE2eSmokeTest.php` 5 passed（59 assertions）；
> 真实服务脚本 `run-e2e-http.sh` 28 PASS / 0 FAIL（输出见 `smoke-CS-117-http.txt`）。

## 1. 前置环境

| 项 | 值 |
|----|----|
| 后端 | `php artisan serve` → `http://127.0.0.1:8000`（`APP_DEBUG=true`，验证码返回 `debug_code`） |
| 数据库 | 开发库 PG `cubeshop`；已执行 `2026_09_17_000038_create_cs_ticket_tables`、`..._000039_add_cs_permissions` |
| 基础数据 | `CsTicketTypeSeeder`（8 类）、`CsFaqCategorySeeder`（5 类 5 篇，示例文章默认 `draft`） |
| 账号 | 后台 `admin` / `Admin@123`；买家由脚本注册 |
| 执行 | `bash docs/testing/evidence/cs/CS-117/run-e2e-http.sh`（幂等，可重复执行） |

脚本附带的前置动作（真实接口，非 DB 造数）：注册买家 → 后台建商品 → 加购 → 下单 → 沙箱支付（`pending_ship`）
→ 后台发布全部 FAQ 草稿（验证「后台维护即时生效」）。

## 2. 十步步骤与实测结果

| 步 | 操作 | 断言 | 实测 |
|----|------|------|------|
| 1 | 买家读服务中心 FAQ 分类 | 分类非空 | ✅ 分类数=5 |
| 2 | 关键词「物流」搜索 → 详情 → 提交「有帮助」 | 命中文章 / 详情可读 / `helpful_count` +1 | ✅ id=2；`helpful_count=2`（累计） |
| 3 | 选「物流问题」→ 关联订单 → 上传 2 张凭证 → 提交 | 上传返回 URL；建单 201 | ✅ 2 张凭证；`/storage/uploads/cs/20260917/....png` |
| 4 | 工单落地校验 | 工单号 `TK` 前缀 / 状态 `pending` / 我的列表可见 | ✅ `TK20260917000006`、`pending`、列表命中 |
| 5 | 客服登录 → 工作台待处理数 → 打开工单 | `meta.pending_count ≥ 1`；详情可读 | ✅ `pending_count=1`；`user_summary.id=572` |
| 6 | 客服写内部备注（`is_internal=true`） | 用户端 0 条内部备注、无内部文本、状态不变、**未通知买家** | ✅ 4 项全过（`/me/notifications` 仍为 0 条） |
| 7 | 客服公开回复 | `pending → processing`、`first_replied_at` 落库、买家收到站内信 | ✅ `2026-09-17T08:45:02Z`；`cs_ticket_reply ×1` |
| 8 | 置「等待用户回复」→ 买家追加回复 | `waiting_user`；买家回复后回 `processing` | ✅ 两次流转均符合矩阵 |
| 9 | 客服标记「已完成」 | `completed`；用户端可见系统消息 | ✅ 系统消息 2 条（等待回复 + 已完成） |
| 10 | 买家关闭 → 再次关闭 | `closed`；重复关闭 `40009` | ✅ `close_reason=user`；复关 `409 / 40009` |

**结果：PASS=28 FAIL=0 → 端到端 10 步全过。**

### 断言口径修正记录（本轮发现并修复）

Bash 侧 `python -c` 在本机（Windows）有两个静默假信号，已修正后重跑：

1. **stdin 编码**：`json.load(sys.stdin)` 默认按 cp936 解码管道里的 UTF-8 字节 → 中文变乱码，中文子串比对恒为 False。
   改为 `json.loads(sys.stdin.buffer.read().decode('utf-8','replace'))`。
2. **变量名笔误**：统计条件的生成器变量为 `m`，两处误写 `n` → `NameError` 被 `2>/dev/null` 吞掉，输出为空。
   修正为 `m`。

> 二者都属「测试脚本自身的假信号」，非产品缺陷：修正前后业务接口返回一致，`/me/notifications` 在内部备注后始终为空。

## 3. 浏览器通道（人工 / agent-browser）

| 步骤 | 页面 | 预期 |
|------|------|------|
| 1 | `web` `/service-center` | 三栏 / 快捷工具 / 热门问题正常渲染 |
| 2 | `/service-center/faq?keyword=物流` | 命中高亮、点入详情 |
| 3 | `/service-center/faq/:id` | 正文纯文本渲染、`<script>` 不执行、反馈按钮置灰 |
| 4 | `/service-center/tickets/new?order_id=` | 类型联动必填订单、凭证 ≤9 张、联系方式带出 |
| 5 | `/service-center/tickets` | 状态 Tab 切换、分页加载更多 |
| 6 | `/service-center/tickets/:id` | 气泡区分 user/staff/system、内部备注不可见、已关闭隐藏输入区 |

后台通道（`admin` 5173，账号 `admin`/`Admin@123`）：
侧栏「服务工单」→ 列表红点待处理数 → 抽屉详情（用户摘要 / 订单卡片 / 消息流含内部备注 / 操作区）
→ 「帮助中心」分类与文章 CRUD、发布 / 下架 / 预览。

> **实现状态**：浏览器自动化截图**未在本轮产出**（本机 `web` 开发服务 `:3000` 返回 502，无可用浏览器会话），
> 已列为 `acceptance-CS-117.md` 未解决问题 #1，需人工补 `ui-CS-117-e2e-*.png`(6) 与 `ui-CS-117-mobile-*.png`(3)。
> 接口通道与自动化用例已覆盖同等业务断言，不阻塞 G1 的功能判定。

## 4. H5 移动端（375×812）检查项

| 页面 | 检查点 | 状态 |
|------|--------|------|
| 服务中心首页 | 无横向滚动；快捷工具 3 宫格单列/两列可点 | 待人工（见未解决问题 #1） |
| 工单列表 | Tab 可横向滑动；卡片不溢出 | 待人工 |
| 工单详情 | 输入区不被键盘遮挡；图片凭证可缩略 | 待人工 |

## 5. 证据清单

| 文件 | 说明 |
|------|------|
| `run-e2e-http.sh` | 10 步接口通道脚本（幂等可重复执行） |
| `smoke-CS-117-http.txt` | 本次执行输出（28 PASS / 0 FAIL） |
| `pest-CS-117-e2e.txt` | 自动化等价用例输出（5 passed / 59 assertions） |
| `curl-CS-117.md` | 逐步 curl 命令与响应摘要 |
| `pest-CS-117.txt` | R1~R7 回归输出 |
