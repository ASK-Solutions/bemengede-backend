<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\MarketingSubscription;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrivacyService
{
    public function __construct(private ConsentService $consents) {}

    /** Everything we hold about a person, as plain JSON they can download. */
    public function export(User $user): array
    {
        $bookings = Booking::with('passengers', 'payments', 'trip.route.origin.city', 'trip.route.destination.city', 'operator')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('contact_phone', $user->phone))
            ->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'account' => $user->only(['name', 'phone', 'email', 'locale', 'created_at', 'adult_confirmed_at']),
            'consents' => Consent::where('user_id', $user->id)->orderBy('id')->get(['type', 'version', 'granted', 'source', 'created_at']),
            'marketing_subscriptions' => MarketingSubscription::whereIn('address', array_filter([$user->phone, $user->email]))
                ->get(['channel', 'subscribed_at', 'unsubscribed_at']),
            'bookings' => $bookings->map(fn (Booking $b) => [
                'reference' => $b->reference,
                'operator' => $b->operator->name,
                'route' => $b->trip->route->origin->city->name_en.' → '.$b->trip->route->destination->city->name_en,
                'departure_at' => $b->trip->departure_at->toIso8601String(),
                'status' => $b->status,
                'total_etb' => $b->total / 100,
                'contact_phone' => $b->contact_phone,
                'contact_email' => $b->contact_email,
                'passengers' => $b->passengers->map->only(['full_name', 'seat_number', 'fare_category']),
                'payments' => $b->payments->map->only(['provider', 'amount', 'status', 'created_at']),
            ]),
        ];
    }

    public function blockingBookings(User $user)
    {
        return Booking::where(fn ($q) => $q->where('user_id', $user->id)->orWhere('contact_phone', $user->phone))
            ->whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN, Booking::AWAITING_VERIFICATION])
            ->whereHas('trip', fn ($q) => $q->where('departure_at', '>', now()))
            ->pluck('reference');
    }

    public function scheduleDeletion(User $user): PrivacyRequest
    {
        $blocking = $this->blockingBookings($user);
        if ($blocking->isNotEmpty()) {
            throw ValidationException::withMessages([
                'account' => 'You have upcoming trips ('.$blocking->implode(', ').'). Cancel them or wait until after travel, then delete your account.',
            ]);
        }

        // Withdraw marketing immediately — no need to wait for the cooling-off period.
        MarketingSubscription::whereIn('address', array_filter([$user->phone, $user->email]))
            ->get()->each(fn ($s) => $this->consents->unsubscribe($s));

        return PrivacyRequest::updateOrCreate(
            ['user_id' => $user->id, 'type' => 'delete', 'status' => 'scheduled'],
            ['run_after' => now()->addHours(config('bemengede.privacy.deletion_cooling_off_hours'))],
        );
    }

    /**
     * Erase personal data. Booking and payment amounts are kept without names or
     * contact details, because accounting law requires keeping financial records.
     */
    public function erase(User $user): void
    {
        DB::transaction(function () use ($user) {
            $bookings = Booking::where(fn ($q) => $q->where('user_id', $user->id)->orWhere('contact_phone', $user->phone))->get();
            foreach ($bookings as $b) {
                $b->passengers()->update(['full_name' => 'Deleted passenger']);
                $b->update(['user_id' => null, 'contact_phone' => null, 'contact_email' => null, 'anonymized_at' => now()]);
                $b->review()?->update(['comment' => null]);
            }
            MarketingSubscription::whereIn('address', array_filter([$user->phone, $user->email]))->delete();
            AuditLog::where('user_id', $user->id)->update(['user_id' => null]);
            $user->tokens()->delete();
            $user->delete(); // consents cascade
        });
    }
}
