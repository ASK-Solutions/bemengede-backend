<?php

namespace App\Http\Controllers\Operator;

use App\Models\AuditLog;
use App\Models\Passenger;
use App\Models\TransferVerification;
use App\Services\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every query here is scoped to the signed-in staff member's own company. */
class OperatorController extends OperatorScoped
{
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
