<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Complete history of a bid (submission, amendments, revocation, payment review).
 */
class BidEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['bid_id', 'user_id', 'action', 'before', 'after', 'comment', 'created_at'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
