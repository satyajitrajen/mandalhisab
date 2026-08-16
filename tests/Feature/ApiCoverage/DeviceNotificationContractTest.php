<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Enums\NotificationType;
use App\Models\DeviceToken;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class DeviceNotificationContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_device_token_register_upserts(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/devices/token', [
                'deviceToken' => 'fcm-token-123',
                'platform' => 'android',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $ctx['user']->id,
            'token' => 'fcm-token-123',
            'platform' => 'android',
        ]);

        // Re-register same token on same platform -> upsert, not duplicate.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/devices/token', [
                'deviceToken' => 'fcm-token-456',
                'platform' => 'android',
            ])
            ->assertStatus(200);
        $this->assertSame(1, DeviceToken::where('user_id', $ctx['user']->id)
            ->where('token', 'fcm-token-456')
            ->count());
        $this->assertSame(0, DeviceToken::where('user_id', $ctx['user']->id)
            ->where('token', 'fcm-token-123')
            ->count());
    }

    public function test_notifications_index_filters_unread(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        Notification::create([
            'user_id' => $ctx['user']->id,
            'mandal_id' => $ctx['mandal']->id,
            'festival_id' => $ctx['festival']->id,
            'title' => 'Handover initiated',
            'body' => 'Collector Sagar submitted cash',
            'type' => NotificationType::HANDOVER_INITIATED,
            'is_read' => false,
        ]);
        Notification::create([
            'user_id' => $ctx['user']->id,
            'mandal_id' => $ctx['mandal']->id,
            'festival_id' => $ctx['festival']->id,
            'title' => 'Old news',
            'body' => 'Read already',
            'type' => NotificationType::GENERAL,
            'is_read' => true,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/notifications?unreadOnly=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/notifications')
            ->assertJsonCount(2, 'data');
    }

    public function test_mark_read_scopes_to_own_user(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);
        $other = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $mine = Notification::create([
            'user_id' => $ctx['user']->id,
            'title' => 'Mine',
            'body' => 'my body',
            'type' => NotificationType::GENERAL,
            'is_read' => false,
        ]);
        $theirs = Notification::create([
            'user_id' => $other['user']->id,
            'title' => 'Theirs',
            'body' => 'their body',
            'type' => NotificationType::GENERAL,
            'is_read' => false,
        ]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/notifications/' . $mine->id . '/read')
            ->assertStatus(200);

        $this->assertDatabaseHas('notifications', ['id' => $mine->id, 'is_read' => true]);
        $this->assertDatabaseHas('notifications', ['id' => $theirs->id, 'is_read' => false]);

        // Cannot mark someone else's notification.
        $this->withHeaders($this->authHeaders($ctx['user']))
            ->patchJson('/api/v1/notifications/' . $theirs->id . '/read')
            ->assertStatus(404);
    }

    public function test_mark_all_read(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        Notification::create(['user_id' => $ctx['user']->id, 'title' => 'A', 'body' => 'a', 'type' => NotificationType::GENERAL, 'is_read' => false]);
        Notification::create(['user_id' => $ctx['user']->id, 'title' => 'B', 'body' => 'b', 'type' => NotificationType::GENERAL, 'is_read' => false]);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->postJson('/api/v1/notifications/read-all')
            ->assertStatus(200);

        $this->assertSame(0, Notification::where('user_id', $ctx['user']->id)
            ->where('is_read', false)
            ->count());
    }
}