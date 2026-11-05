<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Operator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function operators(): JsonResponse
    {
        return response()->json(Operator::orderBy('name')->get());
    }

    public function setStatus(Request $request, Operator $operator): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:under_review,active,suspended'],
            'partnership_signed' => ['sometimes', 'boolean'],
        ]);
        $operator->status = $data['status'];
        if (array_key_exists('partnership_signed', $data)) {
            $operator->partnership_signed_at = $data['partnership_signed'] ? now() : null;
        }
        $operator->save();
        AuditLog::record('operator.status', $operator, $data, $operator->id);

        return response()->json($operator);
    }
}
