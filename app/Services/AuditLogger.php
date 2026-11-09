<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes the end-to-end audit trail (CR s4.6, s6.1, s7): who did what, to which
 * record, from where. Rows are append-only.
 */
class AuditLogger
{
    public function log(string $action, string $description, ?Model $subject = null, array $properties = [], ?User $actor = null, ?int $offerId = null): ?AuditLog
    {
        try {
            $actor = $actor ?: Auth::user();
            $request = app()->runningInConsole() ? null : request();

            if ($offerId === null && $subject instanceof Offer) {
                $offerId = $subject->id;
            } elseif ($offerId === null && $subject && isset($subject->offer_id)) {
                $offerId = (int) $subject->offer_id;
            }

            return AuditLog::create([
                'user_id' => optional($actor)->id,
                'actor_name' => $actor ? $actor->name : 'System',
                'institution_id' => optional($actor)->institution_id,
                'offer_id' => $offerId,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject ? $subject->getKey() : null,
                'description' => mb_substr($description, 0, 255),
                'properties' => $properties ?: null,
                'ip_address' => $request ? $request->ip() : null,
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 1000) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
