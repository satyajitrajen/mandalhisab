<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Enums\PaymentGateway;
use App\Enums\RegistrationPaymentStatus;
use App\Models\Mandal;
use App\Models\MandalMember;
use App\Models\RegistrationPayment;
use App\Models\User;
use App\Services\AppleGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class ApplePaymentContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_verify_requires_auth(): void
    {
        $this->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])
            ->assertStatus(401);
    }

    public function test_valid_transaction_sets_paid_through_to_expiry(): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $expires = now()->addYear()->startOfDay()->addHours(10);
        $this->fakeApple(['t1' => $this->transaction('t1', expires: $expires)]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])
            ->assertStatus(200)
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('data.mandalId', $ctx['mandal']->id)
            ->assertJsonPath('data.paidThrough', $expires->toDateString());

        $mandal = Mandal::find($ctx['mandal']->id);
        $this->assertSame($expires->toDateString(), $mandal->registration_paid_through->toDateString());
        $this->assertNotNull($mandal->registration_paid_at);
        $this->assertDatabaseHas('registration_payments', [
            'mandal_id' => $ctx['mandal']->id,
            'gateway' => PaymentGateway::APPLE->value,
            'apple_transaction_id' => 't1',
            'razorpay_order_id' => null,
            'amount_paise' => 10100,
            'status' => RegistrationPaymentStatus::PAID->value,
        ]);
    }

    public function test_reverify_is_idempotent_and_skips_apple(): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $this->fakeApple(['t1' => $this->transaction('t1')]);

        $headers = $this->authHeaders($ctx['user']);
        $this->withHeaders($headers)->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])->assertOk();

        $this->partialMock(AppleGateway::class, fn ($m) => $m->shouldReceive('fetchTransaction')->never());

        $this->withHeaders($headers)
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])
            ->assertOk()
            ->assertJsonPath('data.paid', true);

        $this->assertSame(1, RegistrationPayment::where('apple_transaction_id', 't1')->count());
    }

    public function test_expired_transaction_is_rejected(): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $this->fakeApple(['t1' => $this->transaction('t1', expires: now()->subDay())]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'PAYMENT_VERIFICATION_FAILED');

        $this->assertNull(Mandal::find($ctx['mandal']->id)->registration_paid_through);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    /**
     * @dataProvider invalidTransactions
     */
    public function test_invalid_transaction_is_rejected_and_nothing_written(array $overrides): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $this->fakeApple(['t1' => array_merge($this->transaction('t1'), $overrides)]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'PAYMENT_VERIFICATION_FAILED');

        $this->assertNull(Mandal::find($ctx['mandal']->id)->registration_paid_through);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    public static function invalidTransactions(): array
    {
        return [
            'wrong product' => [['productId' => 'some.other.product']],
            'wrong bundle' => [['bundleId' => 'com.example.other']],
            'wrong environment' => [['environment' => 'Production']],
            'revoked' => [['revocationDate' => Carbon::now()->subHour()]],
        ];
    }

    public function test_subscription_cannot_pay_for_a_second_mandal(): void
    {
        $first = $this->makeUnpaidAdminContext();
        $second = $this->makeUnpaidAdminContext();
        $this->fakeApple([
            't1' => $this->transaction('t1', original: 'orig1'),
            't2' => $this->transaction('t2', original: 'orig1'),
        ]);

        $this->withHeaders($this->authHeaders($first['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])->assertOk();

        $this->withHeaders($this->authHeaders($second['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't2'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'PAYMENT_VERIFICATION_FAILED');

        $this->assertNull(Mandal::find($second['mandal']->id)->registration_paid_through);
    }

    public function test_gateway_error_returns_500(): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $this->partialMock(AppleGateway::class, fn ($m) => $m->shouldReceive('fetchTransaction')
            ->andThrow(new \RuntimeException('Apple down')));

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'PAYMENT_GATEWAY_ERROR');
    }

    public function test_disabled_returns_503(): void
    {
        config(['services.apple_iap.enabled' => false]);
        $ctx = $this->makeUnpaidAdminContext();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PAYMENT_UNAVAILABLE');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $collector = User::factory()->create();
        MandalMember::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $collector->id,
            'role' => MemberRole::COLLECTOR,
            'is_default' => true,
            'is_active' => true,
            'joined_at' => now(),
        ]);
        $this->fakeApple(['t1' => $this->transaction('t1')]);

        $this->withHeaders($this->authHeaders($collector))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1', 'mandalId' => $ctx['mandal']->id])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_webhook_renewal_extends_paid_through(): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $renewedExpiry = now()->addYears(2);
        $this->fakeApple([
            't1' => $this->transaction('t1', original: 'orig1'),
            't2' => $this->transaction('t2', original: 'orig1', expires: $renewedExpiry),
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])->assertOk();

        $this->postJson('/api/v1/payments/apple/webhook', $this->notification('DID_RENEW', 't2'))
            ->assertOk();

        $this->assertSame(
            $renewedExpiry->toDateString(),
            Mandal::find($ctx['mandal']->id)->registration_paid_through->toDateString()
        );
        $this->assertDatabaseHas('registration_payments', [
            'apple_transaction_id' => 't2',
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $ctx['user']->id,
            'status' => RegistrationPaymentStatus::PAID->value,
        ]);
    }

    public function test_webhook_expired_lets_access_lapse(): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $expiry = now()->addDays(3);
        $this->fakeApple(['t1' => $this->transaction('t1', expires: $expiry)]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])->assertOk();

        $this->postJson('/api/v1/payments/apple/webhook', $this->notification('EXPIRED', 't1'))->assertOk();

        $this->assertSame($expiry->toDateString(), Mandal::find($ctx['mandal']->id)->registration_paid_through->toDateString());

        $this->travel(4)->days();
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/'.$ctx['mandal']->id.'/festivals')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'PAYMENT_REQUIRED');
    }

    public function test_webhook_refund_marks_refunded_but_keeps_access(): void
    {
        $ctx = $this->makeUnpaidAdminContext();
        $this->fakeApple(['t1' => $this->transaction('t1')]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/apple/verify', ['transactionId' => 't1'])->assertOk();
        $paidThrough = Mandal::find($ctx['mandal']->id)->registration_paid_through->toDateString();

        $this->fakeApple(['t1' => $this->transaction('t1', revoked: now())]);
        $this->postJson('/api/v1/payments/apple/webhook', $this->notification('REFUND', 't1'))->assertOk();

        $this->assertDatabaseHas('registration_payments', [
            'apple_transaction_id' => 't1',
            'status' => RegistrationPaymentStatus::REFUNDED->value,
        ]);
        $this->assertSame($paidThrough, Mandal::find($ctx['mandal']->id)->registration_paid_through->toDateString());
    }

    public function test_junk_webhook_is_a_200_noop(): void
    {
        $this->partialMock(AppleGateway::class, fn ($m) => $m->shouldReceive('fetchTransaction')->never());

        $this->postJson('/api/v1/payments/apple/webhook', ['signedPayload' => 'not-a-jws'])->assertOk();
        $this->postJson('/api/v1/payments/apple/webhook', [])->assertOk();
    }

    public function test_webhook_for_unknown_transaction_writes_nothing(): void
    {
        $this->fakeApple(['t9' => $this->transaction('t9')]);

        $this->postJson('/api/v1/payments/apple/webhook', $this->notification('SUBSCRIBED', 't9'))->assertOk();

        $this->assertDatabaseCount('registration_payments', 0);
    }

    public function test_webhook_returns_503_when_apple_is_unreachable(): void
    {
        $this->partialMock(AppleGateway::class, fn ($m) => $m->shouldReceive('fetchTransaction')
            ->andThrow(new \RuntimeException('Apple down')));

        $this->postJson('/api/v1/payments/apple/webhook', $this->notification('DID_RENEW', 't1'))
            ->assertStatus(503);
    }

    /**
     * @param  array<string, array>  $transactions
     */
    protected function fakeApple(array $transactions): void
    {
        $this->partialMock(AppleGateway::class, function ($mock) use ($transactions) {
            $mock->shouldReceive('fetchTransaction')
                ->andReturnUsing(function (string $id) use ($transactions) {
                    if (! isset($transactions[$id])) {
                        throw new \RuntimeException('Unknown transaction');
                    }

                    return $transactions[$id];
                });
        });
    }

    protected function transaction(
        string $id,
        ?string $original = null,
        ?Carbon $expires = null,
        ?Carbon $revoked = null,
    ): array {
        return [
            'transactionId' => $id,
            'originalTransactionId' => $original ?? $id,
            'productId' => 'mandal.registration.yearly',
            'bundleId' => 'in.mandalhisab.app',
            'environment' => 'Sandbox',
            'purchaseDate' => now(),
            'expiresDate' => $expires ?? now()->addYear(),
            'revocationDate' => $revoked,
            'price' => 101000,
            'currency' => 'INR',
        ];
    }

    protected function notification(string $type, string $transactionId): array
    {
        $jws = fn (array $payload) => implode('.', [
            rtrim(strtr(base64_encode('{"alg":"ES256"}'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '='),
            'sig',
        ]);

        return ['signedPayload' => $jws([
            'notificationType' => $type,
            'data' => ['signedTransactionInfo' => $jws(['transactionId' => $transactionId])],
        ])];
    }

    /**
     * @return array{user: User, mandal: Mandal}
     */
    protected function makeUnpaidAdminContext(): array
    {
        $user = User::factory()->create();
        $mandal = Mandal::create([
            'name' => 'iOS Mandal',
            'address' => '1 Test Lane',
            'city' => 'Pune',
            'pincode' => '411001',
            'contact_number' => '9876500002',
            'created_by_user_id' => $user->id,
        ]);

        MandalMember::create([
            'mandal_id' => $mandal->id,
            'user_id' => $user->id,
            'role' => MemberRole::ADMIN,
            'is_default' => true,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        return ['user' => $user, 'mandal' => $mandal];
    }
}
