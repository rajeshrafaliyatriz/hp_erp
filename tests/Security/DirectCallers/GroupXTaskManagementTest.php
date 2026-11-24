<?php

namespace Tests\Security\DirectCallers;

use App\Http\Controllers\Api\TaskManagement\DependencyController;
use App\Http\Controllers\Api\TaskManagement\MyTasksController;
use App\Http\Controllers\Api\TaskManagement\ProjectController;
use App\Http\Controllers\Api\TaskManagement\TaskListController;
use App\Http\Controllers\Api\TaskManagement\WorkspaceController;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1c, group X: the task-management API (`/api/task-management/*`, ~147 routes, no `api.token`, no `auth`). Its identity comes from
 * ResolvesTaskContext::taskContext() (about 26 controllers) and from five controllers' own private context() copies; the middleware in front of most of it
 * (task.permission) was a plain-Sanctum lookup too. None checked expiry or the fabricated 'Session Token' name, so an expired token, or a fabricated
 * administrator-bound row, was full impersonation of that user inside the module.
 */
class GroupXTaskManagementTest extends GroupXTestCase
{
    /** Controller => the private method that holds its own lookup. */
    public const PRIVATE_CONTEXTS = [
        DependencyController::class => 'context',
        MyTasksController::class => 'context',
        ProjectController::class => 'context',
        TaskListController::class => 'context',
        WorkspaceController::class => 'context',
    ];

    /** @return array<string, array{0: string}> */
    public static function privateContexts(): array
    {
        $rows = [];
        foreach (array_keys(self::PRIVATE_CONTEXTS) as $class) {
            $rows[class_basename($class)] = [$class];
        }

        return $rows;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function privateContextByKind(): array
    {
        $rows = [];
        foreach (array_keys(self::PRIVATE_CONTEXTS) as $class) {
            foreach (self::REFUSED_KINDS as $kind) {
                $rows[class_basename($class) . ' / ' . $kind] = [$class, $kind];
            }
        }

        return $rows;
    }

    /**
     * The real read routes that are gated ONLY by an in-controller lookup (no task.permission in front), with the outcomes that can only come AFTER the token
     * gate: [uri, the module's own validation status without syear, its exact message, the first table the module reads once it has an identity].
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function realRoutes(): array
    {
        return [
            'TaskListController: GET /tasks' => ['/api/task-management/tasks', 422, 'syear is required.', 'task'],
            'MyTasksController: GET /my-tasks' => ['/api/task-management/my-tasks', 400, 'sub_institute_id and syear are required.', 'task'],
            'ProjectController: GET /projects' => ['/api/task-management/projects', 400, 'sub_institute_id and syear are required.', 'task_management_projects'],
            'WorkspaceController: GET /workspace' => ['/api/task-management/workspace', 422, 'syear is required.', 'task'],
            'DependencyController: GET /dependencies' => ['/api/task-management/dependencies', 400, 'sub_institute_id and syear are required.', 'task_management_dependencies'],
            'DependencyController: GET /milestones' => ['/api/task-management/milestones', 400, 'sub_institute_id and syear are required.', 'task_management_milestones'],
        ];
    }

    /* ── the shared trait (taskContext) ───────────────────────────────── */

    #[DataProvider('refusedKinds')]
    public function test_the_task_context_trait_refuses_every_unrecognised_fabricated_expired_orphaned_or_foreign_token(string $kind): void
    {
        $this->scenario();
        $token = $this->makeToken($kind);
        $rows = $this->tokenRows();
        $before = $this->snapshot();

        $response = $this->xCall('/__x/task_context', $token, 'bearer', [], 'query', null, ['syear' => '2026']);

        $this->assertSame(401, $response->getStatusCode(), $kind);
        $this->assertSame('Unauthenticated.', $response->json('message'));
        $this->assertNotTrue($response->json('ok'));
        $this->assertSame($rows, $this->tokenRows());
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('refusedKinds')]
    public function test_the_trait_never_takes_identity_or_tenant_from_the_request_for_a_refused_token(string $kind): void
    {
        $this->scenario();
        $token = $this->makeToken($kind);

        foreach (['query', 'body', 'header'] as $where) {
            $spoof = $where === 'header' ? ['user_id' => '1', 'sub_institute_id' => '1'] : ['user_id' => 1, 'sub_institute_id' => 1];
            $response = $this->xCall('/__x/task_context', $token, 'bearer', $spoof, $where, null, ['syear' => '2026']);
            $this->assertSame(401, $response->getStatusCode(), "{$kind} / {$where}");
        }
    }

    public function test_a_legitimate_token_still_gets_its_own_user_and_tenant_and_the_request_cannot_override_them(): void
    {
        [$admin, $employee, $adminToken, $employeeToken] = $this->scenario();

        $own = $this->xCall('/__x/task_context', $adminToken, 'bearer', ['sub_institute_id' => 2, 'user_id' => 2], 'query', null, ['syear' => '2026']);
        $own->assertOk();
        $this->assertSame($admin->id, $own->json('context.user_id'));
        $this->assertSame(1, $own->json('context.sub_institute_id'), 'the token owner decides the tenant, whatever the request says');

        $other = $this->xCall('/__x/task_context', $employeeToken, 'query', [], 'query', null, ['syear' => '2026']);
        $other->assertOk();
        $this->assertSame($employee->id, $other->json('context.user_id'));
        $this->assertSame(2, $other->json('context.sub_institute_id'));
    }

    public function test_the_trait_still_validates_after_authentication(): void
    {
        [, , $adminToken] = $this->scenario();

        // authenticated, but no syear: the module's own 422, which proves the auth layer let the request through
        $this->xCall('/__x/task_context', $adminToken)->assertStatus(422);
        $this->xCall('/__x/task_context', null)->assertStatus(401);
    }

    /* ── the five controllers' private context() ─────────────────────── */

    #[DataProvider('privateContextByKind')]
    public function test_each_controllers_own_context_refuses_every_unrecognised_fabricated_expired_orphaned_or_foreign_token(string $class, string $kind): void
    {
        $this->scenario();
        $token = $this->makeToken($kind);
        $rows = $this->tokenRows();

        $result = $this->callPrivate($class, self::PRIVATE_CONTEXTS[$class], $this->apiRequest($token, ['syear' => '2026', 'sub_institute_id' => 1, 'user_id' => 1]));

        $this->assertInstanceOf(JsonResponse::class, $result, class_basename($class) . ' / ' . $kind . ': a refused token must yield the unauthenticated response, not a context');
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame($rows, $this->tokenRows());
    }

    #[DataProvider('privateContexts')]
    public function test_each_controllers_own_context_still_resolves_a_legitimate_token_to_its_own_owner_and_tenant(string $class): void
    {
        [$admin, $employee, $adminToken, $employeeToken] = $this->scenario();

        $adminContext = $this->callPrivate($class, self::PRIVATE_CONTEXTS[$class], $this->apiRequest($adminToken, ['syear' => '2026', 'sub_institute_id' => 2]));
        $this->assertIsArray($adminContext, class_basename($class));
        $this->assertSame(1, $adminContext['sub_institute_id'], 'the request cannot move a legitimate user to another tenant');

        $employeeContext = $this->callPrivate($class, self::PRIVATE_CONTEXTS[$class], $this->apiRequest($employeeToken, ['syear' => '2026']));
        $this->assertIsArray($employeeContext);
        $this->assertSame(2, $employeeContext['sub_institute_id']);
        if (array_key_exists('user_id', $employeeContext)) {
            $this->assertSame($employee->id, $employeeContext['user_id']);
        }
    }

    public function test_a_legitimate_token_without_the_required_year_is_stopped_by_validation_not_authentication(): void
    {
        [, , $adminToken] = $this->scenario();

        foreach ([DependencyController::class, MyTasksController::class, ProjectController::class, TaskListController::class] as $class) {
            $result = $this->callPrivate($class, 'context', $this->apiRequest($adminToken));
            $this->assertInstanceOf(JsonResponse::class, $result);
            $this->assertContains($result->getStatusCode(), [400, 422], class_basename($class) . ': a recognised caller gets the module\'s own validation error');
        }
    }

    /* ── the real routes, through the real stack (api group middleware, task.sanitize, controller) ── */

    #[DataProvider('realRoutes')]
    public function test_the_real_task_routes_refuse_a_fabricated_or_expired_token(string $uri): void
    {
        $this->scenario();

        foreach (['fabricated', 'fabricated_id', 'expired', 'unknown', 'orphan', 'foreign_owner'] as $kind) {
            $response = $this->xCall($uri, $this->makeToken($kind), 'bearer', [], 'query', null, ['syear' => '2026']);
            $this->assertSame(401, $response->getStatusCode(), "{$uri} / {$kind}");
        }
    }

    /**
     * A legitimate token reaches the module's OWN validation: an exact status and message that exist only after the token gate (no table is read yet). A 500,
     * a TypeError or a refusal cannot satisfy this.
     */
    #[DataProvider('realRoutes')]
    public function test_a_legitimate_token_reaches_the_modules_own_validation_on_the_real_routes(string $uri, int $validationStatus, string $validationMessage): void
    {
        [, , $adminToken] = $this->scenario();

        $response = $this->xCall($uri, $adminToken);

        $this->assertSame($validationStatus, $response->getStatusCode(), $uri);
        $this->assertSame($validationMessage, $response->json('message'), $uri);
    }

    /**
     * With the year supplied, a legitimate token gets all the way to the module's first database read. The fixture has no task tables, so that read fails
     * with a QueryException naming the missing table - an outcome reachable ONLY past the token gate. Pinned exactly, so any other failure (a TypeError, an
     * authentication refusal) fails the test.
     */
    #[DataProvider('realRoutes')]
    public function test_a_legitimate_token_with_a_year_reaches_the_first_database_read_of_each_real_route(string $uri, int $validationStatus, string $validationMessage, string $table): void
    {
        [, , $adminToken] = $this->scenario();

        try {
            $this->withoutExceptionHandling()->getJson($uri . '?syear=2026', ['Authorization' => 'Bearer ' . $adminToken, 'Accept' => 'application/json']);
            $this->fail("{$uri}: expected the first read of a task table to fail in this fixture; the request must have been refused or answered earlier");
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString("no such table: {$table}", $e->getMessage(), $uri);
        }
    }

    public function test_the_real_privileged_task_routes_refuse_a_fabricated_administrator_token_at_task_permission(): void
    {
        $this->scenario();
        $fabricated = $this->makeToken('fabricated');

        // DELETE /legacy-tasks/{id} carries task.permission:task.delete; a fabricated administrator row used to satisfy it.
        $response = $this->deleteJson('/api/task-management/legacy-tasks/1?syear=2026', [], ['Authorization' => 'Bearer ' . $fabricated, 'Accept' => 'application/json']);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_an_expired_token_no_longer_works_on_the_privileged_task_routes(): void
    {
        $this->scenario();
        $expired = $this->makeToken('expired');

        $response = $this->deleteJson('/api/task-management/legacy-tasks/1?syear=2026', [], ['Authorization' => 'Bearer ' . $expired, 'Accept' => 'application/json']);

        $this->assertSame(401, $response->getStatusCode());
    }
}
