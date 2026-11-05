<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\PaymentManager;
use App\Support\Phone;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentManager $payments) {}

    private function booking(Request $request, string $reference): Booking
    {
        $b = Booking::where('reference', strtoupper($reference))->firstOrFail();
        abort_unless(Phone::normalize($request->input('phone')) === $b->contact_phone, 404);

        return $b;
    }

    public function start(Request $request, string $reference): JsonResponse
    {
        $request->validate(['method' => ['required', 'in:chapa,telebirr,transfer'], 'phone' => ['required', 'string']]);
        $booking = $this->booking($request, $reference);
        $payment = $this->payments->start($booking, $request->input('method'));

        return response()->json([
            'tx_ref' => $payment->tx_ref,
            'method' => $payment->provider,
            'amount' => $payment->amount,
            'checkout_url' => $payment->checkout_url,
            'booking' => Present::booking($booking->fresh()),
        ]);
    }

    /** Called when the customer returns from the provider. We re-check server to server. */
    public function status(Request $request, string $txRef): JsonResponse
    {
        $payment = Payment::where('tx_ref', $txRef)->firstOrFail();
        abort_unless(Phone::normalize($request->query('phone')) === $payment->booking->contact_phone, 404);
        $payment = $this->payments->reconcile($payment);

        return response()->json(['status' => $payment->status, 'booking' => Present::booking($payment->booking->fresh())]);
    }

    public function verifyTransfer(Request $request, string $txRef): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'bank' => ['required', 'in:'.implode(',', config('bemengede.payments.verify_et.banks'))],
            'reference' => ['required', 'string', 'min:6', 'max:40', 'regex:/^[A-Za-z0-9\-]+$/'],
        ]);
        $payment = Payment::where('tx_ref', $txRef)->where('provider', 'transfer')->firstOrFail();
        abort_unless(Phone::normalize($data['phone']) === $payment->booking->contact_phone, 404);

        $tv = $this->payments->verifyTransfer($payment, $data['bank'], $data['reference']);

        return response()->json([
            'decision' => $tv->decision,
            'checks' => $tv->checks,
            'booking' => Present::booking($payment->booking->fresh()),
        ]);
    }

    /** Development only: pretend the customer paid on the provider's page. */
    public function mockComplete(Request $request, string $txRef): JsonResponse
    {
        abort_unless(config('bemengede.payments.mode') === 'mock', 404);
        cache()->put('mock-pay:'.$txRef, true, 600);
        $payment = $this->payments->reconcile(Payment::where('tx_ref', $txRef)->firstOrFail());

        return response()->json(['status' => $payment->status, 'reference' => $payment->booking->reference]);
    }
}
