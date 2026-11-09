<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorProfile extends Model
{
    protected $fillable = [
        'user_id', 'category', 'bvn', 'nin', 'date_of_birth', 'address', 'csd_name', 'csd_account_number',
        'bank_name', 'bank_account_name', 'bank_account_number', 'bvn_payload', 'bvn_verified_at',
    ];

    protected $hidden = ['bvn_payload', 'bvn'];

    protected $casts = [
        'date_of_birth' => 'date',
        'bvn_payload' => 'array',
        'bvn_verified_at' => 'datetime',
    ];

    protected $appends = ['masked_bvn'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** BVN shown with only the last four digits. */
    public function getMaskedBvnAttribute(): ?string
    {
        return $this->bvn ? str_repeat('*', max(0, strlen($this->bvn) - 4)).substr($this->bvn, -4) : null;
    }
}
