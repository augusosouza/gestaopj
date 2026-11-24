<?php

namespace Tests\Feature;

use Tests\TestCase;

class CabecalhosSegurancaTest extends TestCase
{
    public function test_login_tem_politica_de_cache_privada_e_csp_em_observacao(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
