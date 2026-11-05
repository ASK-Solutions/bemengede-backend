<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * verify.et — checks Ethiopian bank and wallet transfer receipts.
 * POST /api/verify {bank, reference} with x-api-key and an Idempotency-Key.
 * Results can also arrive by signed webhook.
 */
class VerifyEtClient
{
    private function cfg(string $key): mixed
    {
        return config("bemengede.payments.verify_et.{$key}");
    }

    /**
     * @return array{status:string, id:?string, amount:?int, receiver_account:?string, receiver_name:?string, paid_at:?string}
     */
    public function verify(string $bank, string $reference): array
    {
        if (config('bemengede.payments.mode') === 'mock') {
            // Deterministic test references: ...SHORT underpays, ...WRONG pays someone else.
            return [
                'status' => str_ends_with($reference, 'FAIL') ? 'not_found' : 'verified',
                'id' => 'mock_'.$reference,
                'amount' => null, // filled in by caller in mock mode
                'receiver_account' => str_ends_with($reference, 'WRONG') ? '0000000000' : null,
                'receiver_name' => null,
                'paid_at' => now()->toIso8601String(),
                'mock_short' => str_ends_with($reference, 'SHORT'),
            ];
        }

        $res = Http::withHeaders([
            'x-api-key' => $this->cfg('api_key'),
            'Idempotency-Key' => hash('sha256', $bank.'|'.$reference),
        ])->acceptJson()->timeout(30)->post(rtrim($this->cfg('base_url'), '/').'/api/verify', [
            'bank' => $bank,
            'reference' => $reference,
        ])->json();

        $data = $res['data'] ?? [];

        return [
            'status' => ($res['status'] ?? '') === 'success' || ($res['status'] ?? '') === 'verified' ? 'verified' : (string) ($res['status'] ?? 'error'),
            'id' => $res['transaction_id'] ?? null,
            'amount' => isset($data['amount']) ? (int) round(((float) $data['amount']) * 100) : null,
            'receiver_account' => $data['receiver_account'] ?? $data['receiver']['account'] ?? null,
            'receiver_name' => $data['receiver_name'] ?? $data['receiver']['name'] ?? null,
            'paid_at' => $data['date'] ?? $data['paid_at'] ?? null,
        ];
    }

    public function validWebhook(Request $request): bool
    {
        $secret = (string) $this->cfg('webhook_secret');
        if ($secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, (string) $request->header($this->cfg('signature_header')));
    }
}
