<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\MemberRole;
use App\Traits\ApiResponse;

class RequireRole
{
    use ApiResponse;

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $membership = $request->attributes->get('current_membership');

        if (! $membership) {
            return $this->error('FORBIDDEN', 'Mandal context required for this action', 403);
        }

        $allowedRoles = array_map(fn ($r) => MemberRole::from($r), $roles);
        $userRole = $membership->role;

        // SUPER_ADMIN can do everything
        if ($userRole === MemberRole::SUPER_ADMIN) {
            return $next($request);
        }

        if (! in_array($userRole, $allowedRoles, true)) {
            return $this->error('FORBIDDEN', 'You do not have permission to perform this action', 403);
        }

        return $next($request);
    }
}
