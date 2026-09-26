<?php

namespace App\Models;

use App\Models\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 退款纠纷消息（沟通线程）
 */
class RefundDisputeMessage extends Model
{
    use HasPublicId;

    protected $table = 'refund_dispute_messages';

    protected $fillable = [
        'public_id', 'dispute_id', 'sender_type', 'sender_id', 'body', 'attachments',
    ];

    protected $casts = [
        'attachments' => 'array',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(RefundDispute::class, 'dispute_id');
    }
}
