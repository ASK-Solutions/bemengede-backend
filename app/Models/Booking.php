<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Booking extends Model
{
    public const PENDING = 'pending_payment';
    public const AWAITING_VERIFICATION = 'awaiting_verification';
    public const CONFIRMED = 'confirmed';
    public const CHECKED_IN = 'checked_in';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const REFUNDED = 'refunded';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public static function newReference(): string
    {
        // No 0/O/1/I to avoid misreading on the phone.
        do {
            $ref = 'BM-'.collect(str_split(Str::upper(Str::random(12))))
                ->filter(fn ($c) => ! in_array($c, ['0', 'O', '1', 'I'], true))->take(6)->implode('');
        } while (strlen($ref) < 9 || static::where('reference', $ref)->exists());

        return $ref;
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [self::CONFIRMED, self::CHECKED_IN], true);
    }
}
