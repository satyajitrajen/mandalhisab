<?php

namespace App\Http\Middleware;

use App\Models\BankAccount;
use App\Models\CashHandover;
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

        // Shallow routes like /receipt-books/{book} or /funds/handovers/{handover}
        // carry no mandal/festival in the path; derive the tenant from the
        // resource itself so downstream middleware (hisab.locked, role) check
        // the right festival. The resource's festival always wins over a
        // client-supplied header.
        $resourceFestivalId = $this->resolveResourceFestivalId($request);
        if ($resourceFestivalId === false) {
            return $this->error('NOT_FOUND', 'Resource not found', 404);
        }
        if ($resourceFestivalId !== null) {
            if ($festivalId && (string) $festivalId !== (string) $resourceFestivalId) {
                return $this->error('FORBIDDEN', 'Resource does not belong to the selected festival', 403);
            }
            $festivalId = $resourceFestivalId;
        }

        // Resolve the festival first and bind the tenant to its owning mandal.
        // This guarantees a festival id from the path OR an X-Festival-Id header
        // can never be used to reach another mandal's data.
        if ($festivalId) {
            $festival = Festival::find($festivalId);

            if (! $festival) {
                return $this->error('NOT_FOUND', 'Festival not found', 404);
            }

            if ($claimedMandalId && (string) $festival->mandal_id !== (string) $claimedMandalId) {
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

    /**
     * Route parameters that identify a festival-owned resource, mapped to
     * the model that owns them.
     *
     * @var array<string, class-string<Model>>
     */
    protected array $festivalResources = [
        'book' => ReceiptBook::class,
        'handover' => CashHandover::class,
        'account' => BankAccount::class,
    ];

    /**
     * Festival id of the resource named in the route, null when the route has
     * none, or false when the referenced resource doesn't exist.
     */
    protected function resolveResourceFestivalId(Request $request): string|false|null
    {
        foreach ($this->festivalResources as $param => $modelClass) {
            $value = $request->route($param);
            if ($value === null || $value === '') {
                continue;
            }

            $model = $value instanceof Model ? $value : $modelClass::find($value);

            return $model?->festival_id ?? false;
        }

        return null;
    }
}
