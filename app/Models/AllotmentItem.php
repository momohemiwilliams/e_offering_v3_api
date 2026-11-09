<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllotmentItem extends Model
{
    protected $fillable = [
        'allotment_id', 'bid_id', 'subscribed', 'allotted', 'units', 'released', 'filled_pct', 'eligible',
        'ineligible_reason', 'override_amount', 'override_reason', 'overridden_by', 'overridden_at',
    ];

    protected $casts = [
        'subscribed' => 'decimal:2',
        'allotted' => 'decimal:2',
        'units' => 'decimal:4',
        'released' => 'decimal:2',
        'filled_pct' => 'decimal:2',
        'override_amount' => 'decimal:2',
        'eligible' => 'boolean',
        'overridden_at' => 'datetime',
    ];

    public function allotment(): BelongsTo
    {
        return $this->belongsTo(Allotment::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
