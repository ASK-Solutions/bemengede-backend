<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * telebirr H5 C2B web checkout.
 * Flow: fabric token → signed preOrder → customer approves in telebirr →
 * async notify (signed) → queryOrder before we confirm anything.
 *
 * Field names follow the telebirr merchant integration guide; confirm them
 * (and the sign_type) against the documentation issued with your merchant account.
 */
class TelebirrProvider implements PaymentProvider
{
    private function cfg(string $key): mixed
    {
        return config("bemengede.payments.telebirr.{$key}");
    }

    private function mock(): bool
    {
        return config('bemengede.payments.mode') === 'mock';
    }

    private function fabricToken(): string
    {
        return Cache::remember('telebirr:fabric-token', now()->addMinutes(50), function () {
            return Http::withHeaders(['X-APP-Key' => $this->cfg('fabric_app_id')])->timeout(20)
                ->post($this->cfg('base_url').'/payment/v1/token', ['appSecret' => $this->cfg('app_secret')])
                ->throw()->json('token');
        });
    }

    /** SHA256WithRSA over the sorted key=value string, as required by telebirr. */
    private function sign(array $fields): string
    {
        $exclude = ['sign', 'sign_type', 'header', 'refund_info', 'openType', 'raw_request', 'biz_content'];
        $flat = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $exclude, true)) {
                continue;
            }
            $flat[$k] = $v;
        }
        foreach ($fields['biz_content'] ?? [] as $k => $v) {
            $flat[$k] = $v;
        }
        ksort($flat);
        $str = collect($flat)->map(fn ($v, $k) => "{$k}={$v}")->implode('&');
        $key = openssl_pkey_get_private(file_get_contents($this->cfg('private_key_path')));
        openssl_sign($str, $sig, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($sig);
    }

    private function request(string $method, array $biz): array
    {
        $req = [
            'timestamp' => (string) time(),
            'nonce_str' => Str::random(32),
            'method' => $method,
            'version' => '1.0',
            'biz_content' => $biz,
        ];
        $req['sign'] = $this->sign($req);
        $req['sign_type'] = 'SHA256WithRSA';

        return $req;
    }

    public function initiate(Payment $payment): ?string
    {
        $booking = $payment->booking;
        $front = rtrim(config('bemengede.frontend_url'), '/');
        if ($this->mock()) {
            return $front.'/pay/mock?provider=telebirr&tx_ref='.$payment->tx_ref;
        }

        $req = $this->request('payment.preorder', [
            'notify_url' => url('/api/v1/webhooks/telebirr'),
            'redirect_url' => $front.'/booking/'.$booking->reference.'?phone='.urlencode($booking->contact_phone),
            'appid' => $this->cfg('merchant_app_id'),
            'merch_code' => $this->cfg('merchant_code'),
            'merch_order_id' => $payment->tx_ref,
            'trade_type' => 'Checkout',
            'title' => 'Bus ticket '.$booking->reference,
            'total_amount' => number_format($payment->amount / 100, 2, '.', ''),
            'trans_currency' => 'ETB',
            'timeout_express' => config('bemengede.booking.hold_minutes').'m',
        ]);

        $res = Http::withHeaders([
            'X-APP-Key' => $this->cfg('fabric_app_id'),
            'Authorization' => $this->fabricToken(),
        ])->timeout(20)->post($this->cfg('base_url').'/payment/v1/merchant/preOrder', $req)->throw()->json();

        $prepayId = $res['biz_content']['prepay_id'] ?? null;
        if (! $prepayId) {
            return null;
        }
        $raw = [
            'appid' => $this->cfg('merchant_app_id'),
            'merch_code' => $this->cfg('merchant_code'),
            'nonce_str' => Str::random(32),
            'prepay_id' => $prepayId,
            'timestamp' => (string) time(),
        ];
        $raw['sign'] = $this->sign($raw);
        $raw['sign_type'] = 'SHA256WithRSA';

        return $this->cfg('web_checkout_url').'?'.http_build_query($raw).'&version=1.0&trade_type=Checkout';
    }

    public function verify(Payment $payment): PaymentResult
    {
        if ($this->mock()) {
            return $payment->status === 'succeeded' || cache()->pull('mock-pay:'.$payment->tx_ref)
                ? new PaymentResult('succeeded', $payment->amount, 'MOCK-'.$payment->tx_ref)
                : new PaymentResult('pending');
        }

        $req = $this->request('payment.queryorder', [
            'appid' => $this->cfg('merchant_app_id'),
            'merch_code' => $this->cfg('merchant_code'),
            'merch_order_id' => $payment->tx_ref,
        ]);
        $res = Http::withHeaders([
            'X-APP-Key' => $this->cfg('fabric_app_id'),
            'Authorization' => $this->fabricToken(),
        ])->timeout(20)->post($this->cfg('base_url').'/payment/v1/merchant/queryOrder', $req)->json();

        $biz = $res['biz_content'] ?? [];
        $status = match ($biz['order_status'] ?? null) {
            'PAY_SUCCESS' => 'succeeded',
            'PAY_FAILED', 'ORDER_CLOSED' => 'failed',
            default => 'pending',
        };
        $amount = isset($biz['total_amount']) ? (int) round(((float) $biz['total_amount']) * 100) : null;

        return new PaymentResult($status, $amount, $biz['trans_id'] ?? null, ['order_status' => $biz['order_status'] ?? null]);
    }

    public function parseWebhook(Request $request): ?string
    {
        $data = $request->all();
        if (! $this->mock()) {
            $sign = base64_decode((string) ($data['sign'] ?? ''));
            unset($data['sign'], $data['sign_type']);
            ksort($data);
            $str = collect($data)->map(fn ($v, $k) => "{$k}={$v}")->implode('&');
            $pub = openssl_pkey_get_public(file_get_contents($this->cfg('public_key_path')));
            if (openssl_verify($str, $sign, $pub, OPENSSL_ALGO_SHA256) !== 1) {
                return null;
            }
        }

        return $request->input('merch_order_id');
    }
}
