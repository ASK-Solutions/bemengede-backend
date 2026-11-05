<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, $roles, true), 403, 'You do not have access to this area.');
        if ($user->isOperatorStaff()) {
            abort_unless($user->operator_id, 403);
        }

        return $next($request);
    }
}
