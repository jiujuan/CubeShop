<?php

use App\Support\CmsPageTemplate;

/**
 * 单页模板注册表（CMS-102）
 *
 * 守卫用例（模板 key ↔ 前台 web/src/views/pages/Page{Key}.vue 一一对应）在前台
 * 组件落地后启用，见本文件末尾。
 */
test('TC-CMS-T01 所有模板字段的类型都在白名单内（含 repeater 子字段）', function () {
    expect(CmsPageTemplate::templateKeys())->not->toBeEmpty();

    foreach (CmsPageTemplate::templateKeys() as $key) {
        // `blocks`（CMS-203）是区块化模板：内容走 blocks 列，字段 schema 故意为空
        if (CmsPageTemplate::isBlocks($key)) {
            continue;
        }

        $fields = CmsPageTemplate::schema($key);

        expect($fields)->not->toBeEmpty();

        foreach ($fields as $field) {
            expect(CmsPageTemplate::FIELD_TYPES)->toContain($field['type'])
                ->and($field)->toHaveKeys(['key', 'label', 'type']);

            foreach ($field['item'] ?? [] as $sub) {
                expect(CmsPageTemplate::FIELD_TYPES)->toContain($sub['type'])
                    ->and($sub)->toHaveKeys(['key', 'label', 'type']);
            }
        }
    }
});

test('TC-CMS-T02 同模板内字段 key 不重复', function () {
    foreach (CmsPageTemplate::templateKeys() as $key) {
        $keys = array_column(CmsPageTemplate::schema($key), 'key');

        expect($keys)->toBe(array_values(array_unique($keys)));
    }
});

test('TC-CMS-T03 exists/labels/componentName 与模板定义一致', function () {
    expect(CmsPageTemplate::exists('about'))->toBeTrue()
        ->and(CmsPageTemplate::exists('not-exist'))->toBeFalse();

    // 已知模板存在，未知模板返回空 schema / 空 defaults
    expect(CmsPageTemplate::schema('not-exist'))->toBe([])
        ->and(CmsPageTemplate::defaultsOf('not-exist'))->toBe([]);

    expect(CmsPageTemplate::labels())->toHaveKey('about')
        ->and(CmsPageTemplate::componentName('about'))->toBe('PageAbout.vue');
});

test('TC-CMS-T04 emptyValueOf 按类型给空值（数组型给 []，其余给空串）', function () {
    expect(CmsPageTemplate::emptyValueOf('repeater'))->toBe([])
        ->and(CmsPageTemplate::emptyValueOf('image_list'))->toBe([])
        ->and(CmsPageTemplate::emptyValueOf('text'))->toBe('')
        ->and(CmsPageTemplate::emptyValueOf('markdown'))->toBe('');
});

test('TC-CMS-T05 filterPayload 丢弃未知键、补齐缺失键、null 归一为空值', function () {
    $result = CmsPageTemplate::filterPayload('contact', [
        'address' => '北京市朝阳区',
        'phone' => null,
        'hacker' => 'should-be-dropped',
    ]);

    // 已知键保留
    expect($result)->toHaveKey('address')
        ->and($result['address'])->toBe('北京市朝阳区');

    // null 归一为默认空值
    expect($result['phone'])->toBe('');

    // 未知键被丢弃
    expect($result)->not->toHaveKey('hacker');

    // 缺失键补默认（schema 内的全部键都在）
    $schemaKeys = array_column(CmsPageTemplate::schema('contact'), 'key');
    expect(array_keys($result))->toEqualCanonicalizing($schemaKeys);
});

test('TC-CMS-T06 filterPayload 对 repeaters 字段给 []，并保留已提交的行', function () {
    $rows = [['year' => '2020', 'event' => '成立']];

    $result = CmsPageTemplate::filterPayload('about', ['milestones' => $rows]);

    expect($result['milestones'])->toBe($rows)
        ->and($result['values'])->toBe([]);
});

test('TC-CMS-T07 rules 对必填字段产出 required，非必填产出 nullable', function () {
    $rules = CmsPageTemplate::rules('about');

    expect($rules['fields.intro'])->toContain('required')
        ->and($rules['fields.banner'])->toContain('nullable')
        ->and($rules['fields.milestones'])->toContain('array');

    $contactRules = CmsPageTemplate::rules('contact');
    expect($contactRules['fields.address'])->toContain('required')
        ->and($contactRules['fields.phone'])->toContain('nullable');
});

/**
 * 守卫用例：模板 key ↔ 前台组件 `web/src/views/pages/Page{Key}.vue` 必须一一对应。
 *
 * 新增模板却忘记写前台组件（或反之）会直接测试失败 —— 与 admin 的
 * `sidebar-icon.test.ts` 同一体例，防止"注册表涨了、前台却渲染不出来"的静默漂移。
 */
test('TC-CMS-T08 模板 key 与前台 Page{Key}.vue 组件集合完全一致（守卫）', function () {
    $dir = dirname(base_path()).'/web/src/views/pages';

    expect(is_dir($dir))->toBeTrue();

    $files = array_map('basename', glob($dir.'/Page*.vue') ?: []);
    $componentKeys = array_map(
        fn (string $file) => strtolower((string) preg_replace('/^Page(.+)\.vue$/', '$1', $file)),
        $files,
    );

    $templateKeys = CmsPageTemplate::templateKeys();

    sort($componentKeys);
    sort($templateKeys);

    expect($componentKeys)->toBe($templateKeys);
});
