<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable platform settings stored as JSON-encoded values.
 */
class Setting extends Model
{
    public const DEFAULTS = [
        // CR s4.4: FMDQ Admin review is built in but disabled by default.
        'fmdq_admin_review_enabled' => false,
        // Security types that need FMDQ admin review once it is enabled (empty = all).
        'fmdq_admin_review_security_types' => [],
        // CR s3.2: annual platform fee, not activated in the first year of launch.
        'platform_fee_enabled' => false,
        'platform_fee_amount' => 0,
        'platform_fee_bank_name' => '',
        'platform_fee_account_name' => 'FMDQ Securities Exchange Limited',
        'platform_fee_account_number' => '',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * @return mixed
     */
    public static function get(string $key)
    {
        $all = static::allValues();

        return array_key_exists($key, $all) ? $all[$key] : (self::DEFAULTS[$key] ?? null);
    }

    /**
     * @param mixed $value
     */
    public static function put(string $key, $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        Cache::forget('settings.all');
    }

    public static function allValues(): array
    {
        return Cache::rememberForever('settings.all', function () {
            $values = self::DEFAULTS;
            foreach (static::query()->get() as $row) {
                $values[$row->key] = json_decode((string) $row->value, true);
            }

            return $values;
        });
    }

    public static function adminReviewRequiredFor(string $securityType): bool
    {
        if (! static::get('fmdq_admin_review_enabled')) {
            return false;
        }
        $types = (array) static::get('fmdq_admin_review_security_types');

        return $types === [] || in_array($securityType, $types, true);
    }
}
