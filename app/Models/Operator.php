<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operator extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['partnership_signed_at' => 'datetime', 'commission_percent' => 'float'];
    }

    /** Operators whose trips can be sold. */
    public function scopeBookable(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** Operators that may be named publicly as partners (signed agreement + live). */
    public function scopePublicPartner(Builder $q): Builder
    {
        return $q->where('status', 'active')->whereNotNull('partnership_signed_at');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(OperatorAccount::class);
    }

    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function commission(): float
    {
        return $this->commission_percent ?? (float) config('bemengede.payments.platform_commission_percent');
    }
}
