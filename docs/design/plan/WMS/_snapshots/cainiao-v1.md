# 菜鸟奇门接口报文快照（v1）

> 用途：官方接口可能改版，本文件作为**当前对接基线**留档。每次菜鸟侧变更都在此追加新版本，禁止直接修改历史段落。
> 创建日期：2026-09-19（P2 开工后由真实报文回填）

## 1. deliveryorder.create（创建出库单）

版本：待填写（接口版本号 / 网关地址）
签名算法：待填写（以官方工具校验为准）

### 请求样例
```json
{
  "deliveryOrderCode": "FO202609190001",
  "orderCode": "CS20260919xxxxxxxx",
  "orderType": "JYCK",
  "warehouseCode": "CN_WH_SH_01",
  "ownerCode": "CUSTOMER_001",
  "receiverInfo": {
    "name": "张三",
    "mobile": "138****8000",
    "province": "浙江省",
    "city": "杭州市",
    "area": "余杭区",
    "detailAddress": "文一西路 xxx 号",
    "zipCode": "311100"
  },
  "orderLines": [
    {
      "orderLineNo": "1",
      "ownerCode": "CUSTOMER_001",
      "itemCode": "CN-SKU-001",
      "itemName": "示例商品",
      "planQty": 2,
      "barCode": "6901234567890"
    }
  ],
  "sourcePlatformCode": "OTHER",
  "remark": "请轻拿轻放"
}
```

### 响应样例（成功）
```json
{ "flag": "success", "code": "0", "message": "success", "deliveryOrderId": "DO1234567890" }
```

### 已知业务码
| code | 含义 | 平台处理 |
|------|------|----------|
| 待填 | 单据已存在 | 判定为幂等成功（不开新单） |
| 待填 | 货品不存在/未映射 | `Exception`，提醒配置 SKU 映射 |
| 待填 | 库存不足 | `Exception`，记录缺货明细 |

## 2. deliveryorder.confirm（发货回传）

### 平台关注的字段
`deliveryOrderCode / deliveryOrderId / expressCode / logisticsCode / logisticsName / orderConfirmTime / orderLines[].actualQty / packages[]`

### 样例待补（P3 联调回填）

## 3. returnorder.create（创建退货入库单）
样例待补（P4 联调回填）

## 4. returnorder.confirm（收货回传）
样例待补（P4 联调回填）

## 5. inventory.query（库存查询）
样例待补（P5 联调回填）

---

## 变更记录
| 日期 | 版本 | 变更内容 | 影响范围 |
|------|------|----------|----------|
| 2026-09-19 | v1 | 建立文件，待联调回填 | — |
