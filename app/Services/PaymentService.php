<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Enums\PaymentGateway;
use App\Enums\RegistrationPaymentStatus;
use App\Exceptions\PaymentException;
use App\Models\Mandal;
use App\Models\MandalMember;
use App\Models\RegistrationPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentService
{
    public function __construct(
        protected RazorpayGateway $gateway,
        protected AppleGateway $apple,
    ) {}

    /**
     * Create (or reuse) a Razorpay order for the admin's mandal when it is
     * unpaid, expired, or inside the renewal window.
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

        if (! $mandal->needsRegistrationPayment()) {
            return [
                'alreadyPaid' => true,
                ...$this->statusPayload($mandal),
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

            return $this->orderPayload($existing, $mandal, $user, $keyId);
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

        return $this->orderPayload($payment, $mandal, $user, $keyId);
    }

    /**
     * Verify checkout signature. Extends the mandal by one year only on HMAC match.
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
            $record = RegistrationPayment::query()->lockForUpdate()->findOrFail($record->id);

            // A repeated verify of the same order must not add another year.
            if ($record->status === RegistrationPaymentStatus::PAID) {
                return;
            }

            $current = $mandal->registration_paid_through;
            $start = $current && $current->gte(today()) ? $current->copy() : today();
            $end = $start->copy()->addYear();

            $record->update([
                'razorpay_payment_id' => $paymentId,
                'status' => RegistrationPaymentStatus::PAID,
                'paid_at' => now(),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ]);

            $mandal->forceFill([
                'registration_paid_at' => now(),
                'registration_paid_through' => $end->toDateString(),
            ])->save();
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
            'paidThrough' => $mandal?->registration_paid_through?->toDateString(),
        ];
    }

    /**
     * Verify an App Store subscription transaction and extend the mandal to
     * the subscription's expiry. Nothing is written unless Apple confirms it.
     */
    public function verifyAppleRegistrationPayment(User $user, array $payload): array
    {
        if (! $this->apple->isConfigured()) {
            throw new PaymentException('In-app purchase is not available', 'PAYMENT_UNAVAILABLE', 503);
        }

        $transactionId = (string) $payload['transactionId'];

        $existing = RegistrationPayment::query()
            ->where('apple_transaction_id', $transactionId)
            ->first();

        if ($existing) {
            $this->assertAdminOfMandal($user, $existing->mandal_id);
            $mandal = Mandal::query()->findOrFail($existing->mandal_id);

            if ($existing->status === RegistrationPaymentStatus::PAID && $mandal->isRegistrationActive()) {
                return $this->applePaidPayload($mandal);
            }
        } else {
            $mandal = $this->resolvePayableMandal($user, $payload['mandalId'] ?? null);
        }

        $transaction = $this->fetchAppleTransaction($transactionId, $user);
        $this->assertValidAppleTransaction($transaction, $user);

        if ($transaction['expiresDate']->isPast()) {
            throw new PaymentException('This subscription has expired', 'PAYMENT_VERIFICATION_FAILED', 400);
        }

        // The same subscription cannot pay for two different mandals.
        $owner = RegistrationPayment::query()
            ->where('gateway', PaymentGateway::APPLE)
            ->where('apple_original_transaction_id', $transaction['originalTransactionId'])
            ->value('mandal_id');

        if ($owner !== null && $owner !== $mandal->id) {
            throw new PaymentException('This subscription belongs to another mandal', 'PAYMENT_VERIFICATION_FAILED', 400);
        }

        $mandal = $this->recordAppleTransaction($mandal->id, $user->id, $transaction);

        Log::info('Apple registration subscription verified', [
            'mandal_id' => $mandal->id,
            'user_id' => $user->id,
            'apple_transaction_id' => $transaction['transactionId'],
            'paid_through' => $mandal->registration_paid_through?->toDateString(),
        ]);

        return $this->applePaidPayload($mandal);
    }

    /**
     * Apply an App Store Server Notification. State always comes from Apple's
     * API, never from the notification body, so a forged webhook changes nothing.
     */
    public function applyAppleNotification(string $transactionId): void
    {
        if (! $this->apple->isConfigured()) {
            return;
        }

        $transaction = $this->fetchAppleTransaction($transactionId, null);
        $this->assertValidAppleTransaction($transaction, null);

        $mandalId = RegistrationPayment::query()
            ->where('gateway', PaymentGateway::APPLE)
            ->where(function ($q) use ($transaction) {
                $q->where('apple_transaction_id', $transaction['transactionId'])
                    ->orWhere('apple_original_transaction_id', $transaction['originalTransactionId']);
            })
            ->value('mandal_id');

        if ($mandalId === null) {
            // Not verified by the app yet; the app's verify call will record it.
            return;
        }

        if ($transaction['revocationDate'] !== null) {
            // Refunds are recorded only; access already granted is kept.
            RegistrationPayment::query()
                ->where('apple_transaction_id', $transaction['transactionId'])
                ->update(['status' => RegistrationPaymentStatus::REFUNDED]);

            Log::info('Apple registration subscription refunded', [
                'mandal_id' => $mandalId,
                'apple_transaction_id' => $transaction['transactionId'],
            ]);

            return;
        }

        $payment = RegistrationPayment::query()
            ->where('apple_transaction_id', $transaction['transactionId'])
            ->first();
        $userId = $payment?->user_id ?? RegistrationPayment::query()
            ->where('apple_original_transaction_id', $transaction['originalTransactionId'])
            ->latest('created_at')
            ->value('user_id');

        $this->recordAppleTransaction($mandalId, $userId, $transaction);
    }

    public function unpaidAdminMandal(User $user): ?Mandal
    {
        $user->loadMissing('mandalMembers.mandal');

        $membership = $user->mandalMembers
            ->first(function (MandalMember $mm) {
                return $mm->is_active
                    && in_array($mm->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)
                    && $mm->mandal
                    && $mm->mandal->needsRegistrationPayment();
            });

        return $membership?->mandal;
    }

    /**
     * Registration status fields shared by order and status responses.
     */
    public function statusPayload(Mandal $mandal): array
    {
        $active = $mandal->isRegistrationActive();

        return [
            'active' => $active,
            'paidThrough' => $mandal->registration_paid_through?->toDateString(),
            'renewable' => $active && $mandal->needsRegistrationPayment(),
        ];
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

    protected function fetchAppleTransaction(string $transactionId, ?User $user): array
    {
        try {
            return $this->apple->fetchTransaction($transactionId);
        } catch (Throwable $e) {
            Log::error('Apple transaction lookup failed', [
                'user_id' => $user?->id,
                'apple_transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            throw new PaymentException('Unable to verify purchase. Please try again.', 'PAYMENT_GATEWAY_ERROR', 500);
        }
    }

    protected function assertValidAppleTransaction(array $transaction, ?User $user): void
    {
        $reason = match (true) {
            $transaction['transactionId'] === '' => 'missing transaction id',
            $transaction['productId'] !== (string) config('services.apple_iap.product_id') => 'product mismatch',
            $transaction['bundleId'] !== (string) config('services.apple_iap.bundle_id') => 'bundle mismatch',
            $transaction['environment'] !== $this->apple->environment() => 'environment mismatch',
            $transaction['expiresDate'] === null => 'missing expiry',
            $user !== null && $transaction['revocationDate'] !== null => 'revoked',
            default => null,
        };

        if ($reason !== null) {
            Log::warning('Apple transaction rejected', [
                'user_id' => $user?->id,
                'apple_transaction_id' => $transaction['transactionId'],
                'reason' => $reason,
            ]);

            throw new PaymentException('Purchase verification failed', 'PAYMENT_VERIFICATION_FAILED', 400);
        }
    }

    /**
     * Upsert the PAID row for an Apple transaction and extend the mandal to
     * its expiry. Never shortens paid-through (a Razorpay year may be later).
     */
    protected function recordAppleTransaction(string $mandalId, ?string $userId, array $transaction): Mandal
    {
        return DB::transaction(function () use ($mandalId, $userId, $transaction) {
            $mandal = Mandal::query()->lockForUpdate()->findOrFail($mandalId);

            /** @var Carbon $expires */
            $expires = $transaction['expiresDate'];
            $paidThrough = $expires->copy()->timezone(config('app.timezone'))->startOfDay();
            $start = ($transaction['purchaseDate'] ?? now())->copy()->timezone(config('app.timezone'))->startOfDay();

            $payment = RegistrationPayment::query()
                ->lockForUpdate()
                ->where('apple_transaction_id', $transaction['transactionId'])
                ->first();

            $attributes = [
                'gateway' => PaymentGateway::APPLE,
                'apple_original_transaction_id' => $transaction['originalTransactionId'],
                'amount_paise' => (int) round(($transaction['price'] ?? 0) / 10),
                'currency' => $transaction['currency'] ?? $this->currency(),
                'status' => RegistrationPaymentStatus::PAID,
                'period_start' => $start->toDateString(),
                'period_end' => $paidThrough->toDateString(),
            ];

            if ($payment) {
                if ($payment->status !== RegistrationPaymentStatus::REFUNDED) {
                    $payment->update($attributes);
                }
            } else {
                RegistrationPayment::create([
                    ...$attributes,
                    'mandal_id' => $mandal->id,
                    'user_id' => $userId,
                    'apple_transaction_id' => $transaction['transactionId'],
                    'paid_at' => now(),
                ]);
            }

            $current = $mandal->registration_paid_through;
            $mandal->forceFill([
                'registration_paid_at' => $payment ? $mandal->registration_paid_at : now(),
                'registration_paid_through' => ($current && $current->gt($paidThrough) ? $current : $paidThrough)->toDateString(),
            ])->save();

            return $mandal;
        });
    }

    protected function applePaidPayload(Mandal $mandal): array
    {
        return [
            'paid' => true,
            'mandalId' => $mandal->id,
            'mandalName' => $mandal->name,
            'paidThrough' => $mandal->registration_paid_through?->toDateString(),
        ];
    }

    protected function orderPayload(
        RegistrationPayment $payment,
        Mandal $mandal,
        User $user,
        string $keyId,
    ): array {
        $contact = $user->phone ? '+91'.$user->phone : null;

        return [
            'alreadyPaid' => false,
            ...$this->statusPayload($mandal),
            'keyId' => $keyId,
            'orderId' => $payment->razorpay_order_id,
            'amountPaise' => (int) $payment->amount_paise,
            'currency' => $payment->currency,
            'name' => 'MandalHishob',
            'description' => 'Mandal registration (1 year) — '.self::amountLabel((int) $payment->amount_paise),
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
