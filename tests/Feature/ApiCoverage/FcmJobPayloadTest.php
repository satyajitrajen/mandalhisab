<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Jobs\SendFcmNotification;
use App\Models\Notification;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class FcmJobPayloadTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_payload_includes_festival_and_mandal_ids(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $notification = Notification::create([
            'user_id' => $ctx['user']->id,
            'mandal_id' => (string) $ctx['mandal']->id,
            'festival_id' => (string) $ctx['festival']->id,
            'title' => 'New Vargani Received',
            'body' => 'Donor paid Rs 500.',
            'type' => 'VARGANI_CREATED',
            'reference_id' => 'ref-1',
            'is_read' => false,
        ]);

        $this->mock(FcmService::class, function (MockInterface $fcm) use ($ctx, $notification) {
            $fcm->shouldReceive('sendToUser')
                ->once()
                ->withArgs(function (
                    string $userId,
                    string $title,
                    string $body,
                    array $data
                ) use ($ctx, $notification) {
                    $this->assertSame($ctx['user']->id, $userId);
                    $this->assertSame('VARGANI_CREATED', $data['type']);
                    $this->assertSame('ref-1', $data['referenceId']);
                    $this->assertSame((string) $notification->id, $data['notificationId']);
                    $this->assertSame((string) $ctx['festival']->id, $data['festivalId']);
                    $this->assertSame((string) $ctx['mandal']->id, $data['mandalId']);
                    return true;
                });
        });

        (new SendFcmNotification($notification))->handle(app(FcmService::class));
    }

    public function test_payload_omits_festival_and_mandal_when_null(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::TREASURER->value);

        $notification = Notification::create([
            'user_id' => $ctx['user']->id,
            'mandal_id' => null,
            'festival_id' => null,
            'title' => 'General notice',
            'body' => 'Body',
            'type' => 'GENERAL',
            'reference_id' => null,
            'is_read' => false,
        ]);

        $this->mock(FcmService::class, function (MockInterface $fcm) {
            $fcm->shouldReceive('sendToUser')
                ->once()
                ->withArgs(function (string $userId, string $title, string $body, array $data) {
                    $this->assertArrayNotHasKey('festivalId', $data);
                    $this->assertArrayNotHasKey('mandalId', $data);
                    return true;
                });
        });

        (new SendFcmNotification($notification))->handle(app(FcmService::class));
    }
}
