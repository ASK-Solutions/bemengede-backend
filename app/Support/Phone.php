<?php

namespace App\Support;

class Phone
{
    /** Normalise Ethiopian mobile numbers (09…, 07…, 9…, +2519…) to +2519XXXXXXXX. */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $raw);
        if (preg_match('/^(?:\+?251|0)?([79]\d{8})$/', $digits, $m)) {
            return '+251'.$m[1];
        }

        return null;
    }

    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! self::normalize($value)) {
                $fail('Enter an Ethiopian mobile number, for example 0911 234 567.');
            }
        };
    }
}
