<?php

namespace Tests\Security\DirectCallers;

use App\Http\Controllers\Api\jobrolecontroller;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1c, Group Y: every changed lookup site that is reachable through its real route, driven through the REAL controller with the REAL resolver.
 *
 * What is proven per site (see GroupYTestCase for why these six token kinds were accepted by the plain Sanctum lookup):
 *   (a) each of six bad-token kinds (unknown string, guessed id, fabricated "Session Token" bound to an administrator, EXPIRED token, orphaned token, token
 *       owned by another model class) gets the site's own unauthenticated answer (401 + message), with nothing written - one data-provider row per site
 *       AND kind, so a failure names both;
 *   (b) request-supplied user_id / sub_institute_id / user_profile_name (query or body) cannot rescue any of them;
 *   (c) a legitimate login token gets the site's EXACT post-authentication answer: a status and a body marker that can only be produced AFTER the token gate
 *       (a validation error, a "Not found", an empty list from the real query, the owner's own profile). A 500, a TypeError or an exception from anywhere
 *       else no longer passes.
 *
 * `GET /table_data`, `GET /AICourseGeneration` and `GET /gammaContent` (AJAXController) are in GroupYAjaxTest.
 */
class GroupYSitesTest extends GroupYTestCase
{
    /** Message each site answers with when the lookup returns null. */
    private const INVALID = 'Invalid token';

    /**
     * Each site: method, uri, optional fixed params, optional `bearer` (also accepts Authorization: Bearer), optional `message` (its own 401 text), and `legit`:
     * the exact post-authentication outcome for a legitimate tenant-1 administrator token with the fixtures of GroupYTestCase::createSiteTables().
     * `legit.json` maps a dot-path of the JSON body to the exact value expected there.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function siteDefinitions(): array
    {
        $api = ['type' => 'API'];
        $required = static fn (string $field) => ['The ' . $field . ' field is required.'];

        return [
            'GammaApi index (GET /api/gamma-api)' => [
                'method' => 'GET', 'uri' => '/api/gamma-api',
                'legit' => ['status' => 200, 'json' => ['data' => []]],
            ],
            'GammaApi store (POST /api/gamma-api)' => [
                'method' => 'POST', 'uri' => '/api/gamma-api',
                'legit' => ['status' => 422, 'json' => ['errors.account' => $required('account'), 'errors.key' => $required('key')]],
            ],
            'GammaApi show (GET /api/gamma-api/{id})' => [
                'method' => 'GET', 'uri' => '/api/gamma-api/1',
                'legit' => ['status' => 404, 'json' => ['message' => 'Not found']],
            ],
            'GammaApi update (PUT /api/gamma-api/{id})' => [
                'method' => 'PUT', 'uri' => '/api/gamma-api/1',
                'legit' => ['status' => 404, 'json' => ['message' => 'Not found']],
            ],
            'GammaApi destroy (DELETE /api/gamma-api/{id})' => [
                'method' => 'DELETE', 'uri' => '/api/gamma-api/1',
                'legit' => ['status' => 404, 'json' => ['message' => 'Not found']],
            ],
            'GammaApi getBySubInstituteId (GET /api/gamma-api/sub-institute/{id})' => [
                'method' => 'GET', 'uri' => '/api/gamma-api/sub-institute/1',
                'legit' => ['status' => 200, 'json' => ['data' => []]],
            ],
            'UserImport importUsers (POST /api/import-users)' => [
                'method' => 'POST', 'uri' => '/api/import-users', 'bearer' => true,
                'message' => 'Authentication is required to import users.',
                'legit' => ['status' => 200, 'json' => ['status' => false, 'message' => 'Please upload CSV file']],
            ],
            'UserProfile show (GET /api/user/profile)' => [
                'method' => 'GET', 'uri' => '/api/user/profile', 'bearer' => true,
                'legit' => ['status' => 200, 'json' => ['status_code' => 1, 'message' => 'Profile details fetched successfully', 'data.id' => 1, 'data.user_profile_name' => 'Administrator', 'data.sub_institute_id' => 1]],
            ],
            'Industry index (GET /api/industries)' => [
                'method' => 'GET', 'uri' => '/api/industries',
                'legit' => ['status' => 200, 'json' => ['data' => []]],
            ],
            'Industry departments (GET /api/industry/{id}/departments)' => [
                'method' => 'GET', 'uri' => '/api/industry/x/departments',
                'legit' => ['status' => 200, 'json' => ['industry_id' => 'x', 'industry_departments' => []]],
            ],
            'jobrole getJobRolesByDepartment (GET /api/department/{id}/jobroles)' => [
                'method' => 'GET', 'uri' => '/api/department/x/jobroles',
                'legit' => ['status' => 200, 'json' => ['department' => 'x', 'job_roles' => []]],
            ],
            'jobrole skills (GET /api/jobrole/{id}/skills)' => [
                'method' => 'GET', 'uri' => '/api/jobrole/x/skills', 'bearer' => true,
                'legit' => ['status' => 200, 'json' => ['jobRoles' => 'x', 'skills' => []]],
            ],
            'skill store (POST /api/skills, type=API)' => [
                'method' => 'POST', 'uri' => '/api/skills', 'params' => $api,
                'legit' => ['status' => 422, 'json' => ['status' => 0, 'message' => 'The title field is required.']],
            ],
            'skill destroy (DELETE /api/skills/{id}, type=API)' => [
                'method' => 'DELETE', 'uri' => '/api/skills/1', 'params' => $api,
                'legit' => ['status' => 200, 'json' => ['message' => 'Failed to delete !!']],
            ],
            'UserJourneyLog store (POST /api/user-journey-logs)' => [
                'method' => 'POST', 'uri' => '/api/user-journey-logs',
                'legit' => ['status' => 422, 'json' => ['success' => false, 'message' => 'Validation failed', 'errors.user_id' => $required('user id')]],
            ],
            'UserJourneyLog storeBulk (POST /api/user-journey-logs/bulk)' => [
                'method' => 'POST', 'uri' => '/api/user-journey-logs/bulk',
                'legit' => ['status' => 422, 'json' => ['success' => false, 'message' => 'Validation failed', 'errors.logs' => $required('logs')]],
            ],
            'jobroleskill storeskill (POST /api/jobrole-skill/store, type=API)' => [
                'method' => 'POST', 'uri' => '/api/jobrole-skill/store', 'params' => $api,
                'legit' => ['status' => 422, 'json' => ['message' => 'The department field is required.']],
            ],
            'buildwithAI index (GET /api/index)' => [
                'method' => 'GET', 'uri' => '/api/index',
                'legit' => ['status' => 200, 'json' => ['status' => true, 'message' => 'AI Course Outlines received successfully', 'data' => []]],
            ],
            'buildwithAI store (POST /api/save-generated-course, type=API)' => [
                'method' => 'POST', 'uri' => '/api/save-generated-course', 'params' => $api,
                'legit' => ['status' => 422, 'json' => ['status' => false, 'errors.course_type' => $required('course type')]],
            ],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>}> one row per site */
    public static function sites(): array
    {
        $rows = [];
        foreach (self::siteDefinitions() as $label => $site) {
            $rows[$label] = [$site + ['label' => $label]];
        }

        return $rows;
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> one row per site AND bad-token kind */
    public static function siteKinds(): array
    {
        $rows = [];
        foreach (self::siteDefinitions() as $label => $site) {
            foreach (self::BAD_KINDS as $kind) {
                $rows[$label . ' | ' . $kind] = [$site + ['label' => $label], $kind];
            }
        }

        return $rows;
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    /** Send to the real route. The token travels as the `token` input (or as a Bearer header when $bearer). */
    private function hit(array $site, ?string $token, array $extra = [], bool $bearer = false): \Illuminate\Testing\TestResponse
    {
        $params = array_merge($site['params'] ?? [], $extra);
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            if ($bearer) {
                $headers['Authorization'] = 'Bearer ' . $token;
            } else {
                $params['token'] = $token;
            }
        }

        return $site['method'] === 'GET'
            ? $this->getJson($site['uri'] . ($params === [] ? '' : '?' . http_build_query($params)), $headers)
            : $this->json($site['method'], $site['uri'], $params, $headers);
    }

    private function assertUnauthenticated(array $site, \Illuminate\Testing\TestResponse $response, string $what): void
    {
        $response->assertStatus(401);
        $this->assertSame($site['message'] ?? self::INVALID, $response->json('message'), $site['label'] . ' / ' . $what . ': the site\'s own unauthenticated answer');
        $this->assertNull($response->json('data'), $site['label'] . ' / ' . $what . ': no data may be returned');
    }

    /** The exact post-authentication outcome: the status AND each marker in the body. */
    private function assertPostAuthOutcome(array $site, \Illuminate\Testing\TestResponse $response, string $what, array $overrides = []): void
    {
        $legit = array_replace_recursive($site['legit'], $overrides);
        $this->assertSame($legit['status'], $response->status(), $site['label'] . ' / ' . $what . ': status. Body: ' . substr((string) $response->getContent(), 0, 200));
        foreach ($legit['json'] as $path => $expected) {
            $this->assertSame($expected, $response->json($path), $site['label'] . ' / ' . $what . ': body marker "' . $path . '"');
        }
    }

    /* ── (a) bad tokens: one row per site and kind ────────────────────── */

    #[DataProvider('siteKinds')]
    public function test_a_bad_token_gets_the_sites_own_unauthenticated_answer_and_nothing_is_written(array $site, string $kind): void
    {
        $this->createSiteTables();
        $token = $this->badToken($kind);
        $before = $this->snapshotAll();

        $this->assertUnauthenticated($site, $this->hit($site, $token), $kind);
        if (($site['bearer'] ?? false) === true) {
            $this->assertUnauthenticated($site, $this->hit($site, $token, [], true), $kind . ' (bearer header)');
        }

        $this->assertSame($before, $this->snapshotAll(), $site['label'] . ' / ' . $kind . ': a refused request wrote nothing');
    }

    /* ── (b) spoofed identity: one row per site and kind ──────────────── */

    #[DataProvider('siteKinds')]
    public function test_request_supplied_user_and_tenant_cannot_rescue_an_unrecognised_token(array $site, string $kind): void
    {
        $this->createSiteTables();
        $token = $this->badToken($kind);
        $before = $this->snapshotAll();

        foreach ($this->spoofs() as $spoof) {
            $this->assertUnauthenticated($site, $this->hit($site, $token, $spoof), $kind . ' + ' . json_encode($spoof));
        }

        $this->assertSame($before, $this->snapshotAll());
    }

    #[DataProvider('sites')]
    public function test_spoofed_identity_without_any_token_is_refused(array $site): void
    {
        $this->createSiteTables();
        $this->badTokens();
        $before = $this->snapshotAll();

        foreach ($this->spoofs() as $spoof) {
            $response = $this->hit($site, null, $spoof);
            $response->assertStatus(401);
            $this->assertNull($response->json('data'), $site['label']);
        }

        $this->assertSame($before, $this->snapshotAll());
    }

    /* ── (c) legitimate tokens: the EXACT post-authentication outcome ─── */

    #[DataProvider('sites')]
    public function test_a_legitimate_login_token_gets_the_exact_post_authentication_outcome(array $site): void
    {
        $this->createSiteTables();

        $this->assertPostAuthOutcome($site, $this->hit($site, $this->adminToken), 'tenant-1 administrator token');
        if (($site['bearer'] ?? false) === true) {
            $this->assertPostAuthOutcome($site, $this->hit($site, $this->adminToken, [], true), 'tenant-1 administrator, Bearer header');
        }

        // The other tenant's own token reaches the same gate. (The profile endpoint answers with ITS owner: see its dedicated test.)
        $overrides = str_contains($site['label'], 'UserProfile') ? ['json' => ['data.id' => 2, 'data.user_profile_name' => 'Employee', 'data.sub_institute_id' => 2]] : [];
        $this->assertPostAuthOutcome($site, $this->hit($site, $this->otherToken), 'tenant-2 employee token', $overrides);
    }

    /* ── specifics the matrix cannot say ──────────────────────────────── */

    #[DataProvider('kinds')]
    public function test_gamma_api_keys_are_not_listed_to_a_bad_token(string $kind): void
    {
        $this->createSiteTables();
        \DB::table('gamma_api')->insert(['id' => 1, 'account' => 'acct', 'key' => 'secret-key', 'status' => 1, 'limit' => 5, 'sub_institute_id' => 2]);

        $response = $this->getJson('/api/gamma-api?token=' . urlencode($this->badToken($kind)));
        $response->assertStatus(401);
        $this->assertStringNotContainsString('secret-key', (string) $response->getContent(), $kind);
    }

    public function test_gamma_api_keys_are_still_listed_to_a_legitimate_token(): void
    {
        $this->createSiteTables();
        \DB::table('gamma_api')->insert(['id' => 1, 'account' => 'acct', 'key' => 'secret-key', 'status' => 1, 'limit' => 5, 'sub_institute_id' => 2]);

        $ok = $this->getJson('/api/gamma-api?token=' . urlencode($this->adminToken));
        $ok->assertStatus(200);
        $this->assertSame('acct', $ok->json('data.0.account'), 'unchanged for a legitimate token: it still lists the table');
    }

    public function test_user_profile_answers_with_its_own_owner_and_never_with_the_fabricated_administrators_profile(): void
    {
        $this->createSiteTables();
        \DB::table('tbluser')->where('id', 1)->update(['first_name' => 'Ada', 'last_name' => 'Admin']);
        \DB::table('tbluser')->where('id', 2)->update(['first_name' => 'Eve', 'last_name' => 'Employee']);

        $mine = $this->getJson('/api/user/profile?token=' . urlencode($this->otherToken));
        $mine->assertStatus(200);
        $this->assertSame('Eve Employee', $mine->json('data.full_name'));
        $this->assertSame(2, $mine->json('data.sub_institute_id'));

        $fabricated = $this->admin->createToken('Session Token', ['*'], now()->addDays(30))->plainTextToken;
        foreach (['query' => $this->getJson('/api/user/profile?token=' . urlencode($fabricated)), 'bearer' => $this->getJson('/api/user/profile', ['Authorization' => 'Bearer ' . $fabricated])] as $how => $response) {
            $response->assertStatus(401);
            $this->assertSame('Invalid token', $response->json('message'), $how);
            $this->assertStringNotContainsString('Ada', (string) $response->getContent(), $how . ': the administrator\'s profile is not revealed');
        }
    }

    public function test_user_import_with_an_expired_token_is_refused_and_with_a_legitimate_one_reaches_the_file_check(): void
    {
        $this->createSiteTables();
        $expired = $this->badToken('expired legitimate token');

        $this->postJson('/api/import-users?token=' . urlencode($expired))->assertStatus(401);

        $legit = $this->postJson('/api/import-users?token=' . urlencode($this->adminToken));
        $this->assertSame(200, $legit->status());
        $this->assertSame('Please upload CSV file', $legit->json('message'), 'past authentication it asks for the file');
    }

    public function test_skill_store_and_destroy_with_a_legitimate_token(): void
    {
        $this->createSiteTables();

        $store = $this->postJson('/api/skills', ['type' => 'API', 'token' => $this->adminToken]);
        $this->assertSame(422, $store->status());
        $this->assertSame('The title field is required.', $store->json('message'), 'validation: only reachable after the token gate');

        \DB::table('s_users_skills')->insert(['id' => 9, 'sub_institute_id' => 1, 'title' => 't']);
        $destroy = $this->deleteJson('/api/skills/9', ['type' => 'API', 'token' => $this->adminToken]);
        $destroy->assertStatus(200);
        $this->assertSame('skill deleted successfully !!', $destroy->json('message'));
        $this->assertNotNull(\DB::table('s_users_skills')->where('id', 9)->value('deleted_at'), 'unchanged behaviour for a legitimate caller: it soft-deletes');
    }

    #[DataProvider('kinds')]
    public function test_skill_destroy_with_a_bad_token_leaves_the_row_alone(string $kind): void
    {
        $this->createSiteTables();
        \DB::table('s_users_skills')->insert(['id' => 10, 'sub_institute_id' => 1, 'title' => 'keep']);

        $this->deleteJson('/api/skills/10', ['type' => 'API', 'token' => $this->badToken($kind)])->assertStatus(401);
        $this->assertNull(\DB::table('s_users_skills')->where('id', 10)->value('deleted_at'), $kind . ': the row was not deleted');
    }

    public function test_journey_logs_with_a_legitimate_token_write_nothing_without_a_valid_payload(): void
    {
        $this->createSiteTables();
        $this->assertSame(422, $this->postJson('/api/user-journey-logs?token=' . urlencode($this->adminToken))->status());
        $this->assertSame(422, $this->postJson('/api/user-journey-logs/bulk?token=' . urlencode($this->adminToken))->status());
    }

    #[DataProvider('kinds')]
    public function test_the_skill_search_endpoint_is_refused_for_a_bad_token_when_invoked_directly(string $kind): void
    {
        // GET /api/skills/search is registered AFTER Route::resource('skills'), whose `skills/{skill}` pattern matches it first, so this method is not reachable
        // over HTTP in the current route order. The real method is therefore exercised directly.
        $this->createSiteTables();
        $controller = app(jobrolecontroller::class);

        $response = $controller->searchskills(Request::create('/api/skills/search', 'GET', ['token' => $this->badToken($kind), 'query' => 'x']));
        $this->assertSame(401, $response->getStatusCode(), $kind);
        $this->assertSame('Invalid token', $response->getData(true)['message'], $kind);
    }

    public function test_the_skill_search_endpoint_with_no_token_and_with_a_legitimate_token(): void
    {
        $this->createSiteTables();
        $controller = app(jobrolecontroller::class);

        $this->assertSame(401, $controller->searchskills(Request::create('/x', 'GET'))->getStatusCode(), 'no token');

        $ok = $controller->searchskills(Request::create('/api/skills/search', 'GET', ['token' => $this->adminToken, 'query' => 'x']));
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(['status' => 1, 'skills' => []], $ok->getData(true), 'the exact post-authentication answer');
    }

    #[DataProvider('kinds')]
    public function test_buildwithai_index_does_not_return_every_tenants_outlines_to_a_bad_token(string $kind): void
    {
        $this->createSiteTables();
        \DB::table('ai_course_outlines')->insert(['id' => 1, 'course_type' => 'tenant-secret-outline', 'input_fields' => '{}', 'configure_fields' => '{}', 'outline' => '{}', 'sub_institute_id' => 2]);

        $response = $this->getJson('/api/index?token=' . urlencode($this->badToken($kind)));
        $response->assertStatus(401);
        $this->assertStringNotContainsString('tenant-secret-outline', (string) $response->getContent(), $kind);
    }

    public function test_buildwithai_index_is_unchanged_for_a_legitimate_token(): void
    {
        $this->createSiteTables();
        \DB::table('ai_course_outlines')->insert(['id' => 1, 'course_type' => 'outline-a', 'input_fields' => '{}', 'configure_fields' => '{}', 'outline' => '{}', 'sub_institute_id' => 1]);

        $ok = $this->getJson('/api/index?token=' . urlencode($this->adminToken));
        $ok->assertStatus(200);
        $this->assertTrue($ok->json('status'));
        $this->assertSame('outline-a', $ok->json('data.0.course_type'));
    }

    public function test_the_legitimate_login_token_form_is_what_the_application_issues(): void
    {
        // Guard against a vacuous suite: the "legitimate" tokens above really are normal login tokens (named like a device, unexpired, owned by tbluserModel).
        $row = \DB::table('personal_access_tokens')->where('tokenable_id', 1)->first();
        $this->assertSame('Chrome on Windows', $row->name);
        $this->assertSame(\App\Models\auth\tbluserModel::class, $row->tokenable_type);
        $this->assertStringContainsString('|', $this->adminToken);
    }
}
