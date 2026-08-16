<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_application_is_up(): void
    {
        $response = $this->get('/up');
        $response->assertStatus(200);
    }

    public function test_app_config_is_public(): void
    {
        $response = $this->getJson('/api/v1/config/app');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'statusCode',
                'data' => [
                    'minSupportedVersion',
                    'latestVersion',
                    'forceUpdate',
                    'maintenanceMode',
                    'supportPhone',
                    'supportEmail',
                    'features',
                ],
            ]);
    }
}
