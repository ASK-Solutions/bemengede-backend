<?php

namespace App\Http\Controllers\Operator;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Trip;
use App\Services\BookingService;
use App\Support\Phone;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingController extends OperatorScoped
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $phone = Phone::normalize($q);
        $rows = Booking::with('trip.route.origin.city', 'trip.route.destination.city', 'passengers')
            ->where('operator_id', $this->opId($request))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('reference', strtoupper($q))
                ->when($phone, fn ($y) => $y->orWhere('contact_phone', $phone))
                ->orWhereHas('passengers', fn ($p) => $p->where('full_name', 'like', '%'.$q.'%'))))
            ->latest()->limit(50)->get()
            ->map(fn (Booking $b) => [
                'reference' => $b->reference,
                'status' => $b->status,
                'channel' => $b->channel,
                'route' => $b->trip->route->origin->city->name_en.' → '.$b->trip->route->destination->city->name_en,
                'departure_at' => $b->trip->departure_at->toIso8601String(),
                'passengers' => $b->passengers->map->only(['id', 'full_name', 'seat_number']),
                'contact_phone' => $b->contact_phone,
                'total' => $b->total,
            ]);
        AuditLog::record('operator.bookings.search', null, ['q_length' => strlen($q)]);

        return response()->json($rows);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $b = Booking::where('reference', strtoupper($reference))->first();
        $this->own($request, $b);

        return response()->json(Present::booking($b) + ['contact_phone' => $b->contact_phone, 'channel' => $b->channel,
            'passenger_ids' => $b->passengers->pluck('id')]);
    }

    /** Fix a misspelled passenger name (common at the counter). */
    public function updatePassenger(Request $request, Passenger $passenger): JsonResponse
    {
        $this->own($request, $passenger->booking);
        $data = $request->validate(['full_name' => ['required', 'string', 'min:2', 'max:80']]);
        $old = $passenger->full_name;
        $passenger->update($data);
        AuditLog::record('passenger.renamed', $passenger, ['from' => $old, 'to' => $data['full_name']]);

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, string $reference, BookingService $service): JsonResponse
    {
        $b = Booking::where('reference', strtoupper($reference))->first();
        $this->own($request, $b);
        $request->validate(['reason' => ['required', 'string', 'max:160']]);

        return response()->json($service->cancel($b, 'operator:'.$request->user()->id.' '.$request->reason));
    }

    /**
     * Counter sale: the agent sells for cash at the terminal. The agent confirms the customer
     * was shown the terms; the same price rules and seat locks apply as online.
     */
    public function counterSale(Request $request, BookingService $service): JsonResponse
    {
        $op = $this->opId($request);
        $data = $request->validate([
            'trip_id' => ['required', 'integer', "exists:trips,id,operator_id,{$op}"],
            'contact_phone' => ['required', 'string', Phone::rule()],
            'passengers' => ['required', 'array', 'min:1', 'max:'.config('bemengede.booking.max_seats_per_booking')],
            'passengers.*.full_name' => ['required', 'string', 'min:2', 'max:80'],
            'passengers.*.seat_number' => ['required', 'integer', 'min:1', 'distinct'],
            'passengers.*.fare_category' => ['required', 'in:adult,child,student'],
            'terms_shown' => ['accepted'],
            'booker_is_adult' => ['accepted'],
        ], [
            'terms_shown.accepted' => 'Confirm you showed the customer the terms and refund rules.',
            'booker_is_adult.accepted' => 'The customer paying must be 18 or older.',
        ]);
        $data['contact_phone'] = Phone::normalize($data['contact_phone']);
        $data['channel'] = 'counter';
        $trip = Trip::with('operator', 'bus')->findOrFail($data['trip_id']);

        $booking = DB::transaction(function () use ($service, $trip, $data) {
            $b = $service->create($trip, $data, null);
            Payment::create([
                'booking_id' => $b->id, 'provider' => 'cash', 'tx_ref' => 'bm_'.Str::lower(Str::ulid()),
                'amount' => $b->total, 'status' => 'succeeded', 'verified_at' => now(),
            ]);
            $service->confirm($b);

            return $b;
        });
        AuditLog::record('booking.counter_sale', $booking);

        return response()->json(Present::booking($booking->fresh()), 201);
    }
}
