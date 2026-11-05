<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferVerification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'paid_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
