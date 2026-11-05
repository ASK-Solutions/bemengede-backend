<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Chapa hosted checkout. Docs: https://developer.chapa.co
 * Flow: initialize → customer pays on Chapa → webhook/return → verify/{tx_ref}.
 */
class ChapaProvider implements PaymentProvider
{
    private function cfg(string $key): mixed
    {
        return config("bemengede.payments.chapa.{$key}");
    }

    private function mock(): bool
    {
        return config('bemengede.payments.mode') === 'mock';
    }

    public function initiate(Payment $payment): ?string
    {
        $booking = $payment->booking;
        $front = rtrim(config('bemengede.frontend_url'), '/');
        if ($this->mock()) {
            return $front.'/pay/mock?provider=chapa&tx_ref='.$payment->tx_ref;
        }

        $body = [
            'amount' => number_format($payment->amount / 100, 2, '.', ''),
            'currency' => 'ETB',
            'tx_ref' => $payment->tx_ref,
            'phone_number' => $booking->contact_phone,
            'callback_url' => url('/api/v1/webhooks/chapa'),
            'return_url' => $front.'/booking/'.$booking->reference.'?phone='.urlencode($booking->contact_phone),
            'customization' => ['title' => 'Bemengede', 'description' => 'Bus ticket '.$booking->reference],
        ];
        if ($booking->contact_email) {
            $body['email'] = $booking->contact_email;
        }
        // Split settlement straight to the bus company when it has a Chapa subaccount.
        if ($sub = $booking->operator->chapa_subaccount_id) {
            $body['subaccounts'] = ['id' => $sub];
        }

        $res = Http::withToken($this->cfg('secret_key'))->acceptJson()->timeout(20)
            ->post($this->cfg('base_url').'/transaction/initialize', $body)->throw()->json();

        return $res['data']['checkout_url'] ?? null;
    }

    public function verify(Payment $payment): PaymentResult
    {
        if ($this->mock()) {
            return $payment->status === 'succeeded' || cache()->pull('mock-pay:'.$payment->tx_ref)
                ? new PaymentResult('succeeded', $payment->amount, 'MOCK-'.$payment->tx_ref)
                : new PaymentResult('pending');
        }

        $res = Http::withToken($this->cfg('secret_key'))->acceptJson()->timeout(20)
            ->get($this->cfg('base_url').'/transaction/verify/'.$payment->tx_ref)->json();

        $data = $res['data'] ?? [];
        $status = ($res['status'] ?? null) === 'success' && ($data['status'] ?? null) === 'success' ? 'succeeded' : 'pending';
        $amount = isset($data['amount']) ? (int) round(((float) $data['amount']) * 100) : null;

        return new PaymentResult($status, $amount, $data['reference'] ?? null, [
            'status' => $data['status'] ?? null,
            'method' => $data['method'] ?? null,
            'currency' => $data['currency'] ?? null,
        ]);
    }

    public function parseWebhook(Request $request): ?string
    {
        $secret = (string) $this->cfg('webhook_secret');
        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);
        $given = (string) ($request->header('x-chapa-signature') ?? $request->header('chapa-signature'));
        if (! $this->mock() && ($secret === '' || ! hash_equals($expected, $given))) {
            return null;
        }

        return $request->input('tx_ref') ?? $request->input('trx_ref');
    }
}
