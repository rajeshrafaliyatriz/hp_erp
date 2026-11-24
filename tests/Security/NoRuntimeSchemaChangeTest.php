<?php

namespace Tests\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1, the schema half: authentication must never execute CREATE/ALTER/DROP/TRUNCATE/RENAME, and a missing `personal_access_tokens` table must make every
 * caller answer 401 (fail closed) instead of being created on the spot.
 *
 * The removed code ran `CREATE TABLE IF NOT EXISTS personal_access_tokens ...` from PersonalAccessTokenResolver::ensureTable(), which every token lookup
 * called AND AppServiceProvider::boot() called on every request.
 */
class NoRuntimeSchemaChangeTest extends TokenTestCase
{
    /** @return array<string, array{0: string}> */
    public static function tokenStates(): array
    {
        return ['known token' => ['known'], 'unknown token' => ['unknown'], 'expired token' => ['expired'], 'guessed id' => ['guessed']];
    }

    private function tokenFor(string $state): string
    {
        [, , $adminToken] = $this->twoTenants();

        return match ($state) {
            'known' => $adminToken,
            'unknown' => 'definitely-not-a-token',
            'guessed' => '1|garbage',
            'expired' => $this->issueToken(\App\Models\auth\tbluserModel::query()->find(1), 'Chrome on Windows', now()->subDay()),
        };
    }

    /**
     * HONEST LIMIT: this test can never fail on SQLite against the OLD code. The old `ensureTable()` first asked `information_schema.tables ... table_schema =
     * DATABASE()`, which SQLite cannot run, so it died (and was swallowed) before reaching its `CREATE TABLE`; with the table PRESENT it would not have issued
     * the DDL on MySQL/MariaDB either. It is kept as a guard against a regression to DDL in the request path. The tests that CAN fail against the old code are
     * test_with_a_fake_information_schema_no_create_table_is_even_attempted and the source scan.
     */
    #[DataProvider('tokenStates')]
    public function test_no_caller_issues_schema_changing_sql_with_the_tokens_table_present(string $state): void
    {
        $token = $this->tokenFor($state);
        $this->startQueryLog();

        foreach (self::callerKeys() as $caller) {
            $this->callWithToken($caller, $token);
        }
        $this->callWithToken('api_group', $token);

        $this->assertNotSame([], $this->sql, 'the listener saw the requests');
        $this->assertSame([], $this->ddlStatements(), 'no CREATE/ALTER/DROP/TRUNCATE/RENAME');
    }

    /**
     * HONEST LIMIT: on SQLite the "nothing is created" half cannot fail against the OLD code (see the previous docblock: its information_schema probe fails
     * first). The 401 half is real: the old code answered 200/401 depending on the path but never failed closed on a missing table.
     */
    #[DataProvider('tokenStates')]
    public function test_with_the_tokens_table_missing_every_caller_answers_401_and_nothing_is_created(string $state): void
    {
        $token = $this->tokenFor($state);
        Schema::drop('personal_access_tokens');
        $this->startQueryLog();

        foreach (self::callerKeys() as $caller) {
            $this->callWithToken($caller, $token)->assertStatus(401);
            $this->callWithToken($caller, $token, 'query')->assertStatus(401);
        }
        $this->callWithToken('api_group', $token)->assertStatus(401);

        $this->assertSame([], $this->ddlStatements());
        $this->assertSame([], $this->writeStatements());
        $this->assertFalse(Schema::hasTable('personal_access_tokens'), 'the table was not created by a request');
    }

    /**
     * The boot-time call was the second source of DDL: boot() ran it on every request.
     *
     * HONEST LIMIT: on SQLite this cannot fail against the old code either (same information_schema reason). See the fake-information_schema test below.
     */
    public function test_booting_the_application_provider_issues_no_schema_change_and_does_not_create_the_tokens_table(): void
    {
        Schema::drop('personal_access_tokens');
        $this->startQueryLog();

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame([], $this->ddlStatements());
        $this->assertSame([], $this->writeStatements());
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
    }

    /** The scheduler listener registration in boot() is unrelated; this proves boot() is callable repeatedly without touching the table. */
    public function test_booting_twice_still_changes_no_schema(): void
    {
        $this->startQueryLog();
        $provider = new AppServiceProvider($this->app);
        $provider->boot();
        $provider->boot();

        $this->assertSame([], $this->ddlStatements());
        $this->assertSame(0, $this->tokenRows());
    }

    /** An unknown token on a request that DID have the table must not insert into it either (the other half of "no write"). */
    public function test_an_unknown_token_inserts_nothing_even_when_the_table_exists(): void
    {
        $this->twoTenants();
        $this->startQueryLog();

        $this->callWithToken('api_group', 'definitely-not-a-token', 'bearer', ['user_id' => '1', 'sub_institute_id' => '1'], 'header');
        foreach (self::callerKeys() as $caller) {
            $this->callWithToken($caller, 'definitely-not-a-token');
        }

        $inserts = array_filter($this->sql, static fn (string $s) => preg_match('/^\s*insert\b/i', $s) === 1);
        $this->assertSame([], array_values($inserts));
        $this->assertSame(2, (int) DB::table('personal_access_tokens')->count());
    }

    /**
     * BEHAVIOURAL, and able to fail against the old code. It makes the old `ensureTable()` probe SUCCEED on SQLite: a SQLite function `DATABASE()` is
     * registered on the connection's PDO and an in-memory database named `information_schema` with an empty `tables` table is attached, so
     * `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'personal_access_tokens'` answers 0 ("table missing").
     * Under the old code that is the branch that issues `CREATE TABLE IF NOT EXISTS personal_access_tokens ...`. Under the fixed code no statement containing
     * "create table" may even be ATTEMPTED (recorded both by QueryExecuted and by beforeExecuting, so a statement SQLite then rejects is still seen).
     *
     * (The claim that the old code WOULD attempt it under this fake was verified separately against the legacy copy in the scratchpad mutation folder; see the
     * report. It is not asserted here because the old class no longer exists in the repository.)
     */
    public function test_with_a_fake_information_schema_no_create_table_is_even_attempted(): void
    {
        $this->installFakeInformationSchema();
        // Control: the probe the old code used now WORKS on this connection and reports "no such table", i.e. the old code would proceed to CREATE TABLE.
        $probe = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'personal_access_tokens'");
        $this->assertSame(0, (int) $probe->n, 'control: the legacy probe reaches its "create it" branch on this connection');

        $this->twoTenants();
        Schema::drop('personal_access_tokens');
        $this->startQueryLog();

        foreach (['definitely-not-a-token', '1|garbage', 'x'] as $token) {
            foreach (self::callerKeys() as $caller) {
                $this->callWithToken($caller, $token);
            }
            $this->callWithToken('api_group', $token);
        }
        (new AppServiceProvider($this->app))->boot();

        $this->assertNotSame([], $this->attempted, 'the beforeExecuting recorder saw statements');
        $this->assertSame([], array_values(array_filter($this->attempted, static fn (string $s) => preg_match('/create\s+table/i', $s) === 1)), 'no CREATE TABLE was attempted');
        $this->assertSame([], array_values(array_filter($this->sql, static fn (string $s) => preg_match('/create\s+table/i', $s) === 1)), 'none executed either');
        $this->assertSame([], array_values(array_filter($this->attempted, static fn (string $s) => stripos($s, 'information_schema') !== false)), 'the request path no longer even probes information_schema');
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
    }

    private function installFakeInformationSchema(): void
    {
        $pdo = $this->db()->getPdo();
        $pdo->sqliteCreateFunction('DATABASE', static fn () => 'main', 0);
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');
    }

    /**
     * Independent of the resolver class: scan every request-path directory for DDL that touches `personal_access_tokens` (or any `ensureTable`), with comments
     * stripped by the PHP tokenizer. Directories: app/Http/Middleware, app/Providers, app/Support, bootstrap (not its cache), plus the resolver file.
     */
    public function test_no_request_path_source_contains_ddl_for_the_tokens_table(): void
    {
        $offences = [];
        $scanned = 0;
        foreach (['app/Http/Middleware', 'app/Providers', 'app/Support', 'bootstrap'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if ($file->getExtension() !== 'php' || str_contains($path, '/bootstrap/cache/')) {
                    continue;
                }
                $scanned++;
                foreach (self::ddlOffences((string) file_get_contents($path)) as $offence) {
                    $offences[] = substr($path, strlen(str_replace('\\', '/', base_path())) + 1) . ': ' . $offence;
                }
            }
        }

        $this->assertGreaterThan(30, $scanned, 'the scan really covered the request-path directories');
        $this->assertSame([], $offences);
    }

    /** The scanner can detect what it claims to: a legacy-shaped snippet is flagged, commented-out and unrelated code is not. */
    public function test_the_ddl_scanner_flags_legacy_shaped_code_and_ignores_comments_and_other_tables(): void
    {
        $legacy = '<?php class X { public static function ensureTable(): void { DB::statement("CREATE TABLE IF NOT EXISTS `personal_access_tokens` (id int)"); } }';
        $this->assertNotSame([], self::ddlOffences($legacy));
        $this->assertNotSame([], self::ddlOffences('<?php Schema::create("personal_access_tokens", fn () => 1);'));
        $this->assertSame([], self::ddlOffences('<?php // CREATE TABLE personal_access_tokens ensureTable' . "\n" . '/* Schema::create("personal_access_tokens") */ $x = 1;'));
        $this->assertSame([], self::ddlOffences('<?php DB::statement("CREATE TABLE other (id int)");'), 'DDL on some other table is not this guard\'s business');
        $this->assertSame([], self::ddlOffences('<?php DB::table("personal_access_tokens")->update(["last_used_at" => now()]);'), 'ordinary reads/writes are not DDL');
    }

    /** @return list<string> */
    private static function ddlOffences(string $php): array
    {
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        $found = [];
        if (stripos($code, 'ensureTable') !== false) {
            $found[] = 'ensureTable';
        }
        if (stripos($code, 'personal_access_tokens') !== false
            && preg_match('/create\s+table|alter\s+table|drop\s+table|truncate\s+table|rename\s+table|Schema::create\(|Schema::table\(|Schema::drop\w*\(|DB::statement\(/i', $code, $m) === 1) {
            $found[] = 'DDL touching personal_access_tokens (' . trim($m[0]) . ')';
        }

        return $found;
    }

    /** SECONDARY GUARD (labelled): the removed methods are gone from the resolver and the provider no longer calls them. */
    public function test_secondary_guard_the_removed_methods_are_gone(): void
    {
        $this->assertFalse(method_exists(\App\Support\PersonalAccessTokenResolver::class, 'ensureTable'));
        $this->assertFalse(method_exists(\App\Support\PersonalAccessTokenResolver::class, 'resolveAdminUser'));
        $this->assertFalse(method_exists(\App\Support\PersonalAccessTokenResolver::class, 'resolveOrProvision'));
    }
}
