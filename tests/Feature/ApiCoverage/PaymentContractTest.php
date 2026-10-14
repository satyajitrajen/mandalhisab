<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Enums\RegistrationPaymentStatus;
use App\Models\Mandal;
use App\Models\MandalMember;
use App\Models\RegistrationPayment;
use App\Models\User;
use App\Services\RazorpayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class PaymentContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_create_order_requires_auth(): void
    {
        $this->postJson('/api/v1/payments/create-order')
            ->assertStatus(401);
    }

    public function test_verify_requires_auth(): void
    {
        $this->postJson('/api/v1/payments/verify', [
            'razorpayOrderId' => 'order_x',
            'razorpayPaymentId' => 'pay_x',
            'razorpaySignature' => 'sig',
        ])->assertStatus(401);
    }

    public function test_register_with_mandal_requires_payment(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Admin User',
            'usernameOrPhone' => '9123456780',
            'email' => 'admin.fee@example.com',
            'password' => 'password123',
            'mandalName' => 'Fee Test Mandal',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.user.registrationPaymentRequired', true)
            ->assertJsonPath('data.user.unpaidMandal.name', 'Fee Test Mandal');

        $this->assertDatabaseHas('mandals', [
            'name' => 'Fee Test Mandal',
            'registration_paid_at' => null,
        ]);
    }

    public function test_collector_cannot_create_order(): void
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

        $this->withHeaders($this->authHeaders($collector))
            ->postJson('/api/v1/payments/create-order', [
                'mandalId' => $ctx['mandal']->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_create_order_ignores_client_amount_and_returns_server_fee(): void
    {
        $ctx = $this->makeUnpaidAdminContext();

        $this->mock(RazorpayGateway::class, function ($mock) {
            $mock->shouldReceive('createOrder')
                ->once()
                ->with(10100, 'INR', \Mockery::type('string'))
                ->andReturn([
                    'id' => 'order_test101',
                    'amount' => 10100,
                    'currency' => 'INR',
                    'status' => 'created',
                ]);
        });

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/create-order', [
                'amount' => 1,
                'mandalId' => $ctx['mandal']->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.alreadyPaid', false)
            ->assertJsonPath('data.orderId', 'order_test101')
            ->assertJsonPath('data.amountPaise', 10100)
            ->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.keyId', 'rzp_test_dummy')
            ->assertJsonMissingPath('data.keySecret');

        $this->assertDatabaseHas('registration_payments', [
            'mandal_id' => $ctx['mandal']->id,
            'razorpay_order_id' => 'order_test101',
            'amount_paise' => 10100,
            'status' => RegistrationPaymentStatus::CREATED->value,
        ]);
    }

    public function test_create_order_reuses_existing_unpaid_order(): void
    {
        $ctx = $this->makeUnpaidAdminContext();

        RegistrationPayment::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $ctx['user']->id,
            'razorpay_order_id' => 'order_existing',
            'amount_paise' => 10100,
            'currency' => 'INR',
            'status' => RegistrationPaymentStatus::CREATED,
        ]);

        $this->mock(RazorpayGateway::class, function ($mock) {
            $mock->shouldReceive('createOrder')->never();
        });

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/create-order')
            ->assertStatus(200)
            ->assertJsonPath('data.orderId', 'order_existing');
    }

    public function test_already_paid_mandal_does_not_create_razorpay_order(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $this->mock(RazorpayGateway::class, function ($mock) {
            $mock->shouldReceive('createOrder')->never();
        });

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/create-order', [
                'mandalId' => $ctx['mandal']->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.alreadyPaid', true)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.renewable', false)
            ->assertJsonPath('data.paidThrough', now()->addYear()->toDateString())
            ->assertJsonPath('data.orderId', null);
    }

    public function test_create_order_allowed_inside_renewal_window(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $ctx['mandal']->forceFill(['registration_paid_through' => now()->addDays(10)])->save();

        $this->mock(RazorpayGateway::class, function ($mock) {
            $mock->shouldReceive('createOrder')->once()->andReturn([
                'id' => 'order_renew', 'amount' => 10100, 'currency' => 'INR', 'status' => 'created',
            ]);
        });

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/create-order', ['mandalId' => $ctx['mandal']->id])
            ->assertStatus(200)
            ->assertJsonPath('data.alreadyPaid', false)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.renewable', true)
            ->assertJsonPath('data.orderId', 'order_renew');
    }

    public function test_early_renewal_extends_from_current_expiry(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $expiry = now()->addDays(10)->startOfDay();
        $ctx['mandal']->forceFill(['registration_paid_through' => $expiry])->save();

        $this->verifyOrder($ctx, 'order_early')->assertOk()
            ->assertJsonPath('data.paidThrough', $expiry->copy()->addYear()->toDateString());

        $payment = RegistrationPayment::where('razorpay_order_id', 'order_early')->firstOrFail();
        $this->assertSame('razorpay', $payment->gateway->value);
        $this->assertSame($expiry->toDateString(), $payment->period_start->toDateString());
        $this->assertSame($expiry->copy()->addYear()->toDateString(), $payment->period_end->toDateString());
    }

    public function test_renewal_after_expiry_extends_from_today(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $ctx['mandal']->forceFill(['registration_paid_through' => now()->subMonths(2)])->save();

        $this->verifyOrder($ctx, 'order_late')->assertOk()
            ->assertJsonPath('data.paidThrough', today()->addYear()->toDateString());
    }

    public function test_repeated_verify_does_not_add_a_second_year(): void
    {
        $ctx = $this->makeUnpaidAdminContext();

        $this->verifyOrder($ctx, 'order_twice')->assertOk();
        $this->verifyOrder($ctx, 'order_twice', create: false)->assertOk()
            ->assertJsonPath('data.paidThrough', today()->addYear()->toDateString());
    }

    public function test_expired_mandal_is_blocked_by_middleware(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $ctx['mandal']->forceFill(['registration_paid_through' => now()->subDay()])->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/festivals')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'PAYMENT_REQUIRED')
            ->assertJsonPath('error.details.expired', true)
            ->assertJsonPath('error.details.paidThrough', now()->subDay()->toDateString());
    }

    public function test_mandal_is_active_through_the_last_paid_day(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);
        $ctx['mandal']->forceFill(['registration_paid_through' => today()])->save();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/festivals')
            ->assertStatus(200);
    }

    public function test_backfill_gives_paid_mandals_one_year_from_latest_payment(): void
    {
        $migration = require database_path('migrations/2026_10_14_000000_yearly_registration_and_apple_iap.php');
        $migration->down();

        $user = User::factory()->create();
        $make = fn (string $name, $paidAt) => tap(Mandal::create([
            'name' => $name, 'address' => 'x', 'city' => 'Pune', 'pincode' => '411001',
            'contact_number' => '9876500009', 'created_by_user_id' => $user->id,
        ]), fn ($m) => \Illuminate\Support\Facades\DB::table('mandals')->where('id', $m->id)->update(['registration_paid_at' => $paidAt]));

        $withPayment = $make('With payment', '2025-01-10 10:00:00');
        $legacy = $make('Legacy', '2025-03-05 10:00:00');
        $unpaid = $make('Unpaid', null);

        \Illuminate\Support\Facades\DB::table('registration_payments')->insert([
            ['id' => 'pay_a', 'mandal_id' => $withPayment->id, 'user_id' => $user->id, 'razorpay_order_id' => 'o_a',
                'amount_paise' => 10100, 'currency' => 'INR', 'status' => 'PAID', 'paid_at' => '2025-06-01 09:00:00'],
            ['id' => 'pay_b', 'mandal_id' => $withPayment->id, 'user_id' => $user->id, 'razorpay_order_id' => 'o_b',
                'amount_paise' => 10100, 'currency' => 'INR', 'status' => 'FAILED', 'paid_at' => '2025-09-01 09:00:00'],
        ]);

        $migration->up();

        $this->assertSame('2026-06-01', Mandal::find($withPayment->id)->registration_paid_through->toDateString());
        $this->assertSame('2026-03-05', Mandal::find($legacy->id)->registration_paid_through->toDateString());
        $this->assertNull(Mandal::find($unpaid->id)->registration_paid_through);
        $this->assertDatabaseHas('registration_payments', ['id' => 'pay_a', 'gateway' => 'razorpay']);
    }

    public function test_verify_marks_mandal_paid_on_valid_signature(): void
    {
        $ctx = $this->makeUnpaidAdminContext();

        RegistrationPayment::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $ctx['user']->id,
            'razorpay_order_id' => 'order_ok',
            'amount_paise' => 10100,
            'currency' => 'INR',
            'status' => RegistrationPaymentStatus::CREATED,
        ]);

        $paymentId = 'pay_ok';
        $signature = hash_hmac('sha256', 'order_ok|' . $paymentId, 'test_secret');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/verify', [
                'razorpayOrderId' => 'order_ok',
                'razorpayPaymentId' => $paymentId,
                'razorpaySignature' => $signature,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('data.mandalId', $ctx['mandal']->id);

        $this->assertNotNull(Mandal::find($ctx['mandal']->id)->registration_paid_at);
        $this->assertSame(today()->addYear()->toDateString(), Mandal::find($ctx['mandal']->id)->registration_paid_through->toDateString());
        $this->assertDatabaseHas('registration_payments', [
            'razorpay_order_id' => 'order_ok',
            'razorpay_payment_id' => $paymentId,
            'status' => RegistrationPaymentStatus::PAID->value,
        ]);
    }

    public function test_verify_rejects_bad_signature_and_does_not_mark_paid(): void
    {
        $ctx = $this->makeUnpaidAdminContext();

        RegistrationPayment::create([
            'mandal_id' => $ctx['mandal']->id,
            'user_id' => $ctx['user']->id,
            'razorpay_order_id' => 'order_bad',
            'amount_paise' => 10100,
            'currency' => 'INR',
            'status' => RegistrationPaymentStatus::CREATED,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/verify', [
                'razorpayOrderId' => 'order_bad',
                'razorpayPaymentId' => 'pay_bad',
                'razorpaySignature' => 'definitely-not-the-hmac',
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'PAYMENT_VERIFICATION_FAILED');

        $this->assertNull(Mandal::find($ctx['mandal']->id)->registration_paid_at);
        $this->assertNull(Mandal::find($ctx['mandal']->id)->registration_paid_through);
        $this->assertDatabaseHas('registration_payments', [
            'razorpay_order_id' => 'order_bad',
            'status' => RegistrationPaymentStatus::FAILED->value,
            'razorpay_payment_id' => null,
        ]);
    }

    public function test_unpaid_mandal_cannot_list_festivals(): void
    {
        $ctx = $this->makeUnpaidAdminContext();

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/festivals')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'PAYMENT_REQUIRED');
    }

    public function test_gateway_failure_returns_500(): void
    {
        $ctx = $this->makeUnpaidAdminContext();

        $this->mock(RazorpayGateway::class, function ($mock) {
            $mock->shouldReceive('createOrder')
                ->once()
                ->andThrow(new \RuntimeException('Razorpay down'));
        });

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/create-order')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'PAYMENT_GATEWAY_ERROR');
    }

    protected function verifyOrder(array $ctx, string $orderId, bool $create = true)
    {
        if ($create) {
            RegistrationPayment::create([
                'mandal_id' => $ctx['mandal']->id,
                'user_id' => $ctx['user']->id,
                'razorpay_order_id' => $orderId,
                'amount_paise' => 10100,
                'currency' => 'INR',
                'status' => RegistrationPaymentStatus::CREATED,
            ]);
        }

        $paymentId = 'pay_' . $orderId;

        return $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/payments/verify', [
                'razorpayOrderId' => $orderId,
                'razorpayPaymentId' => $paymentId,
                'razorpaySignature' => hash_hmac('sha256', $orderId . '|' . $paymentId, 'test_secret'),
            ]);
    }

    /**
     * @return array{user: User, mandal: Mandal}
     */
    protected function makeUnpaidAdminContext(): array
    {
        $user = User::factory()->create();
        $mandal = Mandal::create([
            'name' => 'Unpaid Mandal',
            'address' => '1 Test Lane',
            'city' => 'Pune',
            'pincode' => '411001',
            'contact_number' => '9876500001',
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
