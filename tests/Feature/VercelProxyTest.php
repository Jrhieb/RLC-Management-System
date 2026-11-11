<?php

namespace Tests\Feature;

use Tests\TestCase;

class VercelProxyTest extends TestCase
{
    public function test_guest_redirect_uses_https_when_deployed_behind_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'untrusted.example'])
            ->get('/dashboard')
            ->assertRedirect('https://localhost/login');
    }

    public function test_forwarded_scheme_is_ignored_without_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/dashboard')
            ->assertRedirect('http://localhost/login');
    }
}
