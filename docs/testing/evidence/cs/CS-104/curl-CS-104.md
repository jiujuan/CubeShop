# CS-104 用户端 FAQ 四接口 —— 请求/响应记录

> 完整可执行链路见 `../CS-117/curl-CS-117.md`（步骤 1~2）。此处记录本任务的四个接口口径。
> 环境：`php artisan serve` → `127.0.0.1:8000`，买家 token 经 `/auth/register` 获取。

| # | 方法 路径 | 入参 | 关键响应字段 | 实测 |
|---|-----------|------|--------------|------|
| 1 | `GET /api/cs/faq/categories` | — | `data[].{id,name,sort,published_count}` | 200，5 个分类；`published_count` 只计 `status=published` |
| 2 | `GET /api/cs/faq/articles` | `category_id?`、`keyword?`（≤50 字）、`page?`、`per_page?` | `data.list[]`、`data.pagination` | 200；`keyword=物流` → 命中 id=2 |
| 3 | `GET /api/cs/faq/articles/{id}` | — | `data.article`、`data.related` | 200；`related` 为同类其它已发布文章（已剔除自身） |
| 4 | `POST /api/cs/faq/articles/{id}/feedback` | `{helpful: bool}` | `data.{helpful_count,unhelpful_count}` | 200；重复提交累加 |

```bash
BASE=http://127.0.0.1:8000/api
AUTH="Authorization: Bearer $TOK"

curl -s $BASE/cs/faq/categories -H "$AUTH"
curl -s "$BASE/cs/faq/articles?keyword=%E7%89%A9%E6%B5%81&page=1&per_page=10" -H "$AUTH"
curl -s $BASE/cs/faq/articles/2 -H "$AUTH"
curl -s -X POST $BASE/cs/faq/articles/2/feedback -H "$AUTH" -H 'Content-Type: application/json' -d '{"helpful":true}'
```

## 边界与负向

| 场景 | 期望 | 依据 |
|------|------|------|
| 未登录访问四个接口 | 401（`code=40001`） | `CsPermissionMatrixTest` |
| `keyword` 超 50 字 | 422 | 控制器 `max:50` |
| 未发布（draft/offline）文章 | 用户端搜索与详情均不可见 | `FaqService::published()` 作用域 |
| 不存在的文章 id | 404 | — |
| `helpful` 非布尔 | 422 | 控制器 `required,boolean` |

## 浏览量并发核对

文章详情的 `view_count` 自增与 `helpful_count/unhelpful_count` 更新均在数据库侧完成
（`FaqService::detail()` / `feedback()`），不依赖应用层读改写，无「同一文章并发浏览丢计数」问题。
