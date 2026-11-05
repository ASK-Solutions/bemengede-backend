<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\TransferVerification;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\VerifyEtClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks only tell us "something changed". We never trust their body for
 * money: we always re-check with the provider before confirming a booking.
 */
class WebhookController extends Controller
{
    public function __construct(private PaymentManager $payments) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $txRef = $this->payments->provider($provider)->parseWebhook($request);
        if (! $txRef) {
            Log::warning("Rejected {$provider} webhook: bad signature");

            return response()->json(['ok' => false], 401);
        }
        if ($payment = Payment::where('tx_ref', $txRef)->first()) {
            $this->payments->reconcile($payment);
        }

        return response()->json(['ok' => true]);
    }

    public function verifyEt(Request $request, VerifyEtClient $client): JsonResponse
    {
        if (! $client->validWebhook($request)) {
            return response()->json(['ok' => false], 401);
        }
        // Results we already recorded need no action; unknown ones are logged for reconciliation.
        $ref = $request->input('data.reference') ?? $request->input('reference');
        if ($ref && ! TransferVerification::where('reference', strtoupper($ref))->exists()) {
            Log::info('verify.et webhook for unknown reference', ['reference' => $ref]);
        }

        return response()->json(['ok' => true]);
    }
}
