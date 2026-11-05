<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StaffInvitation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class StaffInviter
{
    /** Staff accounts are never self-registered: an owner or admin invites them by email. */
    public function invite(array $data, ?int $invitedBy): StaffInvitation
    {
        $inv = StaffInvitation::create([
            'operator_id' => $data['operator_id'] ?? null,
            'email' => $data['email'],
            'name' => $data['name'],
            'phone' => \App\Support\Phone::normalize($data['phone']) ?? $data['phone'],
            'role' => $data['role'],
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
            'invited_by' => $invitedBy,
        ]);
        Mail::raw(
            "Hello {$inv->name},\n\nYou've been invited to the Bemengede ".($inv->operator_id ? 'operator portal' : 'admin')." as {$inv->role}.\n"
            ."Set your password here (valid for 7 days):\n{$inv->acceptUrl()}\n\nIf you weren't expecting this, ignore this email.",
            fn ($m) => $m->to($inv->email)->subject('Your Bemengede staff invitation'),
        );
        AuditLog::record('staff.invited', $inv, ['role' => $inv->role], $inv->operator_id);

        return $inv;
    }
}
