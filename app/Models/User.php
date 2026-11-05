<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_PASSENGER = 'passenger';
    public const ROLE_OPERATOR_OWNER = 'operator_owner';
    public const ROLE_OPERATOR_AGENT = 'operator_agent';
    public const ROLE_PLATFORM_ADMIN = 'platform_admin';

    protected $fillable = ['name', 'username', 'phone', 'email', 'locale', 'adult_confirmed_at', 'phone_verified_at', 'email_verified_at'];

    protected $hidden = ['password', 'remember_token', 'google_id'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'adult_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /**
     * True when this user may see a booking: they made it while signed in, or it was booked
     * with their verified phone number. A missing phone never matches (anonymised bookings
     * also have no phone, so comparing two empty values would leak them).
     */
    public function owns(Booking $booking): bool
    {
        return $booking->user_id === $this->id
            || ($this->phone && $this->phone_verified_at && $booking->contact_phone === $this->phone);
    }

    /** Which sign-in methods this account has, for the account screen. */
    public function loginMethods(): array
    {
        return [
            'phone' => (bool) ($this->phone && $this->phone_verified_at),
            'password' => $this->password !== null,
            'google' => $this->google_id !== null,
        ];
    }

    public function isOperatorStaff(): bool
    {
        return in_array($this->role, [self::ROLE_OPERATOR_OWNER, self::ROLE_OPERATOR_AGENT], true);
    }

    public function routeNotificationForSms(): string
    {
        return $this->phone;
    }
}
