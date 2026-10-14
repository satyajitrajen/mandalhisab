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
     * Block festival/mandal business APIs unless the yearly registration is current.
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
        if ($mandal && ! $mandal->isRegistrationActive()) {
            $paidThrough = $mandal->registration_paid_through?->toDateString();

            return $this->error(
                'PAYMENT_REQUIRED',
                $paidThrough
                    ? 'Mandal registration expired on '.$paidThrough.'. Renew for '.PaymentService::amountLabel().'/year'
                    : 'Mandal registration fee of '.PaymentService::amountLabel().'/year is unpaid',
                402,
                ['mandalId' => $mandal->id, 'paidThrough' => $paidThrough, 'expired' => $paidThrough !== null]
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
