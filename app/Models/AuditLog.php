<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable, time-stamped record of an action (CR s7 end-to-end audit trail).
 * Rows are only ever created (see App\Services\AuditLogger), never edited.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'actor_name', 'institution_id', 'offer_id', 'action', 'subject_type', 'subject_id',
        'description', 'properties', 'ip_address', 'user_agent', 'created_at',
    ];

    protected $hidden = ['user_agent'];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
