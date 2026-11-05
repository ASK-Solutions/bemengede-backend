<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Operator;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PartnerApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:80'],
            'contact_phone' => ['required', 'string', Phone::rule()],
            'contact_email' => ['nullable', 'email:rfc'],
            'fleet_size' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'accept_privacy' => ['accepted'],
        ], ['accept_privacy.accepted' => 'Please confirm we may use these details to contact you about partnering.']);

        $op = Operator::create([
            'name' => $data['company_name'],
            'slug' => Str::slug($data['company_name']).'-'.Str::lower(Str::random(4)),
            'status' => 'applied',
            'contact_phone' => Phone::normalize($data['contact_phone']),
            'contact_email' => $data['contact_email'] ?? null,
        ]);
        AuditLog::record('operator.applied', $op, ['contact_name' => $data['contact_name'], 'fleet_size' => $data['fleet_size'] ?? null], $op->id);

        return response()->json(['status' => 'received'], 201);
    }
}
