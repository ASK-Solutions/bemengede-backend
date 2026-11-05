<?php

namespace App\Services\Payments;

final class PaymentResult
{
    public function __construct(
        public readonly string $status, // succeeded | pending | failed
        public readonly ?int $amount = null,
        public readonly ?string $providerReference = null,
        public readonly array $raw = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
