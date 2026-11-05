<?php

namespace App\Services\Sms;

interface SmsSender
{
    public function send(string $phone, string $message): void;
}
