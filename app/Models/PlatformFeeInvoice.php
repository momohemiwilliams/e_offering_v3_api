<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlatformFeeInvoice extends Model
{
    public const UNPAID = 'unpaid';
    public const PENDING_REVIEW = 'pending_review';
    public const PAID = 'paid';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'reference', 'institution_id', 'period_start', 'period_end', 'due_date', 'amount', 'method', 'status',
        'gateway_reference', 'gateway_response', 'evidence_path', 'paid_at', 'reviewed_by', 'reviewed_at',
        'review_comment',
    ];

    protected $hidden = ['gateway_response'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PlatformFeeInvoice $invoice) {
            if (! $invoice->reference) {
                $invoice->reference = 'FMDQ-FEE-'.now()->format('Y').'-'.Str::upper(Str::random(6));
            }
        });
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::UNPAID, self::REJECTED], true);
    }
}
