<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentException;
use App\Http\Requests\CreateRegistrationOrderRequest;
use App\Http\Requests\VerifyRegistrationPaymentRequest;
use App\Services\PaymentService;
use App\Traits\ApiResponse;

class PaymentController
{
    use ApiResponse;

    public function __construct(protected PaymentService $paymentService) {}

    /**
     * POST /api/v1/payments/create-order
     */
    public function createOrder(CreateRegistrationOrderRequest $request)
    {
        try {
            $data = $this->paymentService->createRegistrationOrder(
                $request->user(),
                $request->validated('mandalId')
            );

            return $this->success($data, $data['alreadyPaid'] ? 'Mandal already registered' : 'Order created');
        } catch (PaymentException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /**
     * POST /api/v1/payments/verify
     */
    public function verify(VerifyRegistrationPaymentRequest $request)
    {
        try {
            $data = $this->paymentService->verifyRegistrationPayment(
                $request->user(),
                $request->validated()
            );

            return $this->success($data, 'Payment verified');
        } catch (PaymentException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }
}
