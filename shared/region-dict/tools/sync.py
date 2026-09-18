"""CubeShop 地区字典公共服务 —— 校验 + 构建产物 + 分发到各消费方。

单一真源：shared/region-dict/regions.json（GB/T 2260 三级：省 → 市 → 区，含港澳台）

用法（任意系统，需 Python 3.9+）：
    python shared/region-dict/tools/sync.py            # 校验 + 生成 dist + 分发
    python shared/region-dict/tools/sync.py --check    # 只校验与检查是否漂移，不写文件

分发目标（由 TARGETS 定义，新增消费方在此登记即可）：
    backend/resources/data/regions.json   后端 RegionService 数据源
    web/src/data/regions.tree.json        web 前台三级联动（完整树）
    web/src/data/provinces.json           web 轻量省级列表
    admin/src/data/regions.tree.json      admin 三级联动（完整树）
    admin/src/data/provinces.json         admin 运费模板省份下拉（轻量）
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys
from datetime import datetime, timezone
from typing import Dict, List

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SOURCE = os.path.join(ROOT, "regions.json")
DIST = os.path.join(ROOT, "dist")
REPO = os.path.abspath(os.path.join(ROOT, "..", ".."))

TREE_TARGETS: List[str] = [
    "backend/resources/data/regions.json",
    "web/src/data/regions.tree.json",
    "admin/src/data/regions.tree.json",
]

PROVINCE_TARGETS: List[str] = [
    "web/src/data/provinces.json",
    "admin/src/data/provinces.json",
]

# GB/T 2260：港澳台为独立省级行政区（上游数据源不收录，字典必须包含）
REQUIRED_PROVINCES = {"710000": "中国台湾", "810000": "中国香港", "820000": "中国澳门"}


def load_source() -> list:
    with open(SOURCE, encoding="utf-8") as fh:
        return json.load(fh)


def validate(tree: list) -> List[str]:
    """结构校验：code 六位数字、全局唯一、层级合法、必需省级条目存在。"""
    errors: List[str] = []
    seen: Dict[str, str] = {}

    def walk(nodes: list, level: str, parent: str) -> None:
        # 省 / 市：GB/T 2260 严格六位；区/县：上游含九位街道码（如 460400112），只要求不短于六位
        exact = level in ("省", "市")
        for node in nodes:
            code = str(node.get("code", ""))
            name = str(node.get("name", ""))
            if not code.isdigit() or len(code) < 6 or (exact and len(code) != 6):
                errors.append(f"{level} code 非法: {parent}>{name} -> {code}")
            if code in seen:
                errors.append(f"{level} code 重复: {code} ({seen[code]} / {name})")
            else:
                seen[code] = name
            if not name:
                errors.append(f"{level} 名称为空: {code}")
            for child in node.get("children") or []:
                walk([child], "区" if level == "市" else "市", f"{parent}>{name}")

    for prov in tree:
        walk([prov], "省", "")

    for code, name in REQUIRED_PROVINCES.items():
        if not any(str(p["code"]) == code for p in tree):
            errors.append(f"缺少必需省级条目: {code} {name}")

    return errors


def write_json(path: str, payload: object) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, separators=(",", ":"))


def sha1_of(path: str) -> str:
    with open(path, "rb") as fh:
        return hashlib.sha1(fh.read()).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="只校验/检查漂移，不写文件")
    args = parser.parse_args()

    tree = load_source()
    errors = validate(tree)
    if errors:
        print("校验失败：")
        for err in errors:
            print("  -", err)
        return 1

    provinces = [{"code": p["code"], "name": p["name"]} for p in tree]
    n_city = sum(len(p.get("children") or []) for p in tree)
    n_area = sum(len(c.get("children") or []) for p in tree for c in (p.get("children") or []))
    meta = {
        "generated_at": datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds"),
        "source_sha1": sha1_of(SOURCE),
        "provinces": len(provinces),
        "cities": n_city,
        "areas": n_area,
    }
    print(f"校验通过：{len(provinces)} 省 / {n_city} 市 / {n_area} 区")

    if args.check:
        drift = [t for t in TREE_TARGETS + PROVINCE_TARGETS
                 if not os.path.exists(os.path.join(REPO, t))]
        if drift:
            print("分发缺失：")
            for item in drift:
                print("  -", item)
            return 1
        print("分发产物齐全，无漂移")
        return 0

    write_json(os.path.join(DIST, "regions.tree.min.json"), tree)
    write_json(os.path.join(DIST, "regions.provinces.min.json"), provinces)
    write_json(os.path.join(DIST, "meta.json"), meta)

    for target in TREE_TARGETS:
        write_json(os.path.join(REPO, target), tree)
    for target in PROVINCE_TARGETS:
        write_json(os.path.join(REPO, target), provinces)

    print("已分发到：")
    for target in TREE_TARGETS + PROVINCE_TARGETS:
        print("  -", target)
    return 0


if __name__ == "__main__":
    sys.exit(main())
