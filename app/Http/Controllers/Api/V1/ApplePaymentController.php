<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentException;
use App\Http\Requests\VerifyApplePaymentRequest;
use App\Services\AppleGateway;
use App\Services\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplePaymentController
{
    use ApiResponse;

    public function __construct(protected PaymentService $paymentService) {}

    /**
     * POST /api/v1/payments/apple/verify
     */
    public function verify(VerifyApplePaymentRequest $request)
    {
        try {
            $data = $this->paymentService->verifyAppleRegistrationPayment(
                $request->user(),
                $request->validated()
            );

            return $this->success($data, 'Subscription verified');
        } catch (PaymentException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /**
     * POST /api/v1/payments/apple/webhook — App Store Server Notifications V2.
     * The signed payload is only used to find the transaction ID; state is
     * re-fetched from Apple. Junk gets 200 so Apple does not retry it.
     */
    public function webhook(Request $request)
    {
        $notification = AppleGateway::decodeJwsPayload((string) $request->input('signedPayload', ''));
        $transaction = AppleGateway::decodeJwsPayload((string) ($notification['data']['signedTransactionInfo'] ?? ''));
        $transactionId = (string) ($transaction['transactionId'] ?? '');

        if ($transactionId === '' || strlen($transactionId) > 64) {
            return $this->success(null, 'Ignored');
        }

        try {
            $this->paymentService->applyAppleNotification($transactionId);
        } catch (PaymentException $e) {
            if ($e->errorCode === 'PAYMENT_GATEWAY_ERROR') {
                // Apple's API was unreachable: let Apple retry the notification.
                return $this->error($e->errorCode, $e->getMessage(), 503);
            }
        } catch (Throwable $e) {
            Log::warning('Apple notification not applied', [
                'notification_type' => $notification['notificationType'] ?? null,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success(null, 'Received');
    }
}
