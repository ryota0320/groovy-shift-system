<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;
        $allowedRoles = array_map(
            static fn (string $value): ?UserRole => UserRole::tryFrom($value),
            $roles,
        );

        abort_unless($role instanceof UserRole && in_array($role, $allowedRoles, true), 403);

        return $next($request);
    }
}
