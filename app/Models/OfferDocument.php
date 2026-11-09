<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferDocument extends Model
{
    public const DISK = 'documents';

    public const OFFER = 'offer';
    public const SEC_SUPPORT = 'sec_support';
    public const SPONSOR_LETTER = 'sponsor_letter';
    public const AMENDMENT = 'amendment';

    protected $fillable = [
        'offer_id', 'allotment_id', 'category', 'document_type', 'original_name', 'path', 'size',
        'visible_to_investors', 'uploaded_by',
    ];

    protected $hidden = ['path'];

    protected $casts = ['visible_to_investors' => 'boolean'];

    protected $appends = ['url'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return signedFileUrl('documents.show', $this);
    }
}
