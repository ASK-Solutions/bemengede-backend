<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bus extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['layout' => 'array', 'amenities' => 'array'];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @return list<int> seat numbers that can be sold */
    public function sellableSeats(): array
    {
        $blocked = $this->layout['blocked'] ?? [];

        return array_values(array_diff(range(1, $this->seat_count), $blocked));
    }
}
