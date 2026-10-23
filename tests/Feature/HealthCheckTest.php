<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_ok_and_leaks_no_configuration(): void
    {
        $response = $this->get('/health');

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'service' => 'easy-refer-v2'])
            ->assertJsonCount(2);

        $this->assertStringNotContainsString('localhost', $response->getContent());
    }
}
