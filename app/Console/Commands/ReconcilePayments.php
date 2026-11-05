<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payments\PaymentManager;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Re-check pending telebirr/Chapa payments whose webhook never arrived';

    public function handle(PaymentManager $payments): int
    {
        Payment::whereIn('provider', ['chapa', 'telebirr'])->where('status', 'pending')
            ->where('created_at', '>', now()->subDay())
            ->each(fn ($p) => rescue(fn () => $payments->reconcile($p)));

        return self::SUCCESS;
    }
}
