"""生成 backend/resources/data/regions.json（GB/T 2260 省市区三级）。

数据源：modood/Administrative-divisions-of-China（pca-code.json，code/name/children）。
后处理：
1. 直辖市/省直辖县级市的「市辖区」「县」城市级名称 → 归一为省名（避免前端出现「市辖区」）。
2. 紧凑序列化（无空白），UTF-8 无 BOM。
"""
from __future__ import annotations

import json
import sys
import urllib.request

RAW = r"D:\codeproject\PHP\CubeShop\backend\resources\data\_pca_raw.json"
OUT = r"D:\codeproject\PHP\CubeShop\backend\resources\data\regions.json"

URLS = [
    "https://cdn.jsdelivr.net/gh/modood/Administrative-divisions-of-China@master/dist/pca-code.json",
    "https://raw.githubusercontent.com/modood/Administrative-divisions-of-China/master/dist/pca-code.json",
]

RENAME = {"市辖区", "县", "省直辖县级行政区划", "自治区直辖县级行政区划"}


def fetch() -> list:
    last_err: Exception | None = None
    for url in URLS:
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "CubeShop/1.0"})
            with urllib.request.urlopen(req, timeout=120) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except Exception as exc:  # noqa: BLE001
            last_err = exc
            print(f"fetch fail: {url} -> {exc}", file=sys.stderr)
    # 本地缓存兜底（curl 可能已成功落盘，只是 ls 校验失败）
    try:
        with open(RAW, encoding="utf-8") as fh:
            return json.load(fh)
    except Exception:
        raise SystemExit(f"all sources failed; last error: {last_err}")


def pad(code: str) -> str:
    """上游省/市级 code 缺尾零（如 44 / 4401），统一补齐为 GB/T 2260 六位。"""
    return str(code).ljust(6, "0")


def normalize(provinces: list) -> list:
    out = []
    for prov in provinces:
        p_children = []
        for city in prov.get("children", []):
            cname = city["name"]
            if cname in RENAME:
                cname = prov["name"]
            a_children = [
                {"code": pad(a["code"]), "name": a["name"]}
                for a in city.get("children", [])
            ]
            p_children.append({"code": pad(city["code"]), "name": cname, "children": a_children})
        out.append({"code": pad(prov["code"]), "name": prov["name"], "children": p_children})
    # 港澳台为省级行政区（中国台湾 710000 / 中国香港 810000 / 中国澳门 820000），
    # 上游数据源未收录；运费规则需要能选中它们（如偏远/不配送地区），故补充为无下级的省级条目。
    out.extend([
        {"code": "710000", "name": "中国台湾", "children": []},
        {"code": "810000", "name": "中国香港", "children": []},
        {"code": "820000", "name": "中国澳门", "children": []},
    ])
    return out


def main() -> None:
    raw = fetch()
    # 落一份原始缓存便于复跑
    with open(RAW, "w", encoding="utf-8") as fh:
        json.dump(raw, fh, ensure_ascii=False, separators=(",", ":"))

    tree = normalize(raw)
    n_prov = len(tree)
    n_city = sum(len(p["children"]) for p in tree)
    n_area = sum(len(c["children"]) for p in tree for c in p["children"])
    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump(tree, fh, ensure_ascii=False, separators=(",", ":"))

    print(f"provinces={n_prov} cities={n_city} areas={n_area}")
    import os
    print(f"size={os.path.getsize(OUT)} bytes")


if __name__ == "__main__":
    main()
