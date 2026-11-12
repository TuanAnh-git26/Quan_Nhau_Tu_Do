<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok_when_database_is_available(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertOk()->assertJsonPath('status', 'ok');
    }
}
