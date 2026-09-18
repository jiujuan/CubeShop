# Stage P8：京东云仓（ECLP）Adapter —— 冻结，待菜鸟验收通过后启动

**状态**：⏸ 冻结（未排期）
**工期**：约 2 周（**菜鸟 P0~P7 全部 ✅ 后才启动**）
**对应设计文档**：§8（京东 ECLP 字段映射）、§1.2（本期范围表）

---

## 1. 目标与功能（占位，正式开工前再细化）

按 P2～P4 沉淀的 Adapter 契约，复制一套京东实现：
- 宙斯鉴权：公共参数 `method/app_key/access_token/timestamp/sign/v/format`，签名规则独立实现
- 销售出库单创建 / 取消（§8.1/§8.2）、出库结果回传或主动查询（§8.3）
- 退货入库单创建（§8.4）与回传/查询（§8.5）
- 回调入口 `/api/wms/callback/jd-cloud`（与菜鸟同构，共用 `CallbackDeduplicator` 与 Handler 骨架）
- 常量：`WmsProvider::PROVIDER_JD_CLOUD` 已在 P0 预留；`owner_no/warehouse_no/access_token_enc` 字段已在 P0 表结构中预留

## 2. 依赖
- **前置**：P0~P7 全部完成（尤其 Adapter 契约、回调幂等、日志/脱敏基建）
- **外部**：京东宙斯 AppKey/AppSecret、OAuth `access_token` 获取与刷新流程、**云仓库房编号**

## 3. 开工前必须先做的三件事
1. 把 P2 的 `CainiaoAdapter` 抽象出「签名 + 网关 + 错误映射」三件套的可复用基类，避免重复代码
2. 明确 `access_token` 轮换机制（过期/刷新/失败重试），菜鸟无此负担，京东必须设计
3. 确认 ECLP 是否提供回调；若不提供或有延迟，退化为「主动查询补偿」为主链路（§8.3）

## 4. 测试与验收（框架）
- 单元测试：签名样例向量（官方工具校对）、字段映射、错误码映射、`access_token` 刷新
- 回归：菜鸟全链路不得受影响（双 provider 并存时 `WmsAdapterFactory` 路由正确）
- 集成：沙箱跑通出库 + 退货闭环（与 P7 同一份 UAT 用例清单）
- 验收清单：与 P7 同构，另加「双 provider 并存不串配置」专项

## 5. 完成情况（占位）
- [ ] 抽象基类重构完毕
- [ ] `access_token` 刷新机制设计与评审
- [ ] 京东 Adapter 四个接口实现
- [ ] 回调/主动查询链路打通
- [ ] 双 provider 并存专项测试
- [ ] 单元测试 / 回归 / 集成测试通过
- [ ] 验收清单全勾选

**解锁条件**：`README.md` §4 中 P0~P7 全部 ✅
