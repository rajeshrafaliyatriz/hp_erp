<?php

namespace App\Domain\Signals\Run;

/**
 * Fail-closed preflight for a verification run. Pure: it reads the values it is given (never an environment variable itself) and either returns
 * or throws, so it can be called before any database connection is opened and tested without one.
 *
 * Accepted: APP_ENV exactly "verify", database host exactly "127.0.0.1", database name exactly "g2g_signals_verify". Nothing equivalent is
 * accepted ("localhost", sockets, aliases, case or whitespace variants), and a missing or ambiguous value aborts.
 */
final class VerifyEnvironmentGuard
{
    public const ENV = 'verify';
    public const HOST = '127.0.0.1';
    public const DATABASE = 'g2g_signals_verify';

    /**
     * Reads the EFFECTIVE values from the framework's already-loaded configuration (no connection is opened here). The default connection must be
     * the plain `mysql` one: any other default (a DB2_* connection, a fallback alias, sqlite) is not the verification database. A url, or separate
     * read/write hosts, make the effective host ambiguous and are reported as overrides.
     *
     * @param  array<string, mixed>  $connections  config('database.connections')
     * @param  array<string, mixed>  $env  the environment variables to inspect for overrides (e.g. $_ENV + getenv())
     * @return array<string, mixed>
     */
    public static function effective(string $appEnv, string $defaultConnection, array $connections, array $env = [], array $extra = []): array
    {
        $conn = (array) ($connections[$defaultConnection] ?? []);
        $overrides = [];
        if ($defaultConnection !== 'mysql') {
            $overrides[] = "default connection '{$defaultConnection}' (must be 'mysql')";
        }
        if (! empty($conn['url'])) {
            $overrides[] = 'connection url';
        }
        foreach (['read', 'write', 'sticky'] as $k) {
            if (isset($conn[$k])) {
                $overrides[] = "connection {$k} settings";
            }
        }
        foreach (['DB_URL', 'DATABASE_URL'] as $k) {
            if (! empty($env[$k])) {
                $overrides[] = $k;
            }
        }
        // A unix socket makes the MySQL connector ignore the host entirely, so the host value would prove nothing.
        if (! empty($conn['unix_socket'])) {
            $overrides[] = 'connection unix_socket';
        }
        // Cache, queue and session can each use a database connection of their own, or a service that is not a database at all (Redis).
        $stores = ['cache' => ['default' => $extra['cacheStore'] ?? null, 'allowed' => ['array', 'file', 'database'], 'connection' => $extra['cacheConnection'] ?? null],
            'queue' => ['default' => $extra['queueDefault'] ?? null, 'allowed' => ['sync', 'null', 'database'], 'connection' => $extra['queueConnection'] ?? null],
            'session' => ['default' => $extra['sessionDriver'] ?? null, 'allowed' => ['array', 'file', 'cookie', 'database'], 'connection' => $extra['sessionConnection'] ?? null]];
        foreach ($stores as $name => $st) {
            if ($st['default'] !== null && ! in_array((string) $st['default'], $st['allowed'], true)) {
                $overrides[] = "{$name} backend '{$st['default']}' (allowed: " . implode(', ', $st['allowed']) . ')';
            }
            if (! in_array((string) ($st['connection'] ?? ''), ['', 'mysql'], true)) {
                $overrides[] = "{$name} database connection '{$st['connection']}'";
            }
        }

        $out = [
            'appEnv' => $appEnv,
            'dbHost' => isset($conn['host']) ? (string) $conn['host'] : null,
            'dbName' => isset($conn['database']) ? (string) $conn['database'] : null,
            'dbConnectionOverrides' => $overrides,
        ];
        if (array_key_exists('frameworkEnv', $extra)) {
            $out['frameworkEnv'] = $extra['frameworkEnv'];   // the environment the framework itself resolved (--env=… can differ from APP_ENV)
        }

        return $out;
    }

    /**
     * Runs $connect only if the environment passes. The check happens first and uses no connection, so a failure never reaches $connect.
     *
     * @param  array<string, mixed>  $effective
     * @return mixed whatever $connect returns
     */
    public static function guarded(array $effective, callable $connect): mixed
    {
        self::assert($effective);

        return $connect();
    }

    /**
     * @param  array<string, mixed>  $effective  appEnv, dbHost, dbName, plus optional dbConnectionOverrides (list of names of overriding settings that are set)
     *
     * @throws \RuntimeException naming every mismatch, without printing credentials
     */
    public static function assert(array $effective): void
    {
        $problems = [];
        foreach (['appEnv' => [self::ENV, 'APP_ENV'], 'dbHost' => [self::HOST, 'database host'], 'dbName' => [self::DATABASE, 'database name']] as $key => [$want, $label]) {
            $got = $effective[$key] ?? null;
            if (! is_string($got) || $got === '') {
                $problems[] = "{$label} is missing";
            } elseif ($got !== $want) {
                $problems[] = "{$label} must be exactly '{$want}'";
            }
        }
        if (array_key_exists('frameworkEnv', $effective) && $effective['frameworkEnv'] !== self::ENV) {
            $problems[] = "the framework environment must be exactly '" . self::ENV . "' (--env or APP_ENV disagree)";
        }
        foreach ((array) ($effective['dbConnectionOverrides'] ?? []) as $name) {
            $problems[] = "'{$name}' overrides the database settings";
        }
        if ($problems !== []) {
            throw new \RuntimeException('Verification preflight failed, nothing was run: ' . implode('; ', $problems) . '.');
        }
    }
}
