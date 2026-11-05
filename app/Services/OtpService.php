<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * One-time 6-digit codes. A code is tied to where it was sent (a phone or an email)
 * and to a purpose (login, password reset, adding a phone), so a login code can't
 * be used to reset a password.
 */
class OtpService
{
    public function __construct(private SmsSender $sms) {}

    public function send(string $to, string $purpose = 'login'): void
    {
        $key = "otp:{$purpose}:{$to}";
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['phone' => 'Too many codes requested. Try again in a few minutes.']);
        }
        RateLimiter::hit($key, 600);

        $code = (string) random_int(100000, 999999);
        OtpCode::where('phone', $to)->where('purpose', $purpose)->delete();
        OtpCode::create([
            'phone' => $to,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('bemengede.privacy.otp_ttl_minutes')),
        ]);

        $what = $purpose === 'reset' ? 'password reset code' : 'code';
        $text = "Your Bemengede {$what} is {$code}. It expires in 5 minutes. Never share it.";
        if (str_contains($to, '@')) {
            Mail::raw($text."\n\nIf you didn't ask for this, you can ignore this email.", fn ($m) => $m->to($to)->subject('Your Bemengede '.$what));
        } else {
            $this->sms->send($to, $text);
        }
    }

    public function verify(string $to, string $code, string $purpose = 'login'): bool
    {
        $otp = OtpCode::where('phone', $to)->where('purpose', $purpose)->where('expires_at', '>', now())->latest('id')->first();
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
