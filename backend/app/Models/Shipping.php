<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 发货记录（T-042，E03）
 *
 * 一单一发货；重复发货在业务层（OrderService）拒绝。
 * `trace_status`：pending（未拉取）/ in_transit（运输中）/ delivered（已签收）/ failed（连续拉取失败）。
 */
class Shipping extends Model
{
    public const TRACE_PENDING = 'pending';

    public const TRACE_IN_TRANSIT = 'in_transit';

    public const TRACE_DELIVERED = 'delivered';

    public const TRACE_FAILED = 'failed';

    protected $fillable = [
        'order_id', 'company_code', 'company_name', 'tracking_no', 'phone',
        'trace_status', 'pull_fail_count', 'last_fail_message', 'shipped_at', 'delivered_at',
        'waybill_channel', 'waybill_printed_at', 'waybill_data',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'waybill_printed_at' => 'datetime',
        'waybill_data' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function traces(): HasMany
    {
        return $this->hasMany(ShippingTrace::class)->orderByDesc('occurred_at');
    }

    /**
     * 取轨迹查询所需的收件人手机号（V1.1 三期）
     *
     * 优先用发货时冗余的 `phone`；历史运单（000110 迁移前）为空时
     * 回落到订单收货快照 `address_snapshot.contact_phone`，避免旧数据查不了顺丰/中通。
     * 调用方建议 `with('order')` 预加载，避免 N+1。
     */
    public function resolvePhone(): ?string
    {
        $phone = trim((string) $this->phone);
        if ($phone !== '') {
            return $phone;
        }

        $snapshot = $this->order?->address_snapshot;
        $fallback = is_array($snapshot) ? trim((string) ($snapshot['contact_phone'] ?? '')) : '';

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * 解析可打印面单 HTML（供后台「打印面单」端点离线重打）
     *
     * 取值优先级：
     *  1. waybill_data.print_template（发货出单时由 labelData 落库，最权威）；
     *  2. 快递100 旧结构 data.printTemplate / printTemplateBase64 兜底；
     *  3. 均无 → 返回 null（手动录入或渠道未返回面单数据的运单）。
     *
     * 返回内容可能含 base64（图片 / HTML），由 {@see normalizePrintContent} 规整为可嵌入 HTML。
     */
    public function resolvePrintTemplate(): ?string
    {
        $data = is_array($this->waybill_data) ? $this->waybill_data : [];

        $top = $data['print_template'] ?? null;
        if (is_string($top) && $top !== '') {
            return $this->normalizePrintContent($top);
        }

        // 快递100 旧结构兜底（data 子对象内）
        $inner = $data['data'] ?? $data;
        $candidate = ($inner['printTemplate'] ?? null)
            ?? ($inner['printTemplateBase64'] ?? null)
            ?? ($data['printTemplate'] ?? null);
        if (is_string($candidate) && $candidate !== '') {
            return $this->normalizePrintContent($candidate);
        }

        return null;
    }

    /**
     * 把面单原始内容规整为可安全嵌入打印页的 HTML
     *
     * - base64（printTemplateBase64 变体）：解码后按图片 / HTML / 文本分流；
     * - HTML：原样返回（快递100 云打印模板自带脚本，按预期渲染）；
     * - 其它文本：转义后包 <pre>。
     */
    private function normalizePrintContent(string $raw): string
    {
        $trimmed = trim($raw);

        // 纯 base64（无空白、仅 base64 字符）：尝试解码（快递100 printTemplateBase64 变体）
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $trimmed)) {
            $decoded = base64_decode($trimmed, true);
            if ($decoded !== false && $decoded !== '') {
                $trimmed = $decoded;
            }
        }

        $trimmed = trim($trimmed);

        // 二进制图片头（PNG / JPEG）→ 包成 data URI <img>
        if (str_starts_with($trimmed, "\x89PNG") || str_starts_with($trimmed, "\xFF\xD8\xFF")) {
            $mime = str_starts_with($trimmed, "\x89PNG") ? 'image/png' : 'image/jpeg';
            $uri = 'data:'.$mime.';base64,'.base64_encode($trimmed);

            return sprintf('<img src="%s" alt="waybill" style="max-width:100%%">', $uri);
        }

        // HTML 片段 / 文档：原样嵌入
        if (str_starts_with($trimmed, '<')) {
            return $trimmed;
        }

        // 纯文本兜底
        return sprintf('<pre style="white-space:pre-wrap;font-family:monospace">%s</pre>', htmlspecialchars($trimmed, ENT_QUOTES));
    }
}
