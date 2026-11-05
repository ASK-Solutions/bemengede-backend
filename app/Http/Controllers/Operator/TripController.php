<?php

namespace App\Http\Controllers\Operator;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Trip;
use App\Services\BookingService;
use App\Services\Sms\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TripController extends OperatorScoped
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $tz = 'Africa/Addis_Ababa';
        $from = Carbon::parse($data['from'] ?? now($tz)->toDateString(), $tz)->startOfDay()->utc();
        $to = Carbon::parse($data['to'] ?? now($tz)->addDays(7)->toDateString(), $tz)->endOfDay()->utc();

        $trips = Trip::with('route.origin.city', 'route.destination.city', 'bus', 'driver')
            ->where('operator_id', $this->opId($request))->whereBetween('departure_at', [$from, $to])
            ->orderBy('departure_at')->limit(500)->get();
        $sold = Booking::whereIn('trip_id', $trips->pluck('id'))->whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN])
            ->withCount('passengers')->get()->groupBy('trip_id')->map->sum('passengers_count');

        return response()->json($trips->map(fn (Trip $t) => [
            'id' => $t->id,
            'departure_at' => $t->departure_at->toIso8601String(),
            'arrival_at' => $t->arrival_at->toIso8601String(),
            'route_id' => $t->route_id,
            'route' => $t->route->origin->city->name_en.' → '.$t->route->destination->city->name_en,
            'bus_id' => $t->bus_id,
            'bus' => trim(($t->bus->side_number ? $t->bus->side_number.' · ' : '').$t->bus->plate_number),
            'bus_class' => $t->bus->class,
            'driver_id' => $t->driver_id,
            'driver' => $t->driver?->full_name,
            'base_fare' => $t->base_fare,
            'sold' => (int) ($sold[$t->id] ?? 0),
            'capacity' => count($t->bus->sellableSeats()),
            'status' => $t->status,
            'delay_minutes' => $t->delay_minutes,
        ]));
    }

    /** Create one trip, or the same trip on several days (e.g. daily for two weeks). */
    public function store(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $op = $this->opId($request);
        $data = $request->validate([
            'route_id' => ['required', 'integer', "exists:routes,id,operator_id,{$op}"],
            'bus_id' => ['required', 'integer', "exists:buses,id,operator_id,{$op}"],
            'driver_id' => ['nullable', 'integer', "exists:drivers,id,operator_id,{$op}"],
            'departure_local' => ['required', 'date_format:Y-m-d\TH:i', 'after:now'],
            'base_fare_etb' => ['required', 'numeric', 'min:1', 'max:100000'],
            'repeat_days' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $route = Route::findOrFail($data['route_id']);
        abort_if(Bus::find($data['bus_id'])->status !== 'active', 422, 'This bus is not active.');
        $first = Carbon::parse($data['departure_local'], 'Africa/Addis_Ababa')->utc();

        $created = DB::transaction(function () use ($data, $route, $first, $op) {
            $out = [];
            for ($i = 0; $i < ($data['repeat_days'] ?? 1); $i++) {
                $dep = $first->copy()->addDays($i);
                $out[] = Trip::create([
                    'operator_id' => $op, 'route_id' => $route->id, 'bus_id' => $data['bus_id'], 'driver_id' => $data['driver_id'] ?? null,
                    'departure_at' => $dep, 'arrival_at' => $dep->copy()->addMinutes($route->duration_minutes),
                    'base_fare' => (int) round($data['base_fare_etb'] * 100),
                ]);
            }

            return $out;
        });
        AuditLog::record('trip.created', $created[0], ['count' => count($created)]);

        return response()->json(['created' => count($created)], 201);
    }

    public function update(Request $request, Trip $trip): JsonResponse
    {
        $this->own($request, $trip);
        $op = $this->opId($request);
        $data = $request->validate([
            'bus_id' => ['sometimes', 'integer', "exists:buses,id,operator_id,{$op}"],
            'driver_id' => ['sometimes', 'nullable', 'integer', "exists:drivers,id,operator_id,{$op}"],
            'base_fare_etb' => ['sometimes', 'numeric', 'min:1'],
            'status' => ['sometimes', 'in:scheduled,boarding,departed,arrived'],
        ]);
        // A fare change applies to new bookings only; paid tickets keep their price.
        if (isset($data['base_fare_etb'])) {
            $data['base_fare'] = (int) round($data['base_fare_etb'] * 100);
            unset($data['base_fare_etb']);
        }
        $trip->update($data);
        AuditLog::record('trip.updated', $trip, $data);

        return response()->json(['ok' => true]);
    }

    /** Delay: shift times and text every passenger. */
    public function delay(Request $request, Trip $trip, SmsSender $sms): JsonResponse
    {
        $this->own($request, $trip);
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:5', 'max:1440'], 'reason' => ['nullable', 'string', 'max:160']]);
        $trip->update([
            'departure_at' => $trip->departure_at->addMinutes($data['minutes']),
            'arrival_at' => $trip->arrival_at->addMinutes($data['minutes']),
            'delay_minutes' => $trip->delay_minutes + $data['minutes'],
        ]);
        $this->notifyPassengers($trip, $sms, "Your {$trip->operator->name} bus is delayed by {$data['minutes']} min. New departure: "
            .$trip->departure_at->setTimezone('Africa/Addis_Ababa')->format('D j M H:i').'.'
            .($trip->delay_minutes > 180 ? ' You can get a full refund or a free move to another trip.' : ''));
        AuditLog::record('trip.delayed', $trip, $data);

        return response()->json(['ok' => true]);
    }

    /** Operator cancels: every paid booking gets a full refund including the service fee. */
    public function cancel(Request $request, Trip $trip, BookingService $bookings, SmsSender $sms): JsonResponse
    {
        $this->own($request, $trip);
        $request->validate(['reason' => ['required', 'string', 'max:160']]);
        DB::transaction(function () use ($trip, $bookings) {
            $trip->update(['status' => 'cancelled']);
            foreach ($trip->bookings()->whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN, Booking::AWAITING_VERIFICATION, Booking::PENDING])->get() as $b) {
                $b->update(['status' => Booking::CANCELLED, 'cancelled_at' => now(), 'refund_amount' => $b->isPaid() ? $b->total : 0]);
                \App\Models\SeatHold::where('booking_id', $b->id)->delete();
            }
        });
        $this->notifyPassengers($trip, $sms, "{$trip->operator->name} has cancelled your trip on ".$trip->departure_at->setTimezone('Africa/Addis_Ababa')->format('D j M H:i')
            .'. You will get a full refund, including the service fee.', true);
        AuditLog::record('trip.cancelled', $trip, ['reason' => $request->reason]);

        return response()->json(['ok' => true]);
    }

    public function manifest(Request $request, Trip $trip): JsonResponse
    {
        $this->own($request, $trip);
        $rows = $trip->bookings()->whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN])->with('passengers')->get()
            ->flatMap(fn ($b) => $b->passengers->map(fn ($p) => [
                'seat' => $p->seat_number, 'name' => $p->full_name, 'fare_category' => $p->fare_category,
                'booking' => $b->reference, 'channel' => $b->channel, 'boarded' => (bool) $p->boarded_at,
            ]))->sortBy('seat')->values();

        return response()->json(['trip_id' => $trip->id, 'passengers' => $rows, 'boarded' => $rows->where('boarded', true)->count()]);
    }

    private function notifyPassengers(Trip $trip, SmsSender $sms, string $message, bool $includeCancelled = false): void
    {
        $statuses = [Booking::CONFIRMED, Booking::CHECKED_IN, Booking::AWAITING_VERIFICATION];
        if ($includeCancelled) {
            $statuses[] = Booking::CANCELLED;
        }
        $trip->bookings()->whereIn('status', $statuses)->whereNotNull('contact_phone')->pluck('contact_phone')->unique()
            ->each(fn ($phone) => rescue(fn () => $sms->send($phone, 'Bemengede: '.$message)));
    }
}
