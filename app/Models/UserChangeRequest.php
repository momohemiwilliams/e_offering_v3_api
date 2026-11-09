<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff account change waiting for a second admin (legacy maker-checker). */
class UserChangeRequest extends Model
{
    public const PENDING = 'pending';

    protected $guarded = [];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
