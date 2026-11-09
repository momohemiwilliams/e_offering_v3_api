<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An onboarded organisation: TSP/RM, QII, Non-QII or Regulator (SEC/CBN).
 */
class Institution extends Model
{
    use HasFactory;

    public const TYPE_TSP = 'tsp';
    public const TYPE_QII = 'qii';
    public const TYPE_NON_QII = 'non_qii';
    public const TYPE_REGULATOR = 'regulator';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'reference', 'type', 'name', 'email', 'phone', 'address', 'registration_number', 'regulator_body',
        'proof_of_registration_path', 'documents', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
        'privacy_consent_at', 'registered_name', 'registered_address', 'contact_name', 'contact_role',
        'contact_phone', 'registration_completed_at', 'bank_name', 'bank_account_name', 'bank_account_number',
        'fee_paid_until', 'suspended_at',
    ];

    protected $casts = [
        'documents' => 'array',
        'reviewed_at' => 'datetime',
        'privacy_consent_at' => 'datetime',
        'registration_completed_at' => 'datetime',
        'fee_paid_until' => 'date',
        'suspended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Institution $institution) {
            if (! $institution->reference) {
                $prefixes = ['tsp' => 'TSP', 'qii' => 'QII', 'non_qii' => 'NQI', 'regulator' => 'REG'];
                $prefix = $prefixes[$institution->type] ?? 'INS';
                $institution->reference = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function feeInvoices(): HasMany
    {
        return $this->hasMany(PlatformFeeInvoice::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED || $this->suspended_at !== null;
    }

    public function typeLabel(): string
    {
        $labels = [
            self::TYPE_TSP => 'TSP/RM',
            self::TYPE_QII => 'QII',
            self::TYPE_NON_QII => 'Non-QII',
            self::TYPE_REGULATOR => 'Regulator',
        ];

        return ($labels[$this->type] ?? $this->type).($this->regulator_body ? ' ('.$this->regulator_body.')' : '');
    }
}
