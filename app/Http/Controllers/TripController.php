<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TripController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'integer', 'exists:cities,id'],
            'to' => ['required', 'integer', 'exists:cities,id', 'different:from'],
            'date' => ['required', 'date_format:Y-m-d'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:'.config('bemengede.booking.max_seats_per_booking')],
        ]);
        $tz = 'Africa/Addis_Ababa';
        $start = Carbon::parse($data['date'], $tz)->startOfDay()->utc();
        $end = Carbon::parse($data['date'], $tz)->endOfDay()->utc();
        $start = $start->max(now());

        $trips = Trip::with('operator', 'bus', 'route.origin.city', 'route.destination.city')
            ->where('status', 'scheduled')
            ->whereBetween('departure_at', [$start, $end])
            ->whereHas('operator', fn ($q) => $q->bookable())
            ->whereHas('route.origin', fn ($q) => $q->where('city_id', $data['from']))
            ->whereHas('route.destination', fn ($q) => $q->where('city_id', $data['to']))
            ->orderBy('departure_at')->get()
            ->map(fn ($t) => Present::trip($t))
            ->filter(fn ($t) => $t['seats_left'] >= ($data['passengers'] ?? 1))
            ->values();

        return response()->json(['data' => $trips, 'prices_include_service_fee' => true]);
    }

    public function show(Trip $trip): JsonResponse
    {
        abort_unless($trip->operator->status === 'active', 404);
        $trip->load('operator', 'bus', 'route.origin.city', 'route.destination.city');

        return response()->json(Present::trip($trip, true));
    }
}
