<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['departure_at' => 'datetime', 'arrival_at' => 'datetime'];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class);
    }

    /** @return list<int> seats currently held or sold */
    public function unavailableSeats(): array
    {
        return $this->seatHolds()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('seat_number')->map(fn ($n) => (int) $n)->all();
    }

    public function seatsLeft(): int
    {
        return count($this->bus->sellableSeats()) - count($this->unavailableSeats());
    }
}
