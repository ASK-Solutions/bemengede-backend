<?php

namespace App\Http\Controllers\Operator;

use App\Models\Booking;
use App\Models\Settlement;
use App\Models\Trip;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends OperatorScoped
{
    private function period(Request $r): array
    {
        $d = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $tz = 'Africa/Addis_Ababa';

        return [
            Carbon::parse($d['from'] ?? now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay()->utc(),
            Carbon::parse($d['to'] ?? now($tz)->toDateString(), $tz)->endOfDay()->utc(),
        ];
    }

    public function payouts(Request $request, LedgerService $ledger): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $op = $this->operator($request);

        return response()->json([
            'commission_percent' => $op->commission(),
            'summary' => $ledger->summary($op->id, $from, $to),
            'settlements' => Settlement::where('operator_id', $op->id)->latest('period_end')->limit(52)->get(),
            'payout_accounts' => $op->accounts()->get(['bank', 'account_name', 'account_number']),
        ]);
    }

    /** Sales, occupancy and cancellations. Add ?format=csv to download. */
    public function reports(Request $request): JsonResponse|StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $op = $this->opId($request);

        $bookings = Booking::with('trip.route.origin.city', 'trip.route.destination.city', 'payments', 'passengers')
            ->where('operator_id', $op)->whereBetween('created_at', [$from, $to])->get();
        $paid = $bookings->filter(fn ($b) => in_array($b->status, [Booking::CONFIRMED, Booking::CHECKED_IN], true));
        $routeName = fn ($b) => $b->trip->route->origin->city->name_en.' → '.$b->trip->route->destination->city->name_en;

        $byRoute = $paid->groupBy($routeName)->map(fn ($g, $k) => ['route' => $k, 'tickets' => $g->sum(fn ($b) => $b->passengers->count()), 'fares' => $g->sum(fn ($b) => $b->total - $b->service_fee)])->values();
        $byChannel = $paid->groupBy('channel')->map(fn ($g, $k) => ['channel' => $k, 'bookings' => $g->count(), 'fares' => $g->sum(fn ($b) => $b->total - $b->service_fee)])->values();
        $byMethod = $paid->groupBy(fn ($b) => optional($b->payments->firstWhere('status', 'succeeded'))->provider ?? 'unknown')
            ->map(fn ($g, $k) => ['method' => $k, 'bookings' => $g->count()])->values();
        $cancellations = $bookings->where('status', Booking::CANCELLED)->count();

        $trips = Trip::with('bus', 'route.origin.city', 'route.destination.city')->where('operator_id', $op)
            ->whereBetween('departure_at', [$from, $to])->where('status', '!=', 'cancelled')->get();
        $soldByTrip = Booking::whereIn('trip_id', $trips->pluck('id'))->whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN])
            ->withCount('passengers')->get()->groupBy('trip_id')->map->sum('passengers_count');
        $occupancy = $trips->map(fn ($t) => [
            'trip_id' => $t->id,
            'departure_at' => $t->departure_at->toIso8601String(),
            'route' => $t->route->origin->city->name_en.' → '.$t->route->destination->city->name_en,
            'sold' => (int) ($soldByTrip[$t->id] ?? 0),
            'capacity' => count($t->bus->sellableSeats()),
        ]);

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($occupancy) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['trip_id', 'departure_at', 'route', 'sold', 'capacity', 'occupancy_percent']);
                foreach ($occupancy as $o) {
                    fputcsv($out, [$o['trip_id'], $o['departure_at'], $o['route'], $o['sold'], $o['capacity'], $o['capacity'] ? round($o['sold'] / $o['capacity'] * 100) : 0]);
                }
                fclose($out);
            }, 'bemengede-occupancy.csv', ['Content-Type' => 'text/csv']);
        }

        $sold = $occupancy->sum('sold');
        $cap = $occupancy->sum('capacity');

        return response()->json([
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'totals' => ['bookings' => $paid->count(), 'tickets' => $paid->sum(fn ($b) => $b->passengers->count()),
                'fares' => $paid->sum(fn ($b) => $b->total - $b->service_fee), 'cancellations' => $cancellations,
                'occupancy_percent' => $cap ? round($sold / $cap * 100) : 0],
            'by_route' => $byRoute, 'by_channel' => $byChannel, 'by_method' => $byMethod, 'occupancy' => $occupancy,
        ]);
    }
}
