<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user from a rejected onboarding request, kept for the record after being removed
 * from `users` so the email address can register again.
 */
class RejectedOnboardingUser extends Model
{
    protected $fillable = [
        'institution_id', 'original_user_id', 'type', 'access_level', 'name', 'email', 'phone',
        'privacy_consent_at', 'submitted_at', 'rejected_at',
    ];

    protected $casts = [
        'privacy_consent_at' => 'datetime',
        'submitted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /** Lets the admin users table show these rows alongside live ones. */
    protected $appends = ['status'];

    public function getStatusAttribute(): string
    {
        return 'rejected';
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
