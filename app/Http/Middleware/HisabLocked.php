<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\FinalHisabAudit;
use App\Traits\ApiResponse;

class HisabLocked
{
    use ApiResponse;

    /**
     * Block financial mutations once the festival's final hisab is locked.
     * Detects the festival id from the route parameter or X-Festival-Id header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $festivalId = $this->resolveFestivalId($request);

        if (! $festivalId) {
            // No festival context on this route — allow through.
            return $next($request);
        }

        $locked = FinalHisabAudit::where('festival_id', $festivalId)
            ->where('is_locked', true)
            ->exists();

        if ($locked) {
            return $this->error(
                'HISAB_LOCKED',
                'Final hisab is locked. No further changes are allowed.',
                409
            );
        }

        return $next($request);
    }

    protected function resolveFestivalId(Request $request): ?string
    {
        $routeFestival = $request->route('festival');

        if ($routeFestival instanceof \Illuminate\Database\Eloquent\Model) {
            return $routeFestival->getKey();
        }

        if (is_string($routeFestival) && $routeFestival !== '') {
            return $routeFestival;
        }

        $headerFestival = $request->header('X-Festival-Id');
        if (is_string($headerFestival) && $headerFestival !== '') {
            return $headerFestival;
        }

        return null;
    }
}
