<?php

namespace App\Models;

use App\Services\Allotment\AllotmentConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The allotment schedule for one offer (CR s5.2-5.4).
 *
 * draft -> pending_authorisation -> sec_pending -> approved -> lodged
 * returned (by TSP authoriser) and sec_rejected go back to the inputter; cancelled ends the offer.
 */
class Allotment extends Model
{
    public const DRAFT = 'draft';
    public const PENDING_AUTHORISATION = 'pending_authorisation';
    public const RETURNED = 'returned';
    public const SEC_PENDING = 'sec_pending';
    public const SEC_REJECTED = 'sec_rejected';
    public const APPROVED = 'approved';
    public const LODGED = 'lodged';
    public const CANCELLED = 'cancelled';

    public const OVERSUBSCRIBED = 'oversubscribed';
    public const FULLY_SUBSCRIBED = 'fully_subscribed';
    public const UNDERSUBSCRIBED = 'undersubscribed';
    public const BELOW_MINIMUM = 'below_minimum';

    protected $fillable = [
        'offer_id', 'method', 'final_rate', 'issue_size', 'total_demand', 'valid_demand', 'total_allotted',
        'subscription_level', 'subscription_status', 'notes', 'status', 'return_reason', 'initiated_by',
        'submitted_by', 'submitted_at', 'authorised_by', 'authorised_at', 'sec_reviewed_by', 'sec_reviewed_at',
        'sec_reason', 'lodgement_reference', 'lodgement_status', 'lodged_by', 'lodged_at',
    ];

    protected $casts = [
        'method' => 'array',
        'notes' => 'array',
        'final_rate' => 'decimal:4',
        'issue_size' => 'decimal:2',
        'total_demand' => 'decimal:2',
        'valid_demand' => 'decimal:2',
        'total_allotted' => 'decimal:2',
        'subscription_level' => 'decimal:2',
        'submitted_at' => 'datetime',
        'authorised_at' => 'datetime',
        'sec_reviewed_at' => 'datetime',
        'lodged_at' => 'datetime',
    ];

    protected $appends = ['method_label'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AllotmentItem::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OfferDocument::class);
    }

    public function config(): AllotmentConfig
    {
        $method = (array) $this->method;
        $method['final_rate'] = $this->final_rate;

        return AllotmentConfig::fromArray($method);
    }

    public function getMethodLabelAttribute(): string
    {
        try {
            return $this->config()->label();
        } catch (\Throwable $e) {
            return 'Not selected';
        }
    }

    /** The inputter can still change the schedule. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::RETURNED, self::SEC_REJECTED], true);
    }
}
