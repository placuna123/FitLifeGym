<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role gate: `->middleware('role:Admin')`, `->middleware('role:Admin,SuperAdmin')`.
 *
 * Centralizes what used to be a hand-rolled `$user->role !== 'X'` check
 * copy-pasted (inconsistently — some forgot it entirely) into individual
 * controller methods.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role ?? null, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
