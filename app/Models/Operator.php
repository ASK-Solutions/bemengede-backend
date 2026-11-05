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

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OperatorDocument::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Onboarding checklist shown to the platform admin. Each step is derived
     * from real data, so it can't be ticked by hand.
     *
     * @return list<array{key:string, label:string, done:bool}>
     */
    public function checklist(): array
    {
        $docs = $this->documents()->where('status', 'approved')->pluck('type')->all();
        $steps = [
            ['application', 'Application submitted', in_array($this->status, ['applied', 'under_review', 'active', 'suspended'], true)],
            ['legal', 'Legal name, TIN and trade licence recorded', $this->legal_name && $this->tin && $this->trade_license_no],
            ['documents', 'Trade licence and operating licence approved', in_array('trade_license', $docs, true) && in_array('operating_license', $docs, true)],
            ['owner', 'Owner account created', $this->staff()->where('role', User::ROLE_OPERATOR_OWNER)->exists()],
            ['accounts', 'Receiving bank or wallet account added', $this->accounts()->exists()],
            ['chapa', 'Chapa subaccount linked', (bool) $this->chapa_subaccount_id],
            ['fleet', 'At least one bus and one route added', $this->buses()->exists() && $this->routes()->exists()],
            ['agreement', 'Partnership agreement signed', (bool) $this->partnership_signed_at],
        ];

        return array_map(fn ($s) => ['key' => $s[0], 'label' => $s[1], 'done' => (bool) $s[2]], $steps);
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
