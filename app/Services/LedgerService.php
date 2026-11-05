<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\Payment;
use App\Models\Settlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Money split for every successful payment:
 *   fares     = what the passenger paid minus the service fee, minus any refund
 *   commission= platform commission on fares (online channels; counter only if agreed)
 *   net       = fares − commission  → owed to the bus company
 * The service fee is the platform's and pays the gateway fee.
 */
class LedgerService
{
    /** @return array{gross:int, service_fees:int, fares:int, commission:int, gateway_fee:int, net_to_operator:int} */
    public function breakdown(Payment $p): array
    {
        $b = $p->booking;
        $fares = max(0, $b->total - $b->service_fee - (int) $b->refund_amount);
        $commissionable = $p->provider !== 'cash' || config('bemengede.payments.commission_on_counter_sales');
        $commission = $commissionable ? (int) round($fares * $b->operator->commission() / 100) : 0;
        $feePct = (float) (config("bemengede.payments.gateway_fee_percent.{$p->provider}") ?? 0);

        return [
            'gross' => $p->amount,
            'service_fees' => $b->service_fee,
            'fares' => $fares,
            'commission' => $commission,
            'gateway_fee' => (int) round($p->amount * $feePct / 100),
            'net_to_operator' => $fares - $commission,
        ];
    }

    /** Successful payments for one operator in a period (Addis Ababa dates). */
    public function payments(int $operatorId, Carbon $from, Carbon $to): Collection
    {
        return Payment::with('booking.operator')
            ->where('status', 'succeeded')
            ->whereBetween('verified_at', [$from, $to])
            ->whereHas('booking', fn ($q) => $q->where('operator_id', $operatorId))
            ->get();
    }

    /** @return array<string, array> totals per channel */
    public function summary(int $operatorId, Carbon $from, Carbon $to): array
    {
        $out = [];
        foreach ($this->payments($operatorId, $from, $to) as $p) {
            $row = $out[$p->provider] ??= ['channel' => $p->provider, 'count' => 0, 'gross' => 0, 'service_fees' => 0, 'fares' => 0, 'commission' => 0, 'gateway_fee' => 0, 'net_to_operator' => 0];
            $row['count']++;
            foreach ($this->breakdown($p) as $k => $v) {
                $row[$k] += $v;
            }
            $out[$p->provider] = $row;
        }

        return array_values($out);
    }

    /**
     * Create settlement records for every operator and channel in a period.
     * Chapa split payments settle automatically; transfers and cash went straight
     * to the operator (commission is invoiced); telebirr is paid out by the platform.
     *
     * @return Collection<int, Settlement>
     */
    public function generate(Carbon $from, Carbon $to): Collection
    {
        $created = collect();
        foreach (Operator::whereIn('status', ['active', 'suspended'])->get() as $op) {
            $byChannel = Payment::with('booking.operator')->where('status', 'succeeded')->whereNull('settlement_id')
                ->whereBetween('verified_at', [$from, $to])
                ->whereHas('booking', fn ($q) => $q->where('operator_id', $op->id))
                ->get()->groupBy('provider');

            foreach ($byChannel as $channel => $payments) {
                DB::transaction(function () use ($op, $channel, $payments, $from, $to, $created) {
                    $t = ['gross' => 0, 'gateway_fee' => 0, 'commission' => 0, 'service_fees' => 0, 'net_to_operator' => 0];
                    foreach ($payments as $p) {
                        $b = $this->breakdown($p);
                        foreach (array_keys($t) as $k) {
                            $t[$k] += $b[$k];
                        }
                    }
                    $status = match ($channel) {
                        'chapa' => $op->chapa_subaccount_id ? 'settled_by_provider' : 'scheduled',
                        'transfer', 'cash' => 'invoiced',
                        default => 'scheduled',
                    };
                    $s = Settlement::create([
                        'operator_id' => $op->id,
                        'period_start' => $from->copy()->setTimezone('Africa/Addis_Ababa')->toDateString(),
                        'period_end' => $to->copy()->setTimezone('Africa/Addis_Ababa')->toDateString(),
                        'channel' => $channel,
                        'status' => $status,
                    ] + $t);
                    Payment::whereIn('id', $payments->pluck('id'))->update(['settlement_id' => $s->id]);
                    $created->push($s);
                });
            }
        }

        return $created;
    }
}
