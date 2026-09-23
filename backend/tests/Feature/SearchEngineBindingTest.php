<?php

use App\Providers\AppServiceProvider;
use App\Services\Common\ConfigService;
use App\Support\ConfigGroup;
use App\Support\Search\FallbackLikeEngine;
use App\Support\Search\PostgresFtsEngine;
use App\Support\Search\ProductSearchEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * 搜索引擎绑定与运行时覆写（站内搜索 S1-05）
 *
 * 设计文档 §4.5「绑定」/ §6 后台配置。钉住的是三条约定：
 * 1. 业务侧拿到的引擎**一定可用**（不可用时降级，绝不返回不能出结果的实现）；
 * 2. 配置写错（未实现的引擎名）**不能让搜索整体炸掉**；
 * 3. `system_configs.search.engine` 能覆盖 `.env`，与 shipping.channel 同机制。
 *
 * ⚠️ 测试固定跑 SQLite，`PostgresFtsEngine` 在这里恒不可用，所以「降级」是必然而非偶然
 * —— 这正好是降级链路最容易被验证的环境。
 */
beforeEach(function () {
    $this->originalEngine = config('services.search.engine');
    PostgresFtsEngine::flushAvailabilityCache();
    app(ConfigService::class)->flush();
});

afterEach(function () {
    config(['services.search.engine' => $this->originalEngine]);
    PostgresFtsEngine::flushAvailabilityCache();
    app(ConfigService::class)->flush();
});

/** 反射调用私有覆写方法（避免重复 boot 造成监听器重复注册） */
function invokeSearchEngineOverride(): void
{
    $method = new ReflectionMethod(AppServiceProvider::class, 'applySearchEngineOverride');
    $method->setAccessible(true);
    $method->invoke(new AppServiceProvider(app()));
}

test('TC-SEARCH-S1-05-022 绑定解析出的实现一定是 ProductSearchEngine', function () {
    expect(app(ProductSearchEngine::class))->toBeInstanceOf(ProductSearchEngine::class);
});

test('TC-SEARCH-S1-05-023 SQLite 下默认（PG 不可用）降级为 LIKE 引擎', function () {
    expect(app(PostgresFtsEngine::class)->isAvailable())->toBeFalse()
        ->and(app(ProductSearchEngine::class))->toBeInstanceOf(FallbackLikeEngine::class);
});

test('TC-SEARCH-S1-05-024 engine=off 强制走 LIKE 降级', function () {
    config(['services.search.engine' => 'off']);

    expect(app(ProductSearchEngine::class))->toBeInstanceOf(FallbackLikeEngine::class);
});

test('TC-SEARCH-S1-05-025 显式指定 postgres 但不可用时仍降级，不抛异常', function () {
    config(['services.search.engine' => 'postgres']);

    // 降级是静默的：运营在 SQLite/未迁移的库上配了 pgsql 也只影响排序与召回，不影响可用性
    expect(app(ProductSearchEngine::class))->toBeInstanceOf(FallbackLikeEngine::class);
});

test('TC-SEARCH-S1-05-026 未实现的引擎名（阶段二）不炸，回落默认链路', function () {
    config(['services.search.engine' => 'meilisearch']);

    // 阶段二实现后这里应断言 instanceof MeilisearchEngine；现阶段保证配置写错也只是降级
    expect(app(ProductSearchEngine::class))->toBeInstanceOf(ProductSearchEngine::class);
});

test('TC-SEARCH-S1-05-027 system_configs.search.engine 覆盖 .env', function () {
    app(ConfigService::class)->set('search.engine', 'off');

    invokeSearchEngineOverride();

    expect(config('services.search.engine'))->toBe('off')
        ->and(app(ProductSearchEngine::class))->toBeInstanceOf(FallbackLikeEngine::class);
});

test('TC-SEARCH-S1-05-028 后台配置留空时保持 .env 值（不覆盖）', function () {
    config(['services.search.engine' => 'postgres']);
    app(ConfigService::class)->set('search.engine', '');

    invokeSearchEngineOverride();

    expect(config('services.search.engine'))->toBe('postgres');
});

test('TC-SEARCH-S1-05-029 系统表不可用时覆写静默跳过（安装/迁移前不炸）', function () {
    Schema::dropIfExists('system_configs');

    config(['services.search.engine' => 'postgres']);

    invokeSearchEngineOverride();

    expect(config('services.search.engine'))->toBe('postgres');
});

test('TC-SEARCH-S1-05-030 search 前缀归入「搜索与推荐」分组', function () {
    expect(ConfigGroup::labelOf('search.engine'))->toBe('搜索与推荐')
        ->and(ConfigGroup::labelOf('search.cache_ttl'))->toBe('搜索与推荐')
        ->and(ConfigGroup::labels())->toContain('搜索与推荐');
});
