<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalAccessTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_local_health_check_is_available(): void
    {
        $this->get('/up')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_remote_connections_cannot_access_the_app(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->get('/up')->assertForbidden();
    }

    public function test_untrusted_host_is_rejected_even_on_loopback(): void
    {
        $this->get('http://untrusted.example/up')->assertForbidden();
    }

    public function test_cross_origin_writes_are_rejected(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example')
            ->post('/tasks', [])->assertForbidden();
    }
}
