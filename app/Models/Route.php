<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Route extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stops' => 'array'];
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'origin_terminal_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'destination_terminal_id');
    }
}
