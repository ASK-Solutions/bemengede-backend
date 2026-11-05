<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingSubscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['subscribed_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->unsubscribed_at === null;
    }

    public function unsubscribeUrl(): string
    {
        return rtrim(config('bemengede.frontend_url'), '/').'/unsubscribe/'.$this->token;
    }
}
