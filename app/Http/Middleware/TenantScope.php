<?php

namespace App\Http\Middleware;

use App\Models\Festival;
use App\Models\MandalMember;
use App\Models\ReceiptBook;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        if ($pathMandalId instanceof Model) {
            $pathMandalId = $pathMandalId->getKey();
        }
        if ($pathFestivalId instanceof Model) {
            $pathFestivalId = $pathFestivalId->getKey();
        }

        $claimedMandalId = $pathMandalId ?: $headerMandalId;
        $festivalId = $pathFestivalId ?: $headerFestivalId;

        // Shallow routes like /receipt-books/{book} carry no mandal/festival
        // in the path; derive the tenant from the bound resource instead so
        // downstream middleware (hisab.locked, role) still have context.
        $boundBook = $request->route('book');
        if (! $festivalId && $boundBook instanceof ReceiptBook) {
            $festivalId = $boundBook->festival_id;
        }

        // Resolve the festival first and bind the tenant to its owning mandal.
        // This guarantees a festival id from the path OR an X-Festival-Id header
        // can never be used to reach another mandal's data.
        if ($festivalId) {
            $festival = Festival::find($festivalId);

            if (! $festival) {
                return $this->error('NOT_FOUND', 'Festival not found', 404);
            }

            if ($claimedMandalId && $festival->mandal_id !== $claimedMandalId) {
                return $this->error('FORBIDDEN', 'Festival does not belong to the selected mandal', 403);
            }

            $mandalId = $festival->mandal_id;
        } else {
            $mandalId = $claimedMandalId;
        }

        if ($mandalId) {
            $membership = MandalMember::with('mandal')
                ->where('mandal_id', $mandalId)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if (! $membership) {
                return $this->error('FORBIDDEN', 'You are not a member of this mandal', 403);
            }

            $request->attributes->set('current_membership', $membership);
            $request->attributes->set('current_mandal_id', $mandalId);
            $request->attributes->set('current_festival_id', $festivalId);
        }

        return $next($request);
    }
}
