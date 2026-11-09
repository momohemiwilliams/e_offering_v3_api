<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Every person who signs in. `type` decides the area of the app:
 *  admin     FMDQ staff (onboarding approvals, fees, settings, optional offer review)
 *  csd       FMDQ Depository / CSD users (lodgement downloads)
 *  tsp       Transaction Sponsor / Registration Member users (inputter | authoriser)
 *  investor  Retail investors and QII / Non-QII representatives
 *  regulator SEC / CBN reviewers
 */
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const TYPE_ADMIN = 'admin';
    public const TYPE_CSD = 'csd';
    public const TYPE_TSP = 'tsp';
    public const TYPE_INVESTOR = 'investor';
    public const TYPE_REGULATOR = 'regulator';

    public const INPUTTER = 'inputter';
    public const AUTHORISER = 'authoriser';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'institution_id',
        'type',
        'access_level',
        'name',
        'email',
        'phone',
        'password',
        'status',
        'email_verified_at',
        'privacy_consent_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'privacy_consent_at' => 'datetime',
        'last_login_at' => 'datetime',
        'locked_at' => 'datetime',
        'password_changed_at' => 'datetime',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function investorProfile(): HasOne
    {
        return $this->hasOne(InvestorProfile::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function isAdmin(): bool
    {
        return $this->type === self::TYPE_ADMIN;
    }

    public function isTsp(): bool
    {
        return $this->type === self::TYPE_TSP;
    }

    public function isInvestor(): bool
    {
        return $this->type === self::TYPE_INVESTOR;
    }

    public function isRegulator(): bool
    {
        return $this->type === self::TYPE_REGULATOR;
    }

    public function isCsd(): bool
    {
        return $this->type === self::TYPE_CSD;
    }

    public function isInputter(): bool
    {
        return $this->access_level === self::INPUTTER;
    }

    public function isAuthoriser(): bool
    {
        return $this->access_level === self::AUTHORISER;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** retail | qii | non_qii for investors, null otherwise. */
    public function investorCategory(): ?string
    {
        if (! $this->isInvestor()) {
            return null;
        }
        if ($this->institution) {
            return $this->institution->type;
        }

        return optional($this->investorProfile)->category ?? 'retail';
    }

    public function homeRoute(): string
    {
        $routes = [
            self::TYPE_ADMIN => 'admin.dashboard',
            self::TYPE_CSD => 'csd.lodgements.index',
            self::TYPE_TSP => 'tsp.dashboard',
            self::TYPE_INVESTOR => 'investor.dashboard',
            self::TYPE_REGULATOR => 'regulator.dashboard',
        ];

        return $routes[$this->type] ?? 'login';
    }

    public function roleLabel(): string
    {
        $labels = [
            self::TYPE_ADMIN => 'FMDQ Admin',
            self::TYPE_CSD => 'CSD / FMDQ Depository',
            self::TYPE_TSP => 'TSP/RM',
            self::TYPE_INVESTOR => 'Investor',
            self::TYPE_REGULATOR => 'Regulator',
        ];
        $label = $labels[$this->type] ?? ucfirst((string) $this->type);
        if ($this->type === self::TYPE_TSP && $this->access_level) {
            $label .= ' '.ucfirst($this->access_level);
        }
        if ($this->type === self::TYPE_INVESTOR) {
            $categories = ['retail' => 'Retail Investor', 'qii' => 'QII', 'non_qii' => 'Non-QII'];
            $label = $categories[$this->investorCategory()] ?? 'Investor';
        }
        if ($this->type === self::TYPE_REGULATOR && $this->institution) {
            $label = $this->institution->regulator_body.' Reviewer';
        }

        return $label;
    }
}
