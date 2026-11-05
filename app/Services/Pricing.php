<?php

namespace App\Services;

use App\Models\Trip;

/**
 * One place that computes every price, so search results, checkout and the
 * receipt always show the same all-in number (no fees added at the end).
 */
class Pricing
{
    public function serviceFeePerSeat(): int
    {
        return (int) config('bemengede.pricing.service_fee_per_seat');
    }

    public function fareFor(Trip $trip, string $category): int
    {
        $pct = match ($category) {
            'child' => config('bemengede.pricing.child_discount_percent'),
            'student' => config('bemengede.pricing.student_discount_percent'),
            default => 0,
        };

        return (int) round($trip->base_fare * (100 - $pct) / 100);
    }

    /** Price per adult seat including the service fee — the number shown everywhere first. */
    public function allInAdultPrice(Trip $trip): int
    {
        return $trip->base_fare + $this->serviceFeePerSeat();
    }

    /**
     * @param  list<array{fare_category:string}>  $passengers
     * @return array{subtotal:int, discount:int, service_fee:int, total:int, lines:list<array>}
     */
    public function quote(Trip $trip, array $passengers): array
    {
        $lines = [];
        $subtotal = 0;
        $discount = 0;
        foreach ($passengers as $p) {
            $fare = $this->fareFor($trip, $p['fare_category'] ?? 'adult');
            $lines[] = ['fare_category' => $p['fare_category'] ?? 'adult', 'fare' => $fare];
            $subtotal += $trip->base_fare;
            $discount += $trip->base_fare - $fare;
        }
        $fee = $this->serviceFeePerSeat() * count($passengers);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'service_fee' => $fee,
            'total' => $subtotal - $discount + $fee,
            'lines' => $lines,
        ];
    }
}
