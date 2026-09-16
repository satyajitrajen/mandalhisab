<?php

namespace App\Http\Middleware;

use App\Services\PaymentService;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRegistrationPaid
{
    use ApiResponse;

    /**
     * Block festival/mandal business APIs until the mandal registration fee is paid.
     * Auth, payments, and mandal list/create stay allowed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExempt($request)) {
            return $next($request);
        }

        $membership = $request->attributes->get('current_membership');
        if (! $membership) {
            return $next($request);
        }

        $mandal = $membership->mandal ?? $membership->mandal()->first();
        if ($mandal && $mandal->registration_paid_at === null) {
            return $this->error(
                'PAYMENT_REQUIRED',
                'Mandal registration fee of '.PaymentService::amountLabel().' is unpaid',
                402
            );
        }

        return $next($request);
    }

    protected function isExempt(Request $request): bool
    {
        if ($request->is('api/v1/payments/*') || $request->is('api/v1/auth/*')) {
            return true;
        }

        if ($request->is('api/v1/mandals') && in_array($request->method(), ['GET', 'POST'], true)) {
            return true;
        }

        return false;
    }
}
