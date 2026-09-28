<?php

namespace App\Services\Platform\Integrations;

/**
 * A real connectivity test for one `credential`-kind integration provider.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * "REAL" IS THE WHOLE POINT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * K12's two fake providers save to an in-memory array and answer every "Test
 * Connection" with a hardcoded success — no socket opened, ever. This interface
 * exists so that cannot happen here: every implementation makes an actual network
 * call — a real SMTP handshake, a real signed HTTP request — and reports what
 * actually happened, including the real failure message when it fails.
 *
 * `test()` never throws. A network failure, a DNS failure, a timeout — all of it is
 * caught and returned as `['ok' => false, 'message' => ...]`, because the caller
 * (`IntegrationController::test()`) is answering "did the real thing work", not
 * propagating an exception from a routine connectivity check.
 */
interface IntegrationTester
{
    /**
     * @param  array<string, mixed>  $config  This provider's saved fields, decrypted.
     * @return array{ok: bool, message: string}
     */
    public function test(array $config): array;
}
