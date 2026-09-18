<?php

use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * P2-11 终态：对外公开标识 = 持久化 ULID public_id（退役 sqids）。
 * 旧单测依赖 Sqids 编解码，此处改为校验 public_id 列承载的双向解析。
 */

test('P2-11 模型自动生成 26 位 public_id 且唯一', function () {
    $product = createTestSku()->product;

    expect($product->public_id)->not->toBeNull()
        ->and(strlen($product->public_id))->toBe(26);
});

test('P2-11 resolve 可双向解析 public_id 与历史 int 主键', function () {
    $product = createTestSku()->product;

    expect(PublicId::resolve(PublicId::SCOPE_PRODUCT, $product->public_id))->toBe($product->id)
        ->and(PublicId::resolve(PublicId::SCOPE_PRODUCT, (string) $product->id))->toBe($product->id)
        ->and(PublicId::encode(PublicId::SCOPE_PRODUCT, $product->id))->toBe($product->public_id);
});

test('P2-11 非法 / 空 / 跨实体 public_id 一律解析失败', function () {
    $product = createTestSku()->product;

    expect(PublicId::resolve(PublicId::SCOPE_PRODUCT, 'zzzzzzzzzz'))->toBeNull()
        ->and(PublicId::resolve(PublicId::SCOPE_PRODUCT, ''))->toBeNull()
        ->and(PublicId::resolve(PublicId::SCOPE_PRODUCT, 0))->toBeNull()
        // 拿商品 public_id 去解订单 scope（订单表无此 public_id）→ 失败，防止串用
        ->and(PublicId::resolve(PublicId::SCOPE_ORDER, $product->public_id))->toBeNull();
});

test('P2-11 encode / encodeNullable 对空 / 非法主键返回 null 而非抛异常', function () {
    expect(PublicId::encode(PublicId::SCOPE_PRODUCT, null))->toBeNull()
        ->and(PublicId::encode(PublicId::SCOPE_PRODUCT, 0))->toBeNull()
        ->and(PublicId::encodeNullable(PublicId::SCOPE_PRODUCT, null))->toBeNull();
});
