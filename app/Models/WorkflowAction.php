<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One approval-chain step on an offer, amendment or allotment. Rejections here
 * feed the exception report (offers returned by the TSP Authoriser, SEC or FMDQ Admin).
 */
class WorkflowAction extends Model
{
    public const STAGE_INPUTTER = 'inputter';
    public const STAGE_AUTHORISER = 'tsp_authoriser';
    public const STAGE_ADMIN = 'fmdq_admin';
    public const STAGE_SEC = 'sec';

    public $timestamps = false;

    protected $fillable = ['subject_type', 'subject_id', 'offer_id', 'stage', 'decision', 'reason', 'user_id', 'user_name', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public static function record(Model $subject, ?int $offerId, string $stage, string $decision, ?string $reason, ?User $user): self
    {
        return static::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'offer_id' => $offerId,
            'stage' => $stage,
            'decision' => $decision,
            'reason' => $reason,
            'user_id' => optional($user)->id,
            'user_name' => optional($user)->name,
            'created_at' => now(),
        ]);
    }
}
