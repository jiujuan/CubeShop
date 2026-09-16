# T-032 curl 验证（开发库 PostgreSQL `cubeshop`，API `http://127.0.0.1:8000/api`）

- 日期：2026-09-16
- 账号：`operator` / `Operator@123`（权限 `marketing.manage`）

| 场景 | 请求 | 结果 |
|---|---|---|
| 运营登录 | `POST /auth/login` | OK（拿到 token） |
| 创建固定面额券 | `POST /admin/coupons`（fixed/15 元/门槛 100/总量 50/限领 1/相对 10 天） | `code=0 创建成功` ✅ |
| 券列表 | `GET /admin/coupons?page_size=5` | `total=1` ✅ |
| 券统计 | `GET /admin/coupons/{id}/stats` | `total=50 issued=0 avail=50` ✅ |
| 停止发放 | `POST /admin/coupons/{id}/stop` | `code=0 已停止发放` ✅ |
| 创建满减活动 | `POST /admin/promotions`（梯度 100→10、300→40） | `code=0 创建成功` ✅ |
| 梯度非递增（反例） | `POST /admin/promotions`（300→10、100→40） | `code=40000 满减梯度必须按门槛从小到大排列` ✅ |

## 结论

营销管理接口（券 CRUD/停发/统计、满减创建/梯度校验）在开发库端到端可用；
运营账号（`operator`）已持有 `marketing.manage`，可自助发券与建活动。
