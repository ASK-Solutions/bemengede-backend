<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentProvider
{
    /** Start a payment. Returns a checkout URL for redirect-based providers, or null. */
    public function initiate(Payment $payment): ?string;

    /** Ask the provider, server to server, whether this payment really succeeded. */
    public function verify(Payment $payment): PaymentResult;

    /** Validate a webhook's signature and return the tx_ref it refers to, or null if invalid. */
    public function parseWebhook(Request $request): ?string;
}
