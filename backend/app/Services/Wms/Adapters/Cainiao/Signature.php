<?php

namespace App\Services\Wms\Adapters\Cainiao;

/**
 * 奇门签名（WMS 计划 P2 / F2、Step 2）
 *
 * 规则来源：阿里巴巴开放平台官方 SDK `signTopRequest`（奇门仓配接入说明）。
 * 原版 Java 逻辑：
 *
 * ```java
 * Arrays.sort(keys);                              // 1. 参数名 ASCII 升序
 * if (md5) query.append(secret);                  // 2. md5 模式前置 secret（见下方变体说明）
 * for (key : keys) if (areNotEmpty(key, value))    // 3. 跳过空值，拼 key+value（无分隔符）
 *     query.append(key).append(value);
 * if (body != null) query.append(body);            // 4. 业务报文原文接在参数后面
 * bytes = hmac ? encryptHMAC(query, secret) : encryptMD5(query);
 * return hex(bytes).toUpperCase();                 // 5. 大写十六进制
 * ```
 *
 * **变体（重要）**：待签串里 secret 的位置有两套流传口径——
 * - 奇门文档：`md5(secret + 参数串 + body + secret)`（首尾都包）；
 * - TOP 通用 SDK 的 md5 分支：`md5(参数串 + body + secret)`（只包尾）。
 *
 * 由 `wms.providers.cainiao.sign_secret_wrap`（`both` / `tail`）在运行时选择，
 * 默认 `both`。这样联调遇到签名失败时改配置即可，不必改代码。
 * `hmac_md5` 不受影响：secret 作为 HMAC key，不参与待签串拼接。
 *
 * 编码：一律 UTF-8。参数值含中文/空串/布尔时按原样拼接（PHP 弱类型转字符串：
 * `true` → `"1"`、`false` → `""`，后者会因空值被跳过——与 Java 的
 * `String.valueOf(false)` 得到 `"false"` 不同，故**调用方不应传布尔**，
 * 需要时先自行转成 `"true"/"false"` 字符串）。
 */
class Signature
{
    /** 报文 sign_method 字段与服务端算法：MD5 */
    public const METHOD_MD5 = 'md5';

    /** 报文 sign_method 字段与服务端算法：HMAC-MD5 */
    public const METHOD_HMAC_MD5 = 'hmac_md5';

    /** 待签串里 secret 首尾都包（奇门文档口径，默认） */
    public const WRAP_BOTH = 'both';

    /** 待签串里 secret 只包尾（TOP 通用 SDK 口径） */
    public const WRAP_TAIL = 'tail';

    /**
     * 生成签名。
     *
     * @param  array<string, mixed>  $params  系统参数（**不含** sign；空值会被跳过）
     * @param  string  $secret  应用密钥（AppSecret 明文）
     * @param  string|null  $body  业务报文原文（与真正发出的 body **必须逐字节一致**）
     * @param  string  $method  md5 | hmac_md5
     * @param  string  $wrap  both | tail（仅 md5 生效）
     */
    public function sign(
        array $params,
        string $secret,
        ?string $body = null,
        string $method = self::METHOD_MD5,
        string $wrap = self::WRAP_BOTH,
    ): string {
        $source = $this->buildSource($params, $body);

        $digest = match ($method) {
            self::METHOD_HMAC_MD5 => hash_hmac('md5', $source, $secret),
            default => md5($this->applySecretToSource($source, $secret, $wrap)),
        };

        return strtoupper($digest);
    }

    /**
     * 校验签名（P3 回调复用）。
     *
     * 用 `hash_equals` 做常量时间比较，避免通过响应时间差反推签名。
     * 参数被篡改任意一位都会失败。
     */
    public function verify(
        array $params,
        string $secret,
        string $sign,
        ?string $body = null,
        string $method = self::METHOD_MD5,
        string $wrap = self::WRAP_BOTH,
    ): bool {
        if ($sign === '') {
            return false;
        }

        return hash_equals(
            $this->sign($params, $secret, $body, $method, $wrap),
            strtoupper($sign),
        );
    }

    /**
     * 待签串（不含 secret 包裹）：`key1value1key2value2…` + body。
     *
     * 空值规则遵循官方的 `areNotEmpty(key, value)`：**key 或 value 为空即整项跳过**
     * （注意 0 / "0" 视为非空，必须参与签名）。
     */
    public function buildSource(array $params, ?string $body = null): string
    {
        // 只排除 sign 自身；sign_method / timestamp / v 等系统参数**都要参与签名**
        unset($params['sign']);

        // 只保留「键非空且值非空」的参数；布尔 false / null / '' 均视为空
        $params = array_filter(
            $params,
            static fn ($value, $key) => $key !== '' && $value !== null && $value !== '' && $value !== false,
            ARRAY_FILTER_USE_BOTH,
        );

        ksort($params, SORT_STRING);

        $source = '';
        foreach ($params as $key => $value) {
            // 数组/对象在奇门里不会出现在系统参数位，兜底转 JSON 保证可签
            $source .= $key.(is_array($value) ? $this->encodeJson($value) : (string) $value);
        }

        return $source.($body ?? '');
    }

    private function applySecretToSource(string $source, string $secret, string $wrap): string
    {
        return $wrap === self::WRAP_TAIL
            ? $source.$secret
            : $secret.$source.$secret;
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
