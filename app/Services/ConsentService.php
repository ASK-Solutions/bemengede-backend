<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Consent;
use App\Models\MarketingSubscription;
use App\Models\User;
use Illuminate\Support\Str;

class ConsentService
{
    public function recordBookingConsents(Booking $booking, ?User $user, string $source): void
    {
        foreach ([Consent::TERMS => 'terms', Consent::PRIVACY => 'privacy'] as $type => $doc) {
            Consent::create([
                'user_id' => $user?->id,
                'booking_id' => $booking->id,
                'type' => $type,
                'version' => config("bemengede.legal.{$doc}.version"),
                'granted' => true,
                'source' => $source,
            ]);
        }
    }

    /** Terms and privacy accepted when an account is created (any sign-up method). */
    public function recordAccountConsents(User $user, string $source): void
    {
        foreach ([Consent::TERMS => 'terms', Consent::PRIVACY => 'privacy'] as $type => $doc) {
            Consent::create([
                'user_id' => $user->id,
                'type' => $type,
                'version' => config("bemengede.legal.{$doc}.version"),
                'granted' => true,
                'source' => $source,
            ]);
        }
    }

    /** Opt-in only. Saying "no" never creates a subscription. */
    public function setMarketing(?User $user, ?Booking $booking, string $channel, string $address, bool $granted, string $source): ?MarketingSubscription
    {
        $type = $channel === 'sms' ? Consent::MARKETING_SMS : Consent::MARKETING_EMAIL;
        $sub = MarketingSubscription::where('channel', $channel)->where('address', $address)->first();

        if (! $granted && ! $sub) {
            return null; // nothing to record: never subscribed
        }

        Consent::create([
            'user_id' => $user?->id,
            'booking_id' => $booking?->id,
            'type' => $type,
            'version' => config('bemengede.legal.privacy.version'),
            'granted' => $granted,
            'source' => $source,
        ]);

        if ($granted) {
            return MarketingSubscription::updateOrCreate(
                ['channel' => $channel, 'address' => $address],
                ['user_id' => $user?->id, 'token' => $sub?->token ?? Str::random(48), 'subscribed_at' => now(), 'unsubscribed_at' => null],
            );
        }

        $sub->update(['unsubscribed_at' => now()]);

        return $sub;
    }

    public function unsubscribe(MarketingSubscription $sub): void
    {
        if ($sub->isActive()) {
            $sub->update(['unsubscribed_at' => now()]);
            Consent::create([
                'user_id' => $sub->user_id,
                'type' => $sub->channel === 'sms' ? Consent::MARKETING_SMS : Consent::MARKETING_EMAIL,
                'version' => config('bemengede.legal.privacy.version'),
                'granted' => false,
                'source' => 'web',
            ]);
        }
    }

    /** @return array<string, array{granted:bool, version:string, at:string}> latest decision per type */
    public function current(User $user): array
    {
        return $user->consents()->orderBy('id')->get()
            ->groupBy('type')
            ->map(fn ($rows) => [
                'granted' => $rows->last()->granted,
                'version' => $rows->last()->version,
                'at' => $rows->last()->created_at->toIso8601String(),
            ])->all();
    }
}
