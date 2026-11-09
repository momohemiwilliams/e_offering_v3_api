<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post-launch change to an offer (CR s4.6). `changes` keeps the original and
 * amended value of every field, so the audit trail can show both.
 */
class OfferAmendment extends Model
{
    public const PENDING_AUTHORISATION = 'pending_authorisation';
    public const PENDING_ADMIN_REVIEW = 'pending_admin_review';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'offer_id', 'requested_by', 'reason', 'changes', 'status', 'reviewed_by', 'reviewed_at',
        'review_comment', 'applied_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::PENDING_AUTHORISATION, self::PENDING_ADMIN_REVIEW], true);
    }
}
