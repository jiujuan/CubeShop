# 地区字典服务（CubeShop 公共基础数据）

GB/T 2260 三级行政区划（省 → 市 → 区）的唯一数据源，供**后端 PHP、web 前台、admin 后台**三方共用，
避免各端各存一份导致的运费省 matching、地址回填、地市下拉口径不一致。

## 目录结构

```
shared/region-dict/
├── regions.json        ←【单一真源】唯一可手工改动的数据文件
├── tools/
│   ├── build_regions.py   联网重建 regions.json（上游 modood/Administrative-divisions-of-China）
│   └── sync.py            校验 + 构建 dist + 分发到各消费方
└── dist/                  构建产物（regions.tree.min.json / provinces.min.json / meta.json）
```

## 铁律

1. **只允许改 `shared/region-dict/regions.json`**，消费方的 `src/data/*.json` 与
   `backend/resources/data/regions.json` 都是产物，**禁止直接编辑**（下次同步会被覆盖）。
2. 任何一端**不得**引入第二份地区数据（含手写省市区数组、`Select` 里的硬编码列表）。

## 使用方法

改完真源后执行（任意系统，Python 3.9+）：

```bash
python shared/region-dict/tools/sync.py            # 校验 + 生成 dist + 分发
python shared/region-dict/tools/sync.py --check    # CI 用：只校验，缺失/漂移即退出码 1
```

分发目标（`sync.py` 的 `TREE_TARGETS` / `PROVINCE_TARGETS`，新增消费方在此登记）：

| 目标 | 内容 | 用途 |
| --- | --- | --- |
| `backend/resources/data/regions.json` | 三级全树 | `RegionService`（运费按省 code 匹配、地址解析） |
| `web/src/data/regions.tree.json` | 三级全树 | 收货地址省/市/区联动下拉 |
| `web/src/data/provinces.json` | 省级列表 | 仅选省的场景（轻量） |
| `admin/src/data/regions.tree.json` | 三级全树 | 后台代改地址的地区选择 |
| `admin/src/data/provinces.json` | 省级列表 | 运费模板 regions 省份多选 |

## 各端接入方式

**后端 PHP** —— `App\Services\Common\RegionService`（进程内缓存）：

```php
RegionService::tree();                    // 完整三级树
RegionService::provinces();               // 省级列表
RegionService::codeOfProvinceName('上海市'); // 省名 → '310000'
RegionService::provinceName('310000');    // code → 省名
RegionService::isValidProvinceCode($code);
```
外部服务可通过 `GET /api/regions`（全树）/ `GET /api/regions/provinces`（省级，ETag 强缓存）获取同一份数据。

**web / admin 前端** —— `@/lib/region`（两侧同构，动态 import 独立 chunk，按需加载）：

```ts
import { listProvinces, listCities, listDistricts } from '@/lib/region'

const provinces = await listProvinces()                       // 省
const cities    = await listCities('440000')                  // 某省的市
const districts = await listDistricts('440000', '440300')     // 某市的区
```

## 数据口径

- 34 个省级（含**中国台湾 710000 / 中国香港 810000 / 中国澳门 820000**，上游源不收录、本字典补齐）、342 市、3056 区；
- 省 / 市级 code 严格六位；区级允许九位街道码（如 `460400112`）；
- 直辖市等城市级的「市辖区 / 县」已归一为该省名（如北京市 → 北京市），避免用户看到「市辖区」。
