<?php

namespace App\Services\Payments;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\TransferVerification;
use App\Services\BookingService;
use App\Services\SeatHoldService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentManager
{
    public function __construct(
        private BookingService $bookings,
        private SeatHoldService $holds,
        private VerifyEtClient $verifyEt,
    ) {}

    public function provider(string $name): PaymentProvider
    {
        return match ($name) {
            'chapa' => app(ChapaProvider::class),
            'telebirr' => app(TelebirrProvider::class),
            default => throw ValidationException::withMessages(['provider' => 'Unknown payment method.']),
        };
    }

    public function start(Booking $booking, string $method): Payment
    {
        if ($booking->status !== Booking::PENDING) {
            throw ValidationException::withMessages(['booking' => 'This booking is not waiting for payment.']);
        }

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'provider' => $method,
            'tx_ref' => 'bm_'.Str::lower(Str::ulid()),
            'amount' => $booking->total,
            'status' => 'initiated',
        ]);

        if ($method === 'transfer') {
            // Give people time to make the transfer in their bank app.
            $mins = config('bemengede.booking.transfer_hold_minutes');
            $booking->update(['expires_at' => now()->addMinutes($mins)]);
            $this->holds->extend($booking->id, $mins);

            return $payment;
        }

        $payment->update(['checkout_url' => $this->provider($method)->initiate($payment), 'status' => 'pending']);

        return $payment;
    }

    /** Confirm a booking only after asking the provider directly. Safe to call repeatedly. */
    public function reconcile(Payment $payment): Payment
    {
        if ($payment->status === 'succeeded' || in_array($payment->provider, ['transfer', 'cash'], true)) {
            return $payment;
        }
        $result = $this->provider($payment->provider)->verify($payment);

        if ($result->succeeded()) {
            if ($result->amount !== null && $result->amount < $payment->amount) {
                $payment->update(['status' => 'failed', 'provider_response' => $result->raw + ['reason' => 'amount_mismatch']]);
                AuditLog::record('payment.amount_mismatch', $payment, ['expected' => $payment->amount, 'got' => $result->amount], $payment->booking->operator_id);

                return $payment;
            }
            if (! $this->bookings->revive($payment->booking)) {
                // Paid too late and the seats were sold: never keep the money without a ticket.
                $payment->update(['status' => 'refund_due', 'provider_reference' => $result->providerReference, 'verified_at' => now()]);
                AuditLog::record('payment.refund_due', $payment, ['reason' => 'seats_no_longer_available'], $payment->booking->operator_id);

                return $payment->fresh();
            }
            DB::transaction(function () use ($payment, $result) {
                $payment->update([
                    'status' => 'succeeded',
                    'provider_reference' => $result->providerReference,
                    'provider_response' => $result->raw,
                    'verified_at' => now(),
                ]);
                $this->bookings->confirm($payment->booking);
            });
        } elseif ($result->status === 'failed') {
            $payment->update(['status' => 'failed', 'provider_response' => $result->raw]);
        }

        return $payment->fresh();
    }

    /**
     * Bank/wallet transfer checked with verify.et. Auto-confirm only when ALL checks pass;
     * otherwise the operator reviews it. A reference can only ever be used once.
     */
    public function verifyTransfer(Payment $payment, string $bank, string $reference): TransferVerification
    {
        $booking = $payment->booking;
        $reference = strtoupper(trim($reference));

        if (TransferVerification::where('reference', $reference)->exists()) {
            throw ValidationException::withMessages(['reference' => 'This transaction reference has already been used for another booking.']);
        }

        $r = $this->verifyEt->verify($bank, $reference);
        if (config('bemengede.payments.mode') === 'mock') {
            $r['amount'] = ! empty($r['mock_short']) ? $payment->amount - 20000 : $payment->amount;
            $r['receiver_account'] ??= $booking->operator->accounts()->value('account_number');
        }

        $accounts = $booking->operator->accounts()->pluck('account_number')
            ->map(fn ($a) => preg_replace('/\D/', '', $a));
        $receiver = preg_replace('/\D/', '', (string) $r['receiver_account']);
        $receiverMatches = $receiver !== '' && $accounts->contains(fn ($a) => $a === $receiver || (strlen($receiver) >= 4 && str_ends_with($a, substr($receiver, -4)) && str_starts_with($a, substr($receiver, 0, 4))));
        $paidAt = $r['paid_at'] ? \Illuminate\Support\Carbon::parse($r['paid_at']) : null;

        $checks = [
            'found' => $r['status'] === 'verified',
            'amount' => $r['amount'] !== null && $r['amount'] >= $payment->amount,
            'receiver' => $receiverMatches,
            'after_booking' => $paidAt !== null && $paidAt->gte($booking->created_at->copy()->subMinutes(5)),
            'unused' => true,
        ];
        $allPass = ! in_array(false, $checks, true);

        try {
            $tv = TransferVerification::create([
                'payment_id' => $payment->id,
                'bank' => $bank,
                'reference' => $reference,
                'verify_et_id' => $r['id'],
                'verified_amount' => $r['amount'],
                'receiver_account' => $r['receiver_account'] ? '••••'.substr($receiver, -4) : null,
                'paid_at' => $paidAt,
                'checks' => $checks,
                'decision' => $allPass ? 'auto_approved' : 'needs_review',
                'decided_at' => $allPass ? now() : null,
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages(['reference' => 'This transaction reference has already been used for another booking.']);
        }

        if ($allPass) {
            DB::transaction(function () use ($payment, $reference) {
                $payment->update(['status' => 'succeeded', 'provider_reference' => $reference, 'verified_at' => now()]);
                $this->bookings->confirm($payment->booking);
            });
        } else {
            $payment->update(['status' => 'pending']);
            $booking->update(['status' => Booking::AWAITING_VERIFICATION, 'expires_at' => null]);
            // Keep the seats while the bus company reviews the transfer (until departure at the latest).
            \App\Models\SeatHold::where('booking_id', $booking->id)->update(['expires_at' => $booking->trip->departure_at]);
        }

        return $tv;
    }

    public function decideTransfer(TransferVerification $tv, bool $approve, int $reviewerId): void
    {
        DB::transaction(function () use ($tv, $approve, $reviewerId) {
            $tv->update(['decision' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $reviewerId, 'decided_at' => now()]);
            $payment = $tv->payment;
            if ($approve) {
                $payment->update(['status' => 'succeeded', 'provider_reference' => $tv->reference, 'verified_at' => now()]);
                $this->bookings->confirm($payment->booking);
            } else {
                $payment->update(['status' => 'failed']);
                $this->bookings->expire($payment->booking);
            }
        });
        AuditLog::record($approve ? 'transfer.approved' : 'transfer.rejected', $tv);
    }
}
