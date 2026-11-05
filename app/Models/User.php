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

    protected $fillable = ['name', 'phone', 'email', 'locale', 'adult_confirmed_at', 'phone_verified_at'];

    protected $hidden = ['password', 'remember_token'];

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

    public function isOperatorStaff(): bool
    {
        return in_array($this->role, [self::ROLE_OPERATOR_OWNER, self::ROLE_OPERATOR_AGENT], true);
    }

    public function routeNotificationForSms(): string
    {
        return $this->phone;
    }
}
