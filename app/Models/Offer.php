<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * An issue created by a TSP/RM Inputter (CR s4).
 *
 * Workflow status: draft -> pending_authorisation -> (pending_admin_review) -> approved -> live.
 * "returned" sends it back to the inputter with a reason; "cancelled" ends it.
 * Bidding is open while live and open_at <= now <= close_at; after close the offer stays
 * "live" until allotment starts (amendments remain possible until then, s4.6).
 */
class Offer extends Model
{
    use HasFactory;

    public const DRAFT = 'draft';
    public const PENDING_AUTHORISATION = 'pending_authorisation';
    public const PENDING_ADMIN_REVIEW = 'pending_admin_review';
    public const RETURNED = 'returned';
    public const APPROVED = 'approved';
    public const LIVE = 'live';
    public const CANCELLED = 'cancelled';

    public const FIXED_RATE = 'fixed_rate';
    public const COMPETITIVE = 'competitive';
    public const BOOK_BUILDING = 'book_building';

    /** Fields the inputter may change after launch through an amendment (s4.6). */
    public const AMENDABLE = [
        'open_at', 'close_at', 'offer_size', 'min_subscription_threshold', 'min_subscription_amount',
        'subsequent_increment', 'rate', 'rate_min', 'rate_max', 'implied_yield', 'issue_price',
        'maturity_date', 'allotment_date', 'tenor', 'day_count_fraction', 'issue_description',
        'bank_name', 'bank_account_name', 'bank_account_number',
    ];

    protected $fillable = [
        'reference', 'institution_id', 'created_by', 'issuer_name', 'issue_description', 'issue_series',
        'offer_size', 'min_subscription_threshold', 'issuer_type', 'security_type', 'open_at', 'close_at',
        'min_subscription_amount', 'subsequent_increment', 'tenor', 'day_count_fraction',
        'price_discovery_method', 'investor_universe', 'rate', 'rate_min', 'rate_max', 'implied_yield',
        'issue_price', 'maturity_date', 'allotment_date', 'bank_name', 'bank_account_name',
        'bank_account_number', 'uses_receiving_agents', 'status', 'return_reason', 'submitted_at',
        'authorised_by', 'authorised_at', 'admin_reviewed_by', 'admin_reviewed_at', 'launched_by',
        'launched_at', 'cancelled_at', 'cancel_reason', 'final_rate',
    ];

    protected $casts = [
        'offer_size' => 'decimal:2',
        'min_subscription_threshold' => 'decimal:2',
        'min_subscription_amount' => 'decimal:2',
        'subsequent_increment' => 'decimal:2',
        'rate' => 'decimal:4',
        'rate_min' => 'decimal:4',
        'rate_max' => 'decimal:4',
        'implied_yield' => 'decimal:4',
        'issue_price' => 'decimal:4',
        'final_rate' => 'decimal:4',
        'investor_universe' => 'array',
        'uses_receiving_agents' => 'boolean',
        'open_at' => 'datetime',
        'close_at' => 'datetime',
        'maturity_date' => 'date',
        'allotment_date' => 'date',
        'submitted_at' => 'datetime',
        'authorised_at' => 'datetime',
        'admin_reviewed_at' => 'datetime',
        'launched_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $appends = ['phase', 'security_type_label', 'rate_label', 'price_discovery_label'];

    protected static function booted(): void
    {
        static::creating(function (Offer $offer) {
            if (! $offer->reference) {
                $offer->reference = 'FMDQ-'.Str::upper(substr($offer->security_type ?: 'X', 0, 2)).'-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
            }
        });
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OfferDocument::class);
    }

    public function coSponsors(): HasMany
    {
        return $this->hasMany(OfferCoSponsor::class);
    }

    public function receivingAgents(): HasMany
    {
        return $this->hasMany(OfferReceivingAgent::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(OfferAmendment::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function activeBids(): HasMany
    {
        return $this->hasMany(Bid::class)->where('status', Bid::ACTIVE);
    }

    public function allotment(): HasOne
    {
        return $this->hasOne(Allotment::class);
    }

    public function workflowActions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class);
    }

    /** Offers a TSP institution may see: its own plus those it co-sponsors. */
    public function scopeVisibleToInstitution(Builder $query, int $institutionId): Builder
    {
        return $query->where(function (Builder $q) use ($institutionId) {
            $q->where('institution_id', $institutionId)
                ->orWhereHas('coSponsors', function (Builder $c) use ($institutionId) {
                    $c->where('institution_id', $institutionId);
                });
        });
    }

    public function scopeOpenForBidding(Builder $query): Builder
    {
        return $query->where('status', self::LIVE)->where('open_at', '<=', now())->where('close_at', '>=', now())
            ->whereDoesntHave('allotment');
    }

    public function isOpenForBidding(): bool
    {
        return $this->status === self::LIVE && $this->open_at <= now() && $this->close_at >= now() && ! $this->allotmentStarted();
    }

    public function allotmentStarted(): bool
    {
        return $this->relationLoaded('allotment') ? $this->allotment !== null : $this->allotment()->exists();
    }

    /** Amendments allowed from launch up to the start of allotment (s4.6). */
    public function canBeAmended(): bool
    {
        return $this->status === self::LIVE && ! $this->allotmentStarted();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::RETURNED], true);
    }

    /**
     * Human-facing phase, combining workflow status with dates and allotment.
     */
    public function getPhaseAttribute(): string
    {
        if ($this->status !== self::LIVE) {
            return (string) $this->status;
        }
        $allotment = $this->relationLoaded('allotment') ? $this->allotment : null;
        if ($allotment) {
            return 'allotment_'.$allotment->status;
        }
        if ($this->open_at && $this->open_at->isFuture()) {
            return 'upcoming';
        }
        if ($this->close_at && $this->close_at->isPast()) {
            return 'closed';
        }

        return 'open';
    }

    public function getSecurityTypeLabelAttribute(): string
    {
        return config('eoffering.security_types.'.$this->security_type.'.label', (string) $this->security_type);
    }

    public function getPriceDiscoveryLabelAttribute(): string
    {
        return config('eoffering.price_discovery_methods.'.$this->price_discovery_method, (string) $this->price_discovery_method);
    }

    /** Coupon / Rental / Profit-Sharing / Discount Rate / Share Price, by security type. */
    public function getRateLabelAttribute(): string
    {
        $labels = [
            'equities' => 'Share Price',
            'depository_receipts' => 'Share Price',
            'commercial_papers' => 'Discount Rate',
            'non_interest' => 'Rental / Profit-Sharing Rate',
            'bonds' => 'Coupon Rate',
            'private_companies' => 'Coupon Rate',
        ];

        return $labels[$this->security_type] ?? 'Rate';
    }

    public function isPriceBased(): bool
    {
        return in_array($this->security_type, ['equities', 'depository_receipts'], true);
    }

    /** Priced securities favour the highest bid; yields and rates favour the lowest. */
    public function ranksHighestFirst(): bool
    {
        return in_array($this->security_type, ['equities', 'depository_receipts'], true);
    }

    public function needsFinalRate(): bool
    {
        return in_array($this->price_discovery_method, [self::COMPETITIVE, self::BOOK_BUILDING], true);
    }

    /** Payee choices shown on the commitment form (s6.2). */
    public function payeeOptions(): array
    {
        $options = [];
        foreach ($this->receivingAgents as $agent) {
            $options[] = [
                'type' => 'receiving_agent', 'id' => $agent->id, 'name' => $agent->name.' (Receiving Agent)',
                'bank_name' => $agent->bank_name, 'account_name' => $agent->bank_account_name,
                'account_number' => $agent->bank_account_number, 'institution_id' => null,
            ];
        }
        foreach ($this->coSponsors as $co) {
            if ($co->bank_account_number) {
                $options[] = [
                    'type' => 'co_sponsor', 'id' => $co->id, 'name' => optional($co->institution)->name.' (Co-Sponsor)',
                    'bank_name' => $co->bank_name, 'account_name' => $co->bank_account_name,
                    'account_number' => $co->bank_account_number, 'institution_id' => $co->institution_id,
                ];
            }
        }
        if ($options === []) {
            $options[] = [
                'type' => 'sponsor', 'id' => null, 'name' => optional($this->institution)->name.' (Sponsor)',
                'bank_name' => $this->bank_name, 'account_name' => $this->bank_account_name,
                'account_number' => $this->bank_account_number, 'institution_id' => $this->institution_id,
            ];
        }

        return $options;
    }
}
