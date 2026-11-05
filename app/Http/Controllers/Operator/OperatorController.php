<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\Trip;
use App\Models\TransferVerification;
use App\Services\Payments\PaymentManager;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every query here is scoped to the signed-in staff member's own company. */
class OperatorController extends Controller
{
    private function opId(Request $r): int
    {
        return (int) $r->user()->operator_id;
    }

    public function trips(Request $request): JsonResponse
    {
        $trips = Trip::with('operator', 'bus', 'route.origin.city', 'route.destination.city')
            ->where('operator_id', $this->opId($request))
            ->where('departure_at', '>', now()->subDay())->orderBy('departure_at')->limit(100)->get()
            ->map(fn ($t) => Present::trip($t) + ['sold' => Booking::where('trip_id', $t->id)->whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN])->withCount('passengers')->get()->sum('passengers_count')]);

        return response()->json($trips);
    }

    public function storeTrip(Request $request): JsonResponse
    {
        $op = $this->opId($request);
        $data = $request->validate([
            'route_id' => ['required', 'integer', "exists:routes,id,operator_id,{$op}"],
            'bus_id' => ['required', 'integer', "exists:buses,id,operator_id,{$op}"],
            'departure_at' => ['required', 'date', 'after:now'],
            'base_fare_etb' => ['required', 'numeric', 'min:1', 'max:100000'],
        ]);
        $route = \App\Models\Route::findOrFail($data['route_id']);
        $dep = \Illuminate\Support\Carbon::parse($data['departure_at']);
        $trip = Trip::create([
            'operator_id' => $op, 'route_id' => $route->id, 'bus_id' => $data['bus_id'],
            'departure_at' => $dep, 'arrival_at' => $dep->copy()->addMinutes($route->duration_minutes),
            'base_fare' => (int) round($data['base_fare_etb'] * 100),
        ]);
        AuditLog::record('trip.created', $trip);

        return response()->json($trip, 201);
    }

    public function verifications(Request $request): JsonResponse
    {
        $rows = TransferVerification::with('payment.booking.passengers')
            ->whereHas('payment.booking', fn ($q) => $q->where('operator_id', $this->opId($request)))
            ->latest()->limit(100)->get()
            ->map(fn ($tv) => [
                'id' => $tv->id, 'booking' => $tv->payment->booking->reference, 'bank' => $tv->bank, 'reference' => $tv->reference,
                'paid' => $tv->verified_amount, 'due' => $tv->payment->amount, 'receiver' => $tv->receiver_account,
                'paid_at' => $tv->paid_at?->toIso8601String(), 'checks' => $tv->checks, 'decision' => $tv->decision,
                'passenger' => $tv->payment->booking->passengers->first()?->full_name,
            ]);

        return response()->json($rows);
    }

    public function decide(Request $request, TransferVerification $verification, PaymentManager $payments): JsonResponse
    {
        abort_unless($verification->payment->booking->operator_id === $this->opId($request), 404);
        abort_unless($verification->decision === 'needs_review', 422, 'Already decided.');
        $request->validate(['approve' => ['required', 'boolean']]);
        $payments->decideTransfer($verification, $request->boolean('approve'), $request->user()->id);

        return response()->json(['decision' => $verification->fresh()->decision]);
    }

    /** Boarding: scan "BM-XXXXXX:token" from the passenger's QR. */
    public function board(Request $request): JsonResponse
    {
        $request->validate(['qr' => ['required', 'string', 'max:80']]);
        [$ref, $token] = array_pad(explode(':', $request->qr, 2), 2, null);
        $p = Passenger::where('qr_token', $token)->whereHas('booking', fn ($q) => $q->where('reference', $ref)->where('operator_id', $this->opId($request)))->first();
        abort_unless($p && $p->booking->isPaid(), 404, 'Ticket not valid for this company.');
        abort_if($p->boarded_at, 409, 'This ticket has already been used.');
        $p->update(['boarded_at' => now(), 'checked_in_at' => $p->checked_in_at ?? now()]);
        AuditLog::record('passenger.boarded', $p);

        return response()->json(['name' => $p->full_name, 'seat' => $p->seat_number]);
    }
}
