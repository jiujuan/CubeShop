<?php

namespace App\Support\Sms;

/**
 * 阿里云 OpenAPI V3 签名（ACS3-HMAC-SHA256）—— 纯函数，无任何 IO
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D4
 *
 * 之所以独立成类并用**官方示例向量**做对拍，是因为签名错了只有在真机联调时才会暴露，
 * 而那时你只会收到一个 `SignatureDoesNotMatch`，无从判断是哪一步算错。
 *
 * 规范要点（来自阿里云官方 V3 文档，逐条本地验算通过）：
 * 1. `CanonicalRequest = 方法 \n URI \n 查询串 \n 规范化头 \n 已签名头列表 \n 请求体哈希`；
 * 2. RPC 风格 API 的 `CanonicalURI` 固定为 `/`，`Action` / `Version` 走 `x-acs-*` **请求头**，
 *    业务参数（如 `PhoneNumbers`）放**查询串**并按参数名升序 + RFC3986 编码；
 * 3. `StringToSign = "ACS3-HMAC-SHA256" \n hex(sha256(CanonicalRequest))`；
 * 4. **HMAC 密钥就是 AccessKey Secret 本身**，不是 `ACS3` + Secret（这一点极易记错，
 *    已用官方示例值 `YourAccessKeySecret` 验算确认）；
 * 5. `Authorization = "ACS3-HMAC-SHA256 Credential={id},SignedHeaders={...},Signature={...}"`。
 *
 * ⚠️ 参与签名的头必须与实际发出的头**逐字一致**：本类只负责算，不负责猜；
 *    `content-type` 若实际发出则必须传入（文档要求「除 Authorization 外的公共头都要参与」）。
 */
final class AliyunV3Signer
{
    public const ALGORITHM = 'ACS3-HMAC-SHA256';

    /**
     * 计算 Authorization 头的值
     *
     * @param  array<string, string>  $query  业务参数（自动按名升序 + RFC3986 编码）
     * @param  array<string, string>  $headers  参与签名的请求头（键会被转小写并升序；值会 trim）
     * @param  string  $payloadHash  hex(sha256(请求体))；无请求体时传 sha256('')
     * @param  string  $uri  规范化 URI，RPC 风格固定 '/'
     */
    public static function authorization(
        string $accessKeyId,
        string $accessKeySecret,
        string $method,
        string $host,
        array $query,
        array $headers,
        string $payloadHash,
        string $uri = '/',
    ): string {
        $headers = ['host' => $host] + $headers;

        $canonical = self::canonicalRequest($method, $uri, $query, $headers, $payloadHash);
        $signature = self::signature(self::stringToSign($canonical), $accessKeySecret);

        return sprintf(
            '%s Credential=%s,SignedHeaders=%s,Signature=%s',
            self::ALGORITHM,
            $accessKeyId,
            self::signedHeaders($headers),
            $signature,
        );
    }

    /** 规范化请求串（第 1 步产物，测试对拍用） */
    public static function canonicalRequest(
        string $method,
        string $uri,
        array $query,
        array $headers,
        string $payloadHash,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $uri === '' ? '/' : $uri,
            self::canonicalQuery($query),
            self::canonicalHeaders($headers),
            self::signedHeaders($headers),
            $payloadHash,
        ]);
    }

    /** 待签名串（第 2 步产物） */
    public static function stringToSign(string $canonicalRequest): string
    {
        return self::ALGORITHM."\n".hash('sha256', $canonicalRequest);
    }

    /** 签名值（第 3 步产物） */
    public static function signature(string $stringToSign, string $accessKeySecret): string
    {
        return hash_hmac('sha256', $stringToSign, $accessKeySecret);
    }

    /**
     * 规范化查询串：按参数名升序，键值分别做 RFC3986 编码
     *
     * ⚠️ 中文签名、JSON 模板参数都会被编码——不编码会被网关判为签名不一致。
     */
    public static function canonicalQuery(array $query): string
    {
        $params = $query;
        ksort($params);

        $pairs = [];

        foreach ($params as $name => $value) {
            // rawurlencode 即 RFC3986：空格 → %20，`*` 与 `~` 不编码
            $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /** 规范化头：小写键升序、值两端去空格、每行以 \n 结尾 */
    public static function canonicalHeaders(array $headers): string
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = trim((string) $value);
        }

        ksort($normalized);

        $out = '';

        foreach ($normalized as $name => $value) {
            $out .= $name.':'.$value."\n";
        }

        return $out;
    }

    /** 已签名头列表：小写键升序，分号连接 */
    public static function signedHeaders(array $headers): string
    {
        $names = array_map(fn ($name) => strtolower((string) $name), array_keys($headers));
        sort($names);

        return implode(';', $names);
    }
}
