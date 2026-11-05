<?php

namespace App\Http\Controllers\Operator;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Payment;
use App\Models\TransferVerification;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends OperatorScoped
{
    public function __invoke(Request $request): JsonResponse
    {
        $op = $this->opId($request);
        $tz = 'Africa/Addis_Ababa';
        $start = Carbon::now($tz)->startOfDay()->utc();
        $end = Carbon::now($tz)->endOfDay()->utc();

        $trips = Trip::with('route.origin.city', 'route.destination.city', 'bus', 'driver')
            ->where('operator_id', $op)->whereBetween('departure_at', [$start, $end])->orderBy('departure_at')->get();
        $sold = Booking::whereIn('trip_id', $trips->pluck('id'))->whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN])
            ->withCount('passengers')->get()->groupBy('trip_id')->map->sum('passengers_count');

        $departures = $trips->map(fn ($t) => [
            'id' => $t->id,
            'departure_at' => $t->departure_at->toIso8601String(),
            'route' => $t->route->origin->city->name_en.' → '.$t->route->destination->city->name_en,
            'bus' => $t->bus->side_number ?? $t->bus->plate_number,
            'driver' => $t->driver?->full_name,
            'sold' => (int) ($sold[$t->id] ?? 0),
            'capacity' => count($t->bus->sellableSeats()),
            'status' => $t->status,
            'delay_minutes' => $t->delay_minutes,
        ]);

        $paidToday = Payment::where('status', 'succeeded')->whereBetween('verified_at', [$start, $end])
            ->whereHas('booking', fn ($q) => $q->where('operator_id', $op))->sum('amount');

        $mix = Payment::where('status', 'succeeded')->where('verified_at', '>=', now()->subDays(7))
            ->whereHas('booking', fn ($q) => $q->where('operator_id', $op))
            ->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider');

        $alerts = [];
        foreach (Driver::where('operator_id', $op)->where('status', 'active')->whereDate('license_expires_on', '<=', now()->addDays(30))->get() as $d) {
            $alerts[] = ['type' => 'driver_license', 'text' => "Driver licence for {$d->full_name} expires on {$d->license_expires_on->toFormattedDateString()}."];
        }
        foreach (Bus::where('operator_id', $op)->where('status', 'maintenance')->get() as $b) {
            $alerts[] = ['type' => 'bus', 'text' => "Bus {$b->plate_number} is in maintenance."];
        }
        $pending = TransferVerification::where('decision', 'needs_review')
            ->whereHas('payment.booking', fn ($q) => $q->where('operator_id', $op))->count();
        if ($pending) {
            $alerts[] = ['type' => 'verifications', 'text' => "{$pending} bank transfer(s) need review."];
        }

        return response()->json([
            'kpis' => [
                'departures_today' => $trips->count(),
                'seats_sold_today' => $departures->sum('sold'),
                'capacity_today' => $departures->sum('capacity'),
                'revenue_today' => (int) $paidToday,
                'pending_verifications' => $pending,
            ],
            'departures' => $departures,
            'payment_mix_7d' => $mix,
            'alerts' => $alerts,
        ]);
    }
}
