<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only consent log: a new row for every grant or withdrawal,
 * so we can always show what someone agreed to and when.
 */
class Consent extends Model
{
    public const UPDATED_AT = null;

    public const TERMS = 'terms';
    public const PRIVACY = 'privacy';
    public const MARKETING_SMS = 'marketing_sms';
    public const MARKETING_EMAIL = 'marketing_email';
    public const COOKIES_ANALYTICS = 'cookies_analytics';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }
}
