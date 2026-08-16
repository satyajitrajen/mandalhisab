<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Models\Mandal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class FestivalTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_guest_cannot_access_festival_apis(): void
    {
        $this->getJson('/api/v1/mandals/some-id/festivals')
            ->assertStatus(401);
    }

    public function test_member_can_access_festival_apis(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/mandals/' . $ctx['mandal']->id . '/festivals')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_admin_can_create_festival(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::ADMIN->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/festivals', [
                'name' => 'Navratri Utsav',
                'year' => '2025',
                'startDate' => '2025-10-01',
                'endDate' => '2025-10-10',
                'budgetGoal' => 500000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Navratri Utsav')
            ->assertJsonPath('success', true);
    }

    public function test_collector_cannot_create_festival(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/mandals/' . $ctx['mandal']->id . '/festivals', [
                'name' => 'Navratri Utsav',
                'year' => '2025',
                'startDate' => '2025-10-01',
                'endDate' => '2025-10-10',
            ])
            ->assertStatus(403);
    }

    public function test_non_member_cannot_access_festival_apis(): void
    {
        $user = User::factory()->create();
        $mandal = Mandal::create([
            'name' => 'Other Mandal',
            'address' => '456 Other St',
            'city' => 'Mumbai',
            'pincode' => '400001',
            'contact_number' => '9876543211',
            'created_by_user_id' => $user->id,
        ]);

        $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/v1/mandals/' . $mandal->id . '/festivals')
            ->assertStatus(403);
    }
}
