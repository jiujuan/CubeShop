# CubeShop P7 回归测试报告

- 执行时间：2026-09-15 17:59:21
- 测试范围：docs/design/CubeShop_TestCases_v1.0.md 全部 P0 用例 + TC-SYS-002 抽样
- 测试环境：本地 PostgreSQL + Laravel 13.12（artisan serve，沙箱支付）
- 结果：**PASS 25 / FAIL 0**（通过率 100.0%）

| 结果 | 用例 | 说明 |
|---|---|---|
| PASS | TC-SYS-001 | 未登录访问返回 401 |
| PASS | TC-USER-001 | 注册成功 |
| PASS | TC-USER-002 | 重复注册被拒绝（code=40000） |
| PASS | TC-USER-004 | 登录成功获得 Token |
| PASS | TC-USER-005 | 密码错误被拒绝 |
| PASS | TC-USER-007 | 新增收货地址 id=1 |
| PASS | TC-PROD-001 | 商品列表 total=8 |
| PASS | TC-PROD-002 | 搜索「耳机」命中 |
| PASS | TC-PROD-004 | 商品详情含 SKU(sku1=1) |
| PASS | TC-CART-001 | 加购成功 sku1 x1 |
| PASS | TC-CART-002 | 超库存加购被拒绝（code=40009） |
| PASS | TC-CART-003 | 修改数量 1→3 |
| PASS | TC-CART-004 | 删除购物车商品（sku3） |
| PASS | TC-ORDER-001 | 下单成功 快照金额=297.00 锁库存 0→3 |
| PASS | TC-ORDER-002 | 无效地址下单被拒绝（code=40004） |
| PASS | TC-ORDER-003 | 沙箱支付成功 订单 paid paid_at=2026-09-15 17:58:31 |
| PASS | TC-ORDER-005 | 重复回调幂等（支付单仍 1 条） |
| PASS | TC-ORDER-004 | 支付失败后订单仍待支付且可重新支付 |
| PASS | TC-ORDER-006 | 取消待支付订单 库存释放 1→0 |
| PASS | TC-ORDER-008 | 列表筛选 paid=2 条，详情快照/明细完整 |
| PASS | TC-ORDER-010 | 超时自动取消 reason=超时未支付，系统自动取消 |
| PASS | TC-ADMIN-001 | 新增商品 id=9 |
| PASS | TC-ADMIN-002 | 编辑 + 上下架生效（下架不可见/上架可见） |
| PASS | TC-ADMIN-005 | 发货成功 shipped_at=2026-09-15 17:59:04 |
| PASS | TC-SYS-002 | 登录触发限流 429（第 5 次） |

## 本轮修复的缺陷（回归前发现并修复）

| 编号 | 严重级 | 缺陷 | 修复 |
|---|---|---|---|
| BUG-1 | 高 | 未认证/无权限/资源不存在/限流等异常 HTTP 状态码一律 200，与 API 约定不符 | bootstrap/app.php 异常渲染统一映射：40001→401、40003→403、40004→404、429→429、422 校验、500 系统错误（业务码不变） |
| BUG-2 | 高 | 限流异常落入 HttpExceptionInterface 分支且漏设 HTTP 状态码，限流返回 200+code 42900 | 该分支补 `$e->getStatusCode()` 作为 HTTP 状态，429 映射业务码 40009 |
| BUG-3 | 中 | 管理端部分更新商品（仅传 status 等字段）时 500：`Undefined array key "skus"` | update() 仅在提交 skus 时全量替换 SKU，否则保留原 SKU 并按现有 SKU 重算价格 |
| BUG-4 | 低 | 商品 SKU 编码唯一约束冲突返回 50000 系统错误 | 捕获唯一约束冲突，返回 40009「SKU 编码已存在」 |
