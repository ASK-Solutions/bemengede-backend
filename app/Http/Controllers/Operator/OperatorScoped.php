<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use Illuminate\Http\Request;

/** Base for every operator-portal controller: all data is limited to the staff member's company. */
abstract class OperatorScoped extends Controller
{
    protected function opId(Request $r): int
    {
        return (int) $r->user()->operator_id;
    }

    protected function operator(Request $r): Operator
    {
        return Operator::findOrFail($this->opId($r));
    }

    /** 404 (not 403) so staff can't even learn that another company's record exists. */
    protected function own(Request $r, ?object $model): void
    {
        abort_unless($model && (int) $model->operator_id === $this->opId($r), 404);
    }

    protected function ownerOnly(Request $r): void
    {
        abort_unless($r->user()->role === \App\Models\User::ROLE_OPERATOR_OWNER, 403, 'Only the company owner can do this.');
    }
}
