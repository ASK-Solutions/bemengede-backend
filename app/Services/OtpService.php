<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private SmsSender $sms) {}

    public function send(string $phone): void
    {
        $key = 'otp:'.$phone;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['phone' => 'Too many codes requested. Try again in a few minutes.']);
        }
        RateLimiter::hit($key, 600);

        $code = (string) random_int(100000, 999999);
        OtpCode::where('phone', $phone)->delete();
        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('bemengede.privacy.otp_ttl_minutes')),
        ]);
        $this->sms->send($phone, "Your Bemengede code is {$code}. It expires in 5 minutes. Never share it.");
    }

    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::where('phone', $phone)->where('expires_at', '>', now())->latest('id')->first();
        if (! $otp || $otp->attempts >= 5) {
            return false;
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }
        $otp->delete();

        return true;
    }
}
