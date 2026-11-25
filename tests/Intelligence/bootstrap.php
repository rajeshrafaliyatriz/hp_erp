<?php

/*
|--------------------------------------------------------------------------
| ISOLATED bootstrap for the G2G Intelligence tests
|--------------------------------------------------------------------------
|
| Purpose: make it impossible for an Intelligence test to reach a real database, LOCAL or LIVE.
|
|  - Every database-related variable is FORCED here, before the framework starts, so nothing in the process environment (or anywhere else) can steer a
|    connection. The default connection is SQLite in memory. Every other connection the application defines points at a closed local port, so an accidental
|    use of one fails at once instead of reaching a server.
|  - IntelligenceTestCase additionally refuses to boot the application with an env file (see its createApplication): no `.env` is read by these tests, and
|    it aborts if the resolved default connection is not in-memory SQLite.
|  - No secret appears in this file: APP_KEY is a throw-away value that encrypts nothing of value.
|
| Run:  php -d memory_limit=1G vendor/bin/phpunit --no-configuration --bootstrap tests/Intelligence/bootstrap.php tests/Unit/Intelligence tests/Feature/Intelligence
*/

$forced = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:' . base64_encode(str_repeat('i', 32)),
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DATABASE_URL' => '',
    // Any other connection (mysql, live, db3, mariadb ...) points at a closed local port: a refused connection, never a server.
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '1',
    'DB_USERNAME' => 'isolated',
    'DB_PASSWORD' => '',
    'DB3_HOST' => '127.0.0.1',
    'DB3_PORT' => '1',
    'DB3_DATABASE' => 'isolated',
    'DB3_USERNAME' => 'isolated',
    'DB3_PASSWORD' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'AI_PROVIDER' => 'gemini',
];

foreach ($forced as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__ . '/../../vendor/autoload.php';
