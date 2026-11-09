<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * An investor commitment (CR s6). It reaches the order book immediately on submission,
 * whatever its payment status (s6.1).
 */
class Bid extends Model
{
    use HasFactory;

    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_INVALID = 'invalid';

    public const EVIDENCE_DISK = 'documents';

    protected $fillable = [
        'reference', 'offer_id', 'user_id', 'institution_id', 'investor_category', 'investor_type',
        'investor_name', 'amount', 'rate', 'yield', 'csd_name', 'csd_account_number', 'bank_name',
        'bank_account_name', 'bank_account_number', 'payee_type', 'payee_id', 'payee_name', 'payee_bank_name',
        'payee_account_name', 'payee_account_number', 'placed_through_institution_id', 'payment_status',
        'payment_reference', 'payment_evidence_path', 'payment_uploaded_at', 'payment_comment',
        'payment_reviewed_by', 'payment_reviewed_at', 'status', 'submitted_at', 'revoked_at',
    ];

    protected $hidden = ['payment_evidence_path'];

    protected $casts = [
        'amount' => 'decimal:2',
        'rate' => 'decimal:4',
        'yield' => 'decimal:4',
        'submitted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'payment_uploaded_at' => 'datetime',
        'payment_reviewed_at' => 'datetime',
    ];

    protected $appends = ['has_evidence', 'investor_type_label'];

    protected static function booted(): void
    {
        static::creating(function (Bid $bid) {
            if (! $bid->reference) {
                $bid->reference = 'BID-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
            }
        });
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function placedThrough(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'placed_through_institution_id');
    }

    public function paymentReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_reviewed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BidEvent::class)->orderBy('id');
    }

    public function allotmentItem(): HasOne
    {
        return $this->hasOne(AllotmentItem::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Investors may amend or revoke while the offer is still open (s6.1 step 4). */
    public function canBeChangedByInvestor(): bool
    {
        return $this->isActive() && $this->offer->isOpenForBidding();
    }

    public function getHasEvidenceAttribute(): bool
    {
        return (bool) $this->payment_evidence_path;
    }

    public function getInvestorTypeLabelAttribute(): string
    {
        return config('eoffering.investor_types.'.$this->investor_type, (string) $this->investor_type);
    }
}
