# T-037 券过期收敛与可用券过滤 证据

## 场景 A：coupons:expire 过期未用券置 expired

执行前状态分布：{"unused":2,"used":1}

expireOverdue 处理数 = 1（期望 1，仅过期未用那张）
执行后状态分布：{"expired":1,"unused":1,"used":1}

结论：未过期/已用券不受影响 ✅；过期未用券 → expired ✅

## 场景 B：可用券列表排除 expired 与 stopped 模板券

可用券(usable)数量 = 0（期望 0：仅有停发券）
不可用(unusable)原因分布：{"优惠券已停止使用":1}

结论：expired 与 stopped 模板券被排除在可用列表之外（移入 unusable，附原因）✅

## 场景 C：满减活动停发后不再被匹配

活动运行中 match → 满100减10（期望命中）
活动停发后 match → NULL（期望 NULL）
活动停发后 displayFor → NULL（期望 NULL）

结论：停发活动不再参与满减匹配 ✅

> 附：displayFor 命中示例（活动运行状态）
{"promotion_id":1,"name":"满100减10","scope":"all","scope_refs":[],"base_amount":150,"current_tier":{"min":100,"discount":10},"discount":10,"next_tier":null,"gap_to_next":0}
