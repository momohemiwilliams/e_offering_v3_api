<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferCoSponsor extends Model
{
    protected $fillable = [
        'offer_id', 'institution_id', 'can_view_order_book', 'can_confirm_payments', 'bank_name',
        'bank_account_name', 'bank_account_number',
    ];

    protected $casts = [
        'can_view_order_book' => 'boolean',
        'can_confirm_payments' => 'boolean',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
