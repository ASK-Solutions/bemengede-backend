<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Trip;
use App\Services\Pricing;

/** JSON shapes shared by the website and the app. Amounts are in santim. */
class Present
{
    public static function trip(Trip $t, bool $withSeats = false): array
    {
        $pricing = app(Pricing::class);
        $out = [
            'id' => $t->id,
            'operator' => ['name' => $t->operator->name, 'slug' => $t->operator->slug, 'brand_color' => $t->operator->brand_color,
                'free_cancel_hours' => $t->operator->free_cancel_hours, 'late_cancel_fee_percent' => $t->operator->late_cancel_fee_percent],
            'origin' => ['city' => $t->route->origin->city->name_en, 'terminal' => $t->route->origin->name],
            'destination' => ['city' => $t->route->destination->city->name_en, 'terminal' => $t->route->destination->name],
            'departure_at' => $t->departure_at->toIso8601String(),
            'arrival_at' => $t->arrival_at->toIso8601String(),
            'duration_minutes' => $t->route->duration_minutes,
            'bus_class' => $t->bus->class,
            'amenities' => $t->bus->amenities ?? [],
            'seats_left' => $t->seatsLeft(),
            'fare' => $t->base_fare,
            'service_fee_per_seat' => $pricing->serviceFeePerSeat(),
            // The headline price: fare + service fee. Nothing else is added later.
            'price_per_seat' => $pricing->allInAdultPrice($t),
        ];
        if ($withSeats) {
            $out['layout'] = $t->bus->layout;
            $out['seat_count'] = $t->bus->seat_count;
            $out['unavailable_seats'] = $t->unavailableSeats();
        }

        return $out;
    }

    public static function booking(Booking $b): array
    {
        $b->loadMissing('passengers', 'trip.route.origin.city', 'trip.route.destination.city', 'trip.operator', 'trip.bus', 'payments', 'operator.accounts');

        return [
            'reference' => $b->reference,
            'status' => $b->status,
            'trip' => self::trip($b->trip),
            'contact_phone_masked' => $b->contact_phone ? substr($b->contact_phone, 0, 7).'•••'.substr($b->contact_phone, -2) : null,
            'passengers' => $b->passengers->map(fn ($p) => [
                'full_name' => $p->full_name,
                'seat_number' => $p->seat_number,
                'fare_category' => $p->fare_category,
                'fare' => $p->fare,
                // Only expose the boarding QR once the booking is paid.
                'qr' => $b->isPaid() ? $b->reference.':'.$p->qr_token : null,
                'checked_in' => (bool) $p->checked_in_at,
            ]),
            'price' => ['subtotal' => $b->subtotal, 'discount' => $b->discount, 'service_fee' => $b->service_fee, 'total' => $b->total, 'currency' => $b->currency],
            'expires_at' => $b->expires_at?->toIso8601String(),
            'refund_amount' => $b->refund_amount,
            'payment' => optional($b->payments->last(), fn ($p) => ['provider' => $p->provider, 'status' => $p->status, 'tx_ref' => $p->tx_ref, 'checkout_url' => $p->checkout_url]),
            'transfer_accounts' => $b->operator->accounts->map(fn ($a) => ['bank' => $a->bank, 'account_name' => $a->account_name, 'account_number' => $a->account_number]),
        ];
    }
}
