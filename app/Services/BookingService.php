<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Passenger;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private Pricing $pricing,
        private SeatHoldService $holds,
        private ConsentService $consents,
    ) {}

    /**
     * @param  array{contact_phone:string, contact_email?:?string, passengers:list<array>, marketing_sms?:bool, marketing_email?:bool, channel?:string}  $data
     */
    public function create(Trip $trip, array $data, ?User $user): Booking
    {
        if ($trip->operator->status !== 'active' || $trip->departure_at->isPast() || $trip->status !== 'scheduled') {
            throw ValidationException::withMessages(['trip' => 'This trip can no longer be booked.']);
        }

        $quote = $this->pricing->quote($trip, $data['passengers']);
        $source = $data['channel'] ?? 'web';

        return DB::transaction(function () use ($trip, $data, $user, $quote, $source) {
            $booking = Booking::create([
                'reference' => Booking::newReference(),
                'operator_id' => $trip->operator_id,
                'trip_id' => $trip->id,
                'user_id' => $user?->id,
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'] ?? null,
                'status' => Booking::PENDING,
                'subtotal' => $quote['subtotal'],
                'discount' => $quote['discount'],
                'service_fee' => $quote['service_fee'],
                'total' => $quote['total'],
                'channel' => $source,
                'terms_version' => config('bemengede.legal.terms.version'),
                'privacy_version' => config('bemengede.legal.privacy.version'),
                'expires_at' => now()->addMinutes(config('bemengede.booking.hold_minutes')),
            ]);

            $seats = array_map(fn ($p) => (int) $p['seat_number'], $data['passengers']);
            $this->holds->hold($trip, $seats, $booking->id, config('bemengede.booking.hold_minutes'));

            foreach ($data['passengers'] as $i => $p) {
                Passenger::create([
                    'booking_id' => $booking->id,
                    'full_name' => trim($p['full_name']),
                    'seat_number' => (int) $p['seat_number'],
                    'fare_category' => $p['fare_category'] ?? 'adult',
                    'fare' => $quote['lines'][$i]['fare'],
                    'qr_token' => Str::random(32),
                ]);
            }

            // Required consents (terms + privacy) are recorded against the booking.
            // Marketing is separate, optional and off unless the person ticked it.
            $this->consents->recordBookingConsents($booking, $user, $source);
            $this->consents->setMarketing($user, $booking, 'sms', $data['contact_phone'], (bool) ($data['marketing_sms'] ?? false), $source);
            if (! empty($data['contact_email'])) {
                $this->consents->setMarketing($user, $booking, 'email', $data['contact_email'], (bool) ($data['marketing_email'] ?? false), $source);
            }

            return $booking->load('passengers', 'trip.route.origin.city', 'trip.route.destination.city', 'operator');
        });
    }

    public function confirm(Booking $booking): void
    {
        if ($booking->isPaid()) {
            return; // idempotent: webhooks and polling may both arrive
        }
        DB::transaction(function () use ($booking) {
            $booking->update(['status' => Booking::CONFIRMED, 'expires_at' => null]);
            $this->holds->markSold($booking->id);
        });
        AuditLog::record('booking.confirmed', $booking, [], $booking->operator_id);

        // Transactional message (not marketing): always sent, no unsubscribe needed.
        Notification::route('sms', $booking->contact_phone)
            ->route('mail', $booking->contact_email)
            ->notify(new BookingConfirmed($booking));
    }

    /**
     * A payment arrived after the hold ran out. Take the same seats again if they are
     * still free; otherwise the caller must refund the customer in full.
     */
    public function revive(Booking $booking): bool
    {
        if ($booking->status !== Booking::EXPIRED) {
            return true;
        }
        try {
            $this->holds->hold($booking->trip()->with('bus')->first(), $booking->passengers->pluck('seat_number')->map(fn ($n) => (int) $n)->all(), $booking->id, 5);
            $booking->update(['status' => Booking::PENDING]);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function expire(Booking $booking): void
    {
        if ($booking->status !== Booking::PENDING && $booking->status !== Booking::AWAITING_VERIFICATION) {
            return;
        }
        $booking->update(['status' => Booking::EXPIRED]);
        $this->holds->release($booking->id);
    }

    /**
     * Cancellation follows the operator's published policy:
     * full refund of fares until N hours before departure, then a fee.
     * The service fee is refunded too when the operator cancels the trip.
     *
     * @return array{refund:int, fee:int, allowed:bool, reason?:string}
     */
    public function cancellationQuote(Booking $booking): array
    {
        $trip = $booking->trip;
        if (! $booking->isPaid()) {
            return ['allowed' => true, 'refund' => 0, 'fee' => 0];
        }
        if ($trip->departure_at->isPast()) {
            return ['allowed' => false, 'refund' => 0, 'fee' => 0, 'reason' => 'The bus has already departed.'];
        }
        $fares = $booking->subtotal - $booking->discount;
        $hoursLeft = now()->diffInHours($trip->departure_at, false);
        $feePct = $hoursLeft >= $booking->operator->free_cancel_hours ? 0 : $booking->operator->late_cancel_fee_percent;
        $fee = (int) round($fares * $feePct / 100);

        return ['allowed' => true, 'refund' => $fares - $fee, 'fee' => $fee];
    }

    public function cancel(Booking $booking, string $by = 'customer'): array
    {
        $quote = $this->cancellationQuote($booking);
        if (! $quote['allowed']) {
            throw ValidationException::withMessages(['booking' => $quote['reason']]);
        }
        DB::transaction(function () use ($booking, $quote) {
            $booking->update([
                'status' => Booking::CANCELLED,
                'cancelled_at' => now(),
                'refund_amount' => $quote['refund'],
            ]);
            $this->holds->release($booking->id);
        });
        AuditLog::record('booking.cancelled', $booking, ['by' => $by] + $quote, $booking->operator_id);

        return $quote;
    }
}
