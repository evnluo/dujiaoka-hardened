<?php

namespace Tests\Feature;

use Tests\TestCase;

class McpTransportTest extends TestCase
{
    public function test_every_http_method_requires_bearer_auth_even_with_admin_session(): void
    {
        foreach (['GET', 'POST', 'DELETE', 'PUT', 'PATCH', 'OPTIONS', 'HEAD'] as $method) {
            $this->json($method, '/api/admin/mcp')->assertUnauthorized();
        }
    }
}
