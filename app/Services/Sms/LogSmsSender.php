<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes SMS to the log. Replace with your gateway (e.g. AfroMessage, GeezSMS). */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::channel('single')->info('[SMS] to '.substr($phone, 0, 6).'****', ['message' => $message]);
    }
}
