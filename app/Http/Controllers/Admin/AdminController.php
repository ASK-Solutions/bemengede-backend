<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\City;
use App\Models\Dispute;
use App\Models\Operator;
use App\Models\OperatorDocument;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Terminal;
use App\Models\TransferVerification;
use App\Services\LedgerService;
use App\Services\StaffInviter;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        $tz = 'Africa/Addis_Ababa';
        $monthStart = now($tz)->startOfMonth()->utc();
        $paidMonth = Payment::where('status', 'succeeded')->where('verified_at', '>=', $monthStart);

        $days = collect(range(13, 0))->map(function ($i) use ($tz) {
            $d = now($tz)->subDays($i);
            $n = Booking::whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN])
                ->whereBetween('created_at', [$d->copy()->startOfDay()->utc(), $d->copy()->endOfDay()->utc()])->count();

            return ['date' => $d->toDateString(), 'bookings' => $n];
        });

        $top = Booking::whereIn('status', [Booking::CONFIRMED, Booking::CHECKED_IN])->where('created_at', '>=', $monthStart)
            ->selectRaw('operator_id, count(*) as bookings, sum(total) as gross')->groupBy('operator_id')->orderByDesc('gross')->limit(5)->get()
            ->map(fn ($r) => ['operator' => Operator::find($r->operator_id)?->name, 'bookings' => (int) $r->bookings, 'gross' => (int) $r->gross]);

        return response()->json([
            'gross_this_month' => (int) (clone $paidMonth)->sum('amount'),
            'paid_bookings_this_month' => (clone $paidMonth)->count(),
            'operators' => Operator::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'pending_transfer_reviews' => TransferVerification::where('decision', 'needs_review')->count(),
            'open_disputes' => Dispute::where('status', 'open')->count(),
            'refunds_due' => Payment::where('status', 'refund_due')->count(),
            'payment_mix' => (clone $paidMonth)->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider'),
            'bookings_by_day' => $days,
            'top_operators' => $top,
        ]);
    }

    // ---------- Operators ----------
    public function operators(): JsonResponse
    {
        return response()->json(Operator::orderBy('name')->get()->map(function (Operator $o) {
            $c = $o->checklist();

            return $o->only(['id', 'name', 'slug', 'category', 'status', 'partnership_signed_at', 'contact_phone', 'contact_email', 'created_at'])
                + ['steps_done' => collect($c)->where('done', true)->count(), 'steps_total' => count($c)];
        }));
    }

    public function operator(Operator $operator): JsonResponse
    {
        return response()->json($operator->toArray() + [
            'commission_effective' => $operator->commission(),
            'checklist' => $operator->checklist(),
            'documents' => $operator->documents()->get(['id', 'type', 'status', 'note', 'created_at']),
            'accounts' => $operator->accounts()->get(['bank', 'account_name', 'account_number']),
            'staff' => $operator->staff()->get(['id', 'name', 'email', 'role']),
            'counts' => ['buses' => $operator->buses()->count(), 'routes' => $operator->routes()->count(),
                'trips_upcoming' => $operator->trips()->where('departure_at', '>', now())->count()],
        ]);
    }

    public function updateOperator(Request $request, Operator $operator): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:30'],
            'trade_license_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'operating_license_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'chapa_subaccount_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'commission_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:30'],
        ]);
        $operator->update($data);
        AuditLog::record('admin.operator_updated', $operator, array_keys($data), $operator->id);

        return $this->operator($operator);
    }

    /**
     * Go live only when every onboarding step is done. Being named publicly as a partner
     * additionally requires the signed agreement (recorded here, by a person).
     */
    public function setStatus(Request $request, Operator $operator): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:under_review,active,suspended'],
            'partnership_signed' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('partnership_signed', $data)) {
            $operator->partnership_signed_at = $data['partnership_signed'] ? now() : null;
        }
        if ($data['status'] === 'active') {
            $missing = collect($operator->checklist())->where('done', false)
                ->reject(fn ($s) => $s['key'] === 'agreement' && ! empty($data['partnership_signed']))
                ->reject(fn ($s) => $s['key'] === 'chapa') // optional: Chapa split can be added later
                ->pluck('label');
            abort_if($missing->isNotEmpty(), 422, 'Not ready to go live. Missing: '.$missing->implode('; ').'.');
        }
        $operator->status = $data['status'];
        $operator->save();
        AuditLog::record('admin.operator_status', $operator, $data, $operator->id);

        return $this->operator($operator);
    }

    public function inviteOwner(Request $request, Operator $operator, StaffInviter $inviter): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', Phone::rule(), 'unique:users,phone'],
        ]);
        $inv = $inviter->invite($data + ['operator_id' => $operator->id, 'role' => 'operator_owner'], $request->user()->id);

        return response()->json(['id' => $inv->id, 'expires_at' => $inv->expires_at], 201);
    }

    public function document(OperatorDocument $document): StreamedResponse
    {
        AuditLog::record('admin.document_viewed', $document, [], $document->operator_id);

        return Storage::disk('local')->download($document->path);
    }

    public function decideDocument(Request $request, OperatorDocument $document): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'note' => ['nullable', 'string', 'max:300']]);
        $document->update($data);
        AuditLog::record('admin.document_'.$data['status'], $document, [], $document->operator_id);

        return response()->json($document->only(['id', 'type', 'status', 'note']));
    }

    // ---------- Payments ----------
    /** Read-only status. Credentials live in the server environment, never in the database or the browser. */
    public function providers(): JsonResponse
    {
        $p = config('bemengede.payments');
        $last = fn (string $provider) => Payment::where('provider', $provider)->where('status', 'succeeded')->max('verified_at');
        $today = fn (string $provider) => Payment::where('provider', $provider)->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'mode' => $p['mode'],
            'commission_percent' => $p['platform_commission_percent'],
            'service_fee_per_seat' => config('bemengede.pricing.service_fee_per_seat'),
            'providers' => [
                ['key' => 'telebirr', 'name' => 'telebirr', 'configured' => filled($p['telebirr']['merchant_app_id']) && filled($p['telebirr']['private_key_path']), 'webhook' => url('/api/v1/webhooks/telebirr'), 'last_success' => $last('telebirr'), 'today' => $today('telebirr'), 'fee_percent' => $p['gateway_fee_percent']['telebirr']],
                ['key' => 'chapa', 'name' => 'Chapa', 'configured' => filled($p['chapa']['secret_key']), 'webhook' => url('/api/v1/webhooks/chapa'), 'last_success' => $last('chapa'), 'today' => $today('chapa'), 'fee_percent' => $p['gateway_fee_percent']['chapa']],
                ['key' => 'verify_et', 'name' => 'verify.et', 'configured' => filled($p['verify_et']['api_key']), 'webhook' => url('/api/v1/webhooks/verify-et'), 'last_success' => $last('transfer'), 'today' => $today('transfer'),
                    'auto_approved_today' => TransferVerification::where('decision', 'auto_approved')->where('created_at', '>=', now()->startOfDay())->count(),
                    'needs_review' => TransferVerification::where('decision', 'needs_review')->count()],
            ],
            'refunds_due' => Payment::with('booking:id,reference,operator_id')->where('status', 'refund_due')->latest()->limit(50)->get(['id', 'booking_id', 'provider', 'amount', 'provider_reference', 'updated_at']),
        ]);
    }

    public function markRefunded(Request $request, Payment $payment): JsonResponse
    {
        abort_unless(in_array($payment->status, ['refund_due', 'succeeded'], true), 422);
        $request->validate(['reference' => ['required', 'string', 'max:80']]);
        $payment->update(['status' => 'refunded']);
        AuditLog::record('admin.payment_refunded', $payment, ['reference' => $request->reference], $payment->booking->operator_id);

        return response()->json(['ok' => true]);
    }

    // ---------- Settlements ----------
    public function settlements(): JsonResponse
    {
        return response()->json(Settlement::with('operator:id,name')->latest('period_end')->limit(200)->get());
    }

    public function generateSettlements(Request $request, LedgerService $ledger): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from', 'before:today']]);
        $tz = 'Africa/Addis_Ababa';
        $created = $ledger->generate(Carbon::parse($d['from'], $tz)->startOfDay()->utc(), Carbon::parse($d['to'], $tz)->endOfDay()->utc());
        AuditLog::record('admin.settlements_generated', null, $d + ['count' => $created->count()]);

        return response()->json(['created' => $created->count()]);
    }

    public function markSettlementPaid(Request $request, Settlement $settlement): JsonResponse
    {
        $d = $request->validate(['payout_reference' => ['required', 'string', 'max:80']]);
        abort_unless($settlement->status === 'scheduled', 422, 'Only scheduled payouts can be marked paid.');
        $settlement->update(['status' => 'paid', 'paid_at' => now()] + $d);
        AuditLog::record('admin.settlement_paid', $settlement, $d, $settlement->operator_id);

        return response()->json($settlement);
    }

    // ---------- Disputes ----------
    public function disputes(Request $request): JsonResponse
    {
        return response()->json(Dispute::with('booking:id,reference,total,status', 'operator:id,name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->limit(200)->get());
    }

    public function resolveDispute(Request $request, Dispute $dispute): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:resolved,rejected'], 'resolution' => ['required', 'string', 'max:1000']]);
        $dispute->update($d + ['resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        AuditLog::record('admin.dispute_'.$d['status'], $dispute, [], $dispute->operator_id);

        return response()->json($dispute);
    }

    // ---------- Master data ----------
    public function cities(): JsonResponse
    {
        return response()->json(City::with(['terminals' => fn ($q) => $q->orderBy('name')])->orderBy('name_en')->get());
    }

    public function storeCity(Request $request): JsonResponse
    {
        $d = $request->validate(['name_en' => ['required', 'string', 'max:60', 'unique:cities,name_en'], 'name_am' => ['nullable', 'string', 'max:60'], 'region' => ['nullable', 'string', 'max:60']]);

        return response()->json(City::create($d), 201);
    }

    public function storeTerminal(Request $request): JsonResponse
    {
        $d = $request->validate(['city_id' => ['required', 'integer', 'exists:cities,id'], 'name' => ['required', 'string', 'max:80']]);

        return response()->json(Terminal::create($d), 201);
    }

    // ---------- Audit ----------
    public function audit(Request $request): JsonResponse
    {
        return response()->json(AuditLog::query()
            ->when($request->query('operator_id'), fn ($q, $v) => $q->where('operator_id', $v))
            ->when($request->query('action'), fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->latest('id')->paginate(50));
    }
}
