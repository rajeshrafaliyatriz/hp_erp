<?php

namespace App\Http\Middleware;

/**
 * How Intelligence turns a bearer token into a token record. An interface so a test can supply a fake record without a token table.
 *
 * The record must expose `expires_at` (Carbon|null) and `tokenable` (the user object, or null).
 */
interface IntelligenceTokenAuthenticator
{
    public function find(string $plainToken): ?object;
}
