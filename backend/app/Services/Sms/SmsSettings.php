<?php

namespace App\Services\Sms;

use App\Services\Common\ConfigService;

/**
 * 短信运营开关（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D1
 *
 * 收敛 `sms.*` 配置的唯一读口，与 `Support\Search\SearchConfig` 同款做法：
 * 分散在各处用 `config()` 读，后台就永远改不动；读库失败静默回落默认值（安装期/迁移期不 500）。
 *
 * ⚠️ 只放**运营真的需要改**的开关。凭证（AccessKey）绝不进 `system_configs` —— 该表无加密列、
 *    值全部明文回显，凭证必须留在 `sms_configs` 的 `*_enc` 密文列里。
 *
 * 存储格式：`sms.code_scenes` 存**逗号分隔**字符串（`register,login,reset_password`），
 * `sms.code_templates` 存 JSON（`{"register":"SMS_123"}`）—— 前者便于后台勾选框双向绑定，
 * 后者结构天然是键值对。两者都是字符串值，与 `system_configs.config_value` 的列类型一致。
 *
 * ### 「配了服务商账号就自动启用」的表达方式
 *
 * 需求是「后台配好阿里云就默认走短信验证码」。实现上分两件事，缺一不可：
 * 1. **自动落库**（{@see SmsAutoEnable}）：渠道保存成功时，把已配模板的场景写进 `code_scenes`
 *    并打开总开关。这是**一次性写入**，写完就是普通配置值，管理员看得见、改得动、有操作日志；
 * 2. **运行时校验**（{@see SmsReadiness}）：每次发送前再验一遍就绪度，不就绪就回退图形验证码。
 *
 * ⚠️ 不能写成「每次请求判断有没有配渠道」——那样管理员在后台关掉渠道调试一下，
 *    前台登录/注册的模式就跟着跳变，且无从审计。自动只发生在保存那一刻。
 *
 * `sms.code_scenes_auto` 是自动落库的开关：`1` 表示管理员从未手动设置过场景，允许自动写入；
 * 一旦后台提交过 `code_scenes` 就置 `0`，此后完全听人的（与 search 引擎的 `auto` 语义同款）。
 */
final class SmsSettings
{
    /** 后台可维护的开关：`键 => 默认值` */
    public const SWITCHES = [
        'sms.enabled' => '0',
        'sms.code_scenes' => '',
        'sms.code_templates' => '{}',
        'sms.code_scenes_auto' => '1',
    ];

    /**
     * 可选验证码场景（页面多选与接口校验共用）
     *
     * ⚠️ 顺序即后台展示顺序；新增场景只需加在这里，后台页面会自动出现（前端不硬编码场景）。
     */
    public const CODE_SCENES = [
        'register' => '注册',
        'login' => '登录',
        'reset_password' => '重置密码',
    ];

    public function __construct(private readonly ConfigService $config)
    {
    }

    /** 短信总开关 */
    public function enabled(): bool
    {
        return $this->config->get('sms.enabled', self::SWITCHES['sms.enabled']) === '1';
    }

    /** 已启用短信验证码的场景（未列出的场景维持图形验证码） */
    public function codeScenes(): array
    {
        $raw = (string) $this->config->get('sms.code_scenes', '');

        if (trim($raw) === '') {
            return [];
        }

        $scenes = array_filter(array_map('trim', explode(',', $raw)));

        // 过滤掉已下线/写错的场景名，避免脏配置让 AuthController 走进不存在的分支
        return array_values(array_intersect($scenes, array_keys(self::CODE_SCENES)));
    }

    /** 某场景是否已切到短信验证码 */
    public function codeSceneEnabled(string $scene): bool
    {
        return in_array($scene, $this->codeScenes(), true);
    }

    /** 场景是否合法（防止把任意字符串当场景名用） */
    public function isKnownScene(string $scene): bool
    {
        return array_key_exists($scene, self::CODE_SCENES);
    }

    /**
     * 场景列表（后台页面多选与前端展示共用同一份白名单）
     *
     * @return array<string, string>  场景 key => 中文名
     */
    public function sceneOptions(): array
    {
        return self::CODE_SCENES;
    }

    /**
     * 场景自动落库是否仍处于「未人工干预」状态
     *
     * 为 `false` 时 {@see SmsAutoEnable} 不再改动 `code_scenes` —— 管理员的手动选择优先。
     */
    public function scenesAuto(): bool
    {
        return $this->config->get('sms.code_scenes_auto', '1') !== '0';
    }

    /** 标记为「管理员已手动设置过场景」，此后不再自动改写 */
    public function markScenesManual(): void
    {
        $this->config->set('sms.code_scenes_auto', '0');
    }

    /**
     * 场景对应的短信模板 CODE
     *
     * 模板 CODE 是**阿里云账号级别**的（`SMS_xxxxxx`），每个账号申请到的都不一样，
     * 因此不能写死在代码里，必须可配置。未配置时发不出验证码（返回 null，由调用方报错）。
     */
    public function templateFor(string $scene): ?string
    {
        $raw = (string) $this->config->get('sms.code_templates', '{}');
        $map = json_decode($raw, true);

        if (! is_array($map)) {
            return null;
        }

        $code = $map[$scene] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** 已配好模板的场景（自动落库只认这些 —— 没模板的场景启用了也发不出码） */
    public function scenesWithTemplate(): array
    {
        $out = [];

        foreach (array_keys(self::CODE_SCENES) as $scene) {
            if ($this->templateFor($scene) !== null) {
                $out[] = $scene;
            }
        }

        return $out;
    }

    /** 全部开关当前值（后台配置页用） */
    public function switches(): array
    {
        return [
            'sms.enabled' => $this->enabled() ? '1' : '0',
            'sms.code_scenes' => implode(',', $this->codeScenes()),
            'sms.code_templates' => (string) $this->config->get('sms.code_templates', '{}'),
            'sms.code_scenes_auto' => $this->scenesAuto() ? '1' : '0',
        ];
    }

    /**
     * 自动落库专用：写入场景但不改变「未人工干预」标记
     *
     * 与 {@see self::updateSwitches()} 的区别：后者提交 `code_scenes` 会置 auto=0
     * （视为管理员接管），自动落库必须绕过它，否则自动只能生效一次就被自己关掉。
     *
     * @param  array<int, string>  $scenes
     */
    public function applyAutoScenes(array $scenes): void
    {
        $scenes = array_values(array_intersect($scenes, array_keys(self::CODE_SCENES)));

        $this->config->set('sms.code_scenes', implode(',', $scenes));
    }

    /**
     * 更新开关（只认 {@see self::SWITCHES} 白名单内的键）
     *
     * ⚠️ 只要显式提交了 `code_scenes`，就视为管理员接管 —— 自动落库从此不再改写它。
     *
     * @param  array<string, mixed>  $values
     */
    public function updateSwitches(array $values): void
    {
        if (isset($values['sms.enabled'])) {
            $this->config->set('sms.enabled', $values['sms.enabled'] ? '1' : '0');
        }

        if (isset($values['sms.code_scenes'])) {
            $scenes = is_array($values['sms.code_scenes'])
                ? $values['sms.code_scenes']
                : array_filter(array_map('trim', explode(',', (string) $values['sms.code_scenes'])));

            $scenes = array_values(array_intersect($scenes, array_keys(self::CODE_SCENES)));

            $this->config->set('sms.code_scenes', implode(',', $scenes));
            $this->markScenesManual();
        }

        if (isset($values['sms.code_templates'])) {
            $this->config->set('sms.code_templates', $this->normalizeTemplates($values['sms.code_templates']));
        }

        if (isset($values['sms.code_scenes_auto'])) {
            $this->config->set('sms.code_scenes_auto', $values['sms.code_scenes_auto'] ? '1' : '0');
        }
    }

    /** 模板映射归一化：只保留已知场景，非法 JSON 落为 {} */
    private function normalizeTemplates(mixed $value): string
    {
        $map = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($map)) {
            return '{}';
        }

        $out = [];

        foreach (array_keys(self::CODE_SCENES) as $scene) {
            $code = $map[$scene] ?? null;

            if (is_string($code) && trim($code) !== '') {
                $out[$scene] = trim($code);
            }
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
}
