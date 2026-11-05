<?php

namespace App\Http\Controllers\Operator;

use App\Models\AuditLog;
use App\Models\OperatorAccount;
use App\Models\OperatorDocument;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\StaffInviter;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends OperatorScoped
{
    public function show(Request $request): JsonResponse
    {
        $op = $this->operator($request);

        return response()->json($op->only([
            'id', 'name', 'slug', 'status', 'legal_name', 'trade_license_no', 'tin', 'operating_license_no', 'contact_email', 'contact_phone',
            'address', 'brand_color', 'logo_alt', 'free_cancel_hours', 'late_cancel_fee_percent', 'partnership_signed_at',
        ]) + [
            'commission_percent' => $op->commission(),
            'portal_url' => rtrim(config('bemengede.frontend_url'), '/').'/operator',
            'widget_snippet' => '<iframe src="'.rtrim(config('bemengede.frontend_url'), '/').'/?operator='.$op->slug.'" title="Book '.$op->name.' tickets on Bemengede" style="width:100%;height:640px;border:0"></iframe>',
            'accounts' => $op->accounts()->get(['id', 'bank', 'account_name', 'account_number']),
            'documents' => $op->documents()->get(['id', 'type', 'status', 'note', 'created_at']),
            'checklist' => $op->checklist(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate([
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'trade_license_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:30'],
            'operating_license_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'contact_email' => ['sometimes', 'nullable', 'email'],
            'contact_phone' => ['sometimes', 'nullable', 'string', Phone::rule()],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'brand_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_alt' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Policy changes apply to new bookings; existing tickets keep the policy shown when bought.
            'free_cancel_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'late_cancel_fee_percent' => ['sometimes', 'integer', 'min:0', 'max:50'],
        ]);
        if (isset($data['contact_phone'])) {
            $data['contact_phone'] = Phone::normalize($data['contact_phone']);
        }
        $op = $this->operator($request);
        $op->update($data);
        AuditLog::record('operator.settings', $op, array_keys($data));

        return $this->show($request);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate([
            'bank' => ['required', 'in:'.implode(',', config('bemengede.payments.verify_et.banks'))],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'regex:/^[0-9 ]{4,30}$/'],
        ]);
        $acc = OperatorAccount::create(['operator_id' => $this->opId($request)] + $data);
        AuditLog::record('operator.account_added', $acc, ['bank' => $data['bank']]);

        return response()->json($acc, 201);
    }

    public function destroyAccount(Request $request, OperatorAccount $account): JsonResponse
    {
        $this->own($request, $account);
        $this->ownerOnly($request);
        $account->delete();
        AuditLog::record('operator.account_removed', $account);

        return response()->json(['ok' => true]);
    }

    /** Upload a licence document (PDF/JPG/PNG, max 5 MB). Stored privately, never public. */
    public function uploadDocument(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate([
            'type' => ['required', 'in:trade_license,tin,operating_license,other'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);
        $path = $request->file('file')->store('operator-documents/'.$this->opId($request), 'local');
        $doc = OperatorDocument::create(['operator_id' => $this->opId($request), 'type' => $data['type'], 'path' => $path]);
        AuditLog::record('operator.document_uploaded', $doc, ['type' => $data['type']]);

        return response()->json($doc->only(['id', 'type', 'status']), 201);
    }

    public function staff(Request $request): JsonResponse
    {
        $op = $this->opId($request);

        return response()->json([
            'members' => User::where('operator_id', $op)->get(['id', 'name', 'email', 'phone', 'role', 'created_at']),
            'invitations' => StaffInvitation::where('operator_id', $op)->whereNull('accepted_at')->where('expires_at', '>', now())
                ->get(['id', 'name', 'email', 'role', 'expires_at']),
        ]);
    }

    public function invite(Request $request, StaffInviter $inviter): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', Phone::rule(), 'unique:users,phone'],
            'role' => ['required', 'in:operator_owner,operator_agent'],
        ]);
        $inv = $inviter->invite($data + ['operator_id' => $this->opId($request)], $request->user()->id);

        return response()->json(['id' => $inv->id, 'expires_at' => $inv->expires_at], 201);
    }

    public function removeStaff(Request $request, User $user): JsonResponse
    {
        $this->ownerOnly($request);
        $this->own($request, $user);
        abort_if($user->id === $request->user()->id, 422, 'You can’t remove yourself.');
        $user->tokens()->delete();
        $user->forceFill(['operator_id' => null, 'role' => User::ROLE_PASSENGER, 'password' => null])->save();
        AuditLog::record('operator.staff_removed', $user);

        return response()->json(['ok' => true]);
    }
}
