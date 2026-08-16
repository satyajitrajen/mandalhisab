<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\MandalMember;
use App\Traits\ApiResponse;

class TenantScope
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        if (! $user) {
            return $next($request);
        }

        $pathMandalId = $request->route('mandal');
        $pathFestivalId = $request->route('festival');
        $headerMandalId = $request->header('X-Mandal-Id');
        $headerFestivalId = $request->header('X-Festival-Id');

        // Handle model instances from implicit binding
        if ($pathMandalId instanceof \Illuminate\Database\Eloquent\Model) {
            $pathMandalId = $pathMandalId->getKey();
        }
        if ($pathFestivalId instanceof \Illuminate\Database\Eloquent\Model) {
            $pathFestivalId = $pathFestivalId->getKey();
        }

        $mandalId = $pathMandalId ?? $headerMandalId;
        $festivalId = $pathFestivalId ?? $headerFestivalId;

        if ($mandalId) {
            $membership = MandalMember::where('mandal_id', $mandalId)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if (! $membership) {
                return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
            }

            $request->attributes->set('current_membership', $membership);
        }

        if ($festivalId && ! $mandalId) {
            $festival = \App\Models\Festival::find($festivalId);
            if ($festival) {
                $membership = MandalMember::where('mandal_id', $festival->mandal_id)
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->first();

                if (! $membership) {
                    return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
                }

                $request->attributes->set('current_membership', $membership);
            }
        }

        return $next($request);
    }
}
