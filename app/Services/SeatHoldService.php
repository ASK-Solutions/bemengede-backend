<?php

namespace App\Services;

use App\Models\SeatHold;
use App\Models\Trip;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SeatHoldService
{
    /**
     * Hold seats for a booking. The unique (trip_id, seat_number) index makes
     * double-selling impossible even under concurrent requests.
     *
     * @param  list<int>  $seats
     */
    public function hold(Trip $trip, array $seats, int $bookingId, int $minutes): void
    {
        $sellable = $trip->bus->sellableSeats();
        foreach ($seats as $s) {
            if (! in_array($s, $sellable, true)) {
                throw ValidationException::withMessages(['seats' => "Seat {$s} does not exist on this bus."]);
            }
        }

        DB::transaction(function () use ($trip, $seats, $bookingId, $minutes) {
            // Clear expired holds on these seats first so they can be reused.
            SeatHold::where('trip_id', $trip->id)->whereIn('seat_number', $seats)
                ->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();

            try {
                foreach ($seats as $s) {
                    SeatHold::create([
                        'trip_id' => $trip->id,
                        'seat_number' => $s,
                        'booking_id' => $bookingId,
                        'expires_at' => now()->addMinutes($minutes),
                    ]);
                }
            } catch (QueryException $e) {
                throw ValidationException::withMessages(['seats' => 'Someone else just took one of these seats. Please choose again.']);
            }
        });
    }

    public function markSold(int $bookingId): void
    {
        SeatHold::where('booking_id', $bookingId)->update(['expires_at' => null]);
    }

    public function extend(int $bookingId, int $minutes): void
    {
        SeatHold::where('booking_id', $bookingId)->whereNotNull('expires_at')
            ->update(['expires_at' => now()->addMinutes($minutes)]);
    }

    public function release(int $bookingId): void
    {
        SeatHold::where('booking_id', $bookingId)->delete();
    }
}
