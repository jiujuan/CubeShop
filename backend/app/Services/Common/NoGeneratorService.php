<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 单号生成服务（架构文档 4.1.1 公共服务层）
 *
 * 规则（SEC-03 修复后）：{前缀}{YYYYMMDD}{10 位随机数字}，如 CS2026091804738291
 *
 * 背景：原实现为 `{前缀}{Ymd}{6 位日期内序列}`，序列每日从 1 严格递增。
 * 单号会在快递面单、短信、客服对话、后台截图、日志等环节脱离系统控制，
 * 因此"可预测"等于"可枚举"——拿到任意一个当日单号即可推出当日单量，
 * PAY/CS 得支付转化率、RF/CS 得退款率、TK/CS 得售后率，进而反推日 GMV。
 *
 * 现改为随机段：10 位数字（首位非零），取值空间 9×10^9。
 * - 日 1 万单时生日碰撞期望 ≈ 10^8/(2×9×10^9) ≈ 0.6%，配合 5 次重试可忽略；
 * - 唯一性最终由各表单号列的 **唯一索引** 兜底（竞态窗口极小但存在）；
 * - 单号不再具备字典序单调性，**排序一律改用 created_at / 自增 id**。
 *
 * 依赖变更：`biz_no_sequences` 表不再参与取号（退役，仅保留历史数据与内部计数用途）。
 */
class NoGeneratorService
{
    public const PREFIX_ORDER = 'CS';

    public const PREFIX_PAYMENT = 'PAY';

    public const PREFIX_REFUND = 'RF';

    /** 余额充值单（收银台方案 §4.1(4)：RC20260916000001） */
    public const PREFIX_RECHARGE = 'RC';

    /**
     * 客服服务工单（CS-105：TK20260917000001）
     *
     * 注意：设计文档示例写作 CS 前缀，但 CS 已被订单号占用（PREFIX_ORDER），
     * 工单号改前缀 TK，避免两类单号在同一命名空间下撞号。
     */
    public const PREFIX_TICKET = 'TK';

    /**
     * 发货单（WMS 计划 P1：FO2026092000001234567）
     *
     * 出库单号会随报文发往 WMS，故用独立前缀，与订单 CS / 支付 PAY / 退款 RF 区分。
     */
    public const PREFIX_FULFILLMENT = 'FO';

    /** 随机段长度（10 位数字） */
    public const RANDOM_LENGTH = 10;

    /** 冲突重试次数 */
    private const MAX_RETRY = 5;

    /** 前缀 => [唯一性预检表名, 单号列名] */
    private const STORAGE = [
        self::PREFIX_ORDER => ['orders', 'order_no'],
        self::PREFIX_PAYMENT => ['payments', 'payment_no'],
        self::PREFIX_REFUND => ['refunds', 'refund_no'],
        self::PREFIX_RECHARGE => ['balance_recharges', 'recharge_no'],
        self::PREFIX_TICKET => ['cs_ticket', 'ticket_no'],
        self::PREFIX_FULFILLMENT => ['fulfillment_orders', 'outbound_no'],
    ];

    /**
     * 生成业务单号：{前缀}{YYYYMMDD}{10 位随机数字}
     *
     * @throws RuntimeException 连续冲突超过重试上限（极端情况，应视为系统异常）
     */
    public function generate(string $prefix): string
    {
        $date = now()->format('Ymd');
        [$table, $column] = self::STORAGE[$prefix] ?? [null, null];

        for ($attempt = 0; $attempt < self::MAX_RETRY; $attempt++) {
            $no = $prefix.$date.$this->randomSegment();

            // 预检：已存在则重新摇号。竞态窗口内仍可能重复，由唯一索引兜底。
            if ($table === null || ! DB::table($table)->where($column, $no)->exists()) {
                return $no;
            }
        }

        throw new RuntimeException("单号生成冲突重试超限：prefix={$prefix}");
    }

    /**
     * 定长随机数字段（首位非零，保证定长）
     *
     * 使用 random_int（CSPRNG），不做取模运算，避免取模偏差导致分布不均。
     */
    public function randomSegment(int $length = self::RANDOM_LENGTH): string
    {
        if ($length < 1) {
            throw new RuntimeException('随机段长度必须大于 0');
        }

        $min = (int) ('1'.str_repeat('0', $length - 1));
        $max = (int) str_repeat('9', $length);

        return (string) random_int($min, $max);
    }

    public function generateOrderNo(): string
    {
        return $this->generate(self::PREFIX_ORDER);
    }

    public function generatePaymentNo(): string
    {
        return $this->generate(self::PREFIX_PAYMENT);
    }

    public function generateRefundNo(): string
    {
        return $this->generate(self::PREFIX_REFUND);
    }

    public function generateRechargeNo(): string
    {
        return $this->generate(self::PREFIX_RECHARGE);
    }

    public function generateTicketNo(): string
    {
        return $this->generate(self::PREFIX_TICKET);
    }

    /** 发货单（出库单）号，WMS 计划 P1 */
    public function generateOutboundNo(): string
    {
        return $this->generate(self::PREFIX_FULFILLMENT);
    }
}
