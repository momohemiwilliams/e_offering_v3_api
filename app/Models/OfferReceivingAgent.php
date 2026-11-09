<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferReceivingAgent extends Model
{
    protected $fillable = ['offer_id', 'name', 'bank_name', 'bank_account_name', 'bank_account_number'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
