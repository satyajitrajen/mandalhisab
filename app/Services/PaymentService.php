<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Enums\RegistrationPaymentStatus;
use App\Exceptions\PaymentException;
use App\Models\Mandal;
use App\Models\MandalMember;
use App\Models\RegistrationPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentService
{
    public function __construct(protected RazorpayGateway $gateway) {}

    /**
     * Create (or reuse) a Razorpay order for the admin's unpaid mandal.
     * Amount is always taken from config — client-supplied amounts are ignored.
     */
    public function createRegistrationOrder(User $user, ?string $mandalId = null): array
    {
        $mandal = $this->resolvePayableMandal($user, $mandalId);
        $amountPaise = $this->registrationAmountPaise();
        $currency = $this->currency();
        $keyId = (string) config('services.razorpay.key_id');

        if ($keyId === '' || (string) config('services.razorpay.key_secret') === '') {
            throw new PaymentException('Payment gateway is not configured', 'PAYMENT_UNAVAILABLE', 503);
        }

        if ($mandal->registration_paid_at !== null) {
            return [
                'alreadyPaid' => true,
                'keyId' => $keyId,
                'orderId' => null,
                'amountPaise' => $amountPaise,
                'currency' => $currency,
                'mandalId' => $mandal->id,
                'mandalName' => $mandal->name,
            ];
        }

        $existing = RegistrationPayment::query()
            ->where('mandal_id', $mandal->id)
            ->where('status', RegistrationPaymentStatus::CREATED)
            ->where('amount_paise', $amountPaise)
            ->latest('id')
            ->first();

        if ($existing) {
            Log::info('Reusing unpaid Razorpay registration order', [
                'mandal_id' => $mandal->id,
                'user_id' => $user->id,
                'razorpay_order_id' => $existing->razorpay_order_id,
                'amount_paise' => $amountPaise,
            ]);

            return $this->orderPayload($existing, $mandal, $user, $keyId, alreadyPaid: false);
        }

        $receipt = 'mndreg_'.substr($mandal->id, 0, 20);

        try {
            $order = $this->gateway->createOrder($amountPaise, $currency, $receipt);
        } catch (Throwable $e) {
            Log::error('Razorpay create-order failed', [
                'mandal_id' => $mandal->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw new PaymentException('Unable to start payment. Please try again.', 'PAYMENT_GATEWAY_ERROR', 500);
        }

        $orderId = $order['id'] ?? '';
        if ($orderId === '') {
            throw new PaymentException('Unable to start payment. Please try again.', 'PAYMENT_GATEWAY_ERROR', 500);
        }

        $payment = RegistrationPayment::create([
            'mandal_id' => $mandal->id,
            'user_id' => $user->id,
            'razorpay_order_id' => $orderId,
            'amount_paise' => $amountPaise,
            'currency' => $currency,
            'status' => RegistrationPaymentStatus::CREATED,
        ]);

        Log::info('Registration Razorpay order created', [
            'mandal_id' => $mandal->id,
            'user_id' => $user->id,
            'razorpay_order_id' => $orderId,
            'amount_paise' => $amountPaise,
        ]);

        return $this->orderPayload($payment, $mandal, $user, $keyId, alreadyPaid: false);
    }

    /**
     * Verify checkout signature. Marks the mandal paid only on HMAC match.
     */
    public function verifyRegistrationPayment(User $user, array $payload): array
    {
        $orderId = $payload['razorpayOrderId'];
        $paymentId = $payload['razorpayPaymentId'];
        $signature = $payload['razorpaySignature'];
        $secret = (string) config('services.razorpay.key_secret');

        if ($secret === '') {
            throw new PaymentException('Payment gateway is not configured', 'PAYMENT_UNAVAILABLE', 503);
        }

        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Razorpay signature mismatch', [
                'user_id' => $user->id,
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
            ]);

            $record = RegistrationPayment::query()
                ->where('razorpay_order_id', $orderId)
                ->first();

            if ($record && $record->status !== RegistrationPaymentStatus::PAID) {
                $record->update(['status' => RegistrationPaymentStatus::FAILED]);
            }

            throw new PaymentException('Payment verification failed', 'PAYMENT_VERIFICATION_FAILED', 400);
        }

        $record = RegistrationPayment::query()
            ->where('razorpay_order_id', $orderId)
            ->first();

        if (! $record) {
            throw new PaymentException('Unknown payment order', 'PAYMENT_ORDER_NOT_FOUND', 404);
        }

        $this->assertAdminOfMandal($user, $record->mandal_id);

        $expectedAmount = $this->registrationAmountPaise();
        if ((int) $record->amount_paise !== $expectedAmount) {
            throw new PaymentException('Payment amount mismatch', 'PAYMENT_AMOUNT_MISMATCH', 400);
        }

        DB::transaction(function () use ($record, $paymentId) {
            $mandal = Mandal::query()->lockForUpdate()->findOrFail($record->mandal_id);

            $record->update([
                'razorpay_payment_id' => $paymentId,
                'status' => RegistrationPaymentStatus::PAID,
                'paid_at' => now(),
            ]);

            if ($mandal->registration_paid_at === null) {
                $mandal->forceFill(['registration_paid_at' => now()])->save();
            }
        });

        $record->refresh();
        $mandal = $record->mandal()->first();

        Log::info('Registration payment verified', [
            'mandal_id' => $record->mandal_id,
            'user_id' => $user->id,
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'amount_paise' => $record->amount_paise,
        ]);

        return [
            'paid' => true,
            'mandalId' => $record->mandal_id,
            'mandalName' => $mandal?->name,
            'paidAt' => $record->paid_at?->toIso8601String(),
        ];
    }

    public function unpaidAdminMandal(User $user): ?Mandal
    {
        $user->loadMissing('mandalMembers.mandal');

        $membership = $user->mandalMembers
            ->first(function (MandalMember $mm) {
                return $mm->is_active
                    && in_array($mm->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)
                    && $mm->mandal
                    && $mm->mandal->registration_paid_at === null;
            });

        return $membership?->mandal;
    }

    public function registrationAmountPaise(): int
    {
        return (int) config('services.razorpay.registration_amount_paise', 10100);
    }

    public static function amountLabel(?int $paise = null): string
    {
        $paise ??= (int) config('services.razorpay.registration_amount_paise', 10100);
        $rupees = $paise / 100;
        $formatted = fmod($rupees, 1.0) === 0.0
            ? number_format($rupees, 0)
            : number_format($rupees, 2);

        return '₹'.$formatted;
    }

    public function currency(): string
    {
        return (string) config('services.razorpay.currency', 'INR');
    }

    protected function resolvePayableMandal(User $user, ?string $mandalId): Mandal
    {
        if ($mandalId) {
            $this->assertAdminOfMandal($user, $mandalId);

            return Mandal::query()->findOrFail($mandalId);
        }

        $mandal = $this->unpaidAdminMandal($user);
        if (! $mandal) {
            $user->loadMissing('mandalMembers.mandal');
            $anyAdmin = $user->mandalMembers->first(function (MandalMember $mm) {
                return $mm->is_active
                    && in_array($mm->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)
                    && $mm->mandal;
            });

            if ($anyAdmin?->mandal) {
                return $anyAdmin->mandal;
            }

            throw new PaymentException('No mandal found to pay for', 'MANDAL_NOT_FOUND', 404);
        }

        return $mandal;
    }

    protected function assertAdminOfMandal(User $user, string $mandalId): void
    {
        $membership = MandalMember::query()
            ->where('mandal_id', $mandalId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            throw new PaymentException('You are not a member of this mandal', 'FORBIDDEN', 403);
        }

        if (! in_array($membership->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)) {
            throw new PaymentException('Only the mandal admin can pay the registration fee', 'FORBIDDEN', 403);
        }
    }

    protected function orderPayload(
        RegistrationPayment $payment,
        Mandal $mandal,
        User $user,
        string $keyId,
        bool $alreadyPaid,
    ): array {
        $contact = $user->phone ? '+91'.$user->phone : null;

        return [
            'alreadyPaid' => $alreadyPaid,
            'keyId' => $keyId,
            'orderId' => $payment->razorpay_order_id,
            'amountPaise' => (int) $payment->amount_paise,
            'currency' => $payment->currency,
            'name' => 'MandalHishob',
            'description' => 'Mandal registration — '.self::amountLabel((int) $payment->amount_paise),
            'mandalId' => $mandal->id,
            'mandalName' => $mandal->name,
            'prefill' => [
                'name' => $user->full_name,
                'contact' => $contact,
                'email' => $user->email,
            ],
            'themeColor' => '#FF7800',
        ];
    }
}
