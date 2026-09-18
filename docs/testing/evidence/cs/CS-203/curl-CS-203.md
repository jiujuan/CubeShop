# CS-203 快捷回复模板接口 —— HTTP 端到端取证

> 后端 `php artisan serve` @ http://127.0.0.1:8000/api，开发库 PG `cubeshop`。以下为真实响应快照（token 已截断）。

## 1. 创建通用模板（POST，预期 201）
```json
{
  "code": 0,
  "data": {
    "id": 1,
    "title": "通用开场",
    "type_id": null,
    "sort": 1,
    "created_by": 1
  }
}
```

## 2. 创建类型专属模板（POST，预期 201）
```json
{
  "code": 0,
  "data": {
    "id": 2,
    "title": "售后专属",
    "type_id": 1,
    "type_name": null
  }
}
```

## 3. 管理列表（GET，预期 200，全量 + type_name）
```json
{
  "code": 0,
  "count": 2,
  "titles": [
    "通用开场",
    "售后专属"
  ],
  "type_names": [
    null,
    "售前咨询"
  ]
}
```

## 4. 工作台下拉（GET ?type_id=，预期返回通用 + 该类型专属）
```json
{
  "code": 0,
  "count": 2,
  "titles": [
    "通用开场",
    "售后专属"
  ]
}
```

## 5. 编辑模板（PUT，预期 200，content 保持不变）
```json
{
  "code": 0,
  "data": {
    "title": "通用开场(改)",
    "sort": 9,
    "content": "您好，很高兴为您服务",
    "updated_by": 1
  }
}
```

## 6. 非法 type_id（POST，预期 422）
```json
{"code": 40000, "http": "见状态码"}
HTTP 状态码：422
```

## 7. 内容超长（POST，预期 422）
HTTP 状态码：422

## 8. operator 无权限（GET / POST，预期 403）
GET=403 POST=403

## 9. 删除模板（DELETE，预期 200）
DELETE 2 => HTTP 200

## 断言汇总
✅ 创建通用模板 201 (201)
✅ 管理列表 200 (200)
✅ 工作台下拉 200 (200)
✅ 非法 type_id 422 (422)
✅ 内容超长 422 (422)
✅ operator GET 403 (403)
✅ operator POST 403 (403)
✅ 删除 200 (200)

**结果：8 通过 / 0 失败**
