<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['provider_response'];

    protected function casts(): array
    {
        return ['provider_response' => 'array', 'verified_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function transferVerification(): HasOne
    {
        return $this->hasOne(TransferVerification::class);
    }
}
