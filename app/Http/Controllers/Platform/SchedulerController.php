<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\PlatformRegistry;
use App\Services\Platform\ScheduleReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the platform runs on a timer.
 *
 * Read-only. The schedule itself is code — `routes/console.php` — and per-tenant
 * overrides are a later phase with a table behind them. Until that table exists there is
 * nothing here a write could legitimately change, and an endpoint that accepted a change
 * it could not persist would be worse than no endpoint.
 */
class SchedulerController extends PlatformController
{
    private const TABLE = 'g2g_platform_scheduled_tasks';

    public function __construct(
        private readonly ScheduleReader $reader,
        private readonly PlatformRegistry $registry,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            return $this->success(
                'Scheduled tasks.',
                $this->reader->tasks($scope->selectedInstituteId)
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Override a task's schedule for this organisation.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * REFUSED FOR THE FOUR TASKS THAT CANNOT HONOUR ONE
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `events:project` drains every organisation's events in one pass. There is
     * no sense in which it could run at 02:00 for one tenant and 06:00 for
     * another, so accepting a row for it would store a preference the scheduler
     * can never act on — a setting that looks like a control and is not.
     *
     * The catalogue marks which is which; this refuses the rest with the reason
     * rather than writing a row nothing will read.
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $taskKey = trim((string) $request->input('task_key', ''));

            if (! $this->registry->hasScheduledTask($taskKey)) {
                return $this->failure('That is not a scheduled task this platform declares.', 422, [
                    'task_key' => ['Unknown task.'],
                ]);
            }

            if (! $this->registry->taskIsTenantScoped($taskKey)) {
                $reason = $this->registry->scheduledTasks()[$taskKey]['estate_reason']
                    ?? 'This task is not configurable per organisation.';

                return $this->failure(
                    'This task runs for the whole installation and cannot be scheduled per '
                    . 'organisation. ' . $reason,
                    422,
                    ['task_key' => ['Not configurable per organisation.']]
                );
            }

            /*
             * Resetting DELETES the row rather than writing today's default into
             * it. Storing the current default would freeze it: improving the
             * shipped schedule would then reach every tenant except the ones who
             * had once pressed Reset, which is the opposite of what they asked for.
             */
            if ($request->boolean('reset')) {
                $deleted = DB::table(self::TABLE)
                    ->where('sub_institute_id', $scope->selectedInstituteId)
                    ->where('task_key', $taskKey)
                    ->delete();

                return $this->success('Reset to the shipped schedule.', ['reset' => $deleted > 0]);
            }

            $fields = $this->cronFields($request, $taskKey, $scope->selectedInstituteId);

            if (is_string($fields)) {
                return $this->failure($fields, 422, ['schedule' => [$fields]]);
            }

            DB::table(self::TABLE)->updateOrInsert(
                [
                    'sub_institute_id' => $scope->selectedInstituteId,
                    'task_key' => $taskKey,
                ],
                $fields + [
                    'disabled' => $request->boolean('disabled'),
                    'updated_by' => $this->actor($scope),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            return $this->success('Schedule saved.', [
                'task_key' => $taskKey,
                'expression' => implode(' ', array_values($fields)),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * The five cron fields from the request, merged onto whatever is stored.
     *
     * Returns a problem sentence instead when one of them is not a cron field.
     * A partial save is supported on purpose — the screen edits one field at a
     * time — so an absent field keeps its current value rather than resetting to
     * the shipped default, which would undo the rest of somebody's edit.
     *
     * @return array<string, string>|string
     */
    private function cronFields(Request $request, string $taskKey, int $tenantId): array|string
    {
        $existing = DB::table(self::TABLE)
            ->where('sub_institute_id', $tenantId)
            ->where('task_key', $taskKey)
            ->first();

        $shipped = $this->registry->scheduledTasks()[$taskKey]['schedule'] ?? [];

        $out = [];

        foreach (['minute', 'hour', 'day', 'month', 'day_of_week'] as $field) {
            $value = $request->input("schedule.$field")
                ?? $existing->$field
                ?? $shipped[$field]
                ?? '*';

            $value = trim((string) $value);

            /*
             * A deliberately narrow grammar: `*`, a number, a list, a range, or a
             * step. It covers everything the screen can produce and refuses the
             * rest, because a malformed expression does not fail loudly — the task
             * simply stops running, and nobody finds out until something that
             * depended on it is wrong.
             */
            if (! preg_match('#^(\*|\d+|\d+(,\d+)+|\d+-\d+|\*/\d+|\d+-\d+/\d+)$#', $value)) {
                return "\"{$value}\" is not a valid {$field} — use *, a number, 1,15, 1-5 or */10.";
            }

            $out[$field] = $value;
        }

        return $out;
    }

    private function actor(\App\Services\Ai\AiRequestScope $scope): string
    {
        $name = DB::table('tbluser')->where('id', $scope->userId)->value('first_name');

        return trim(((string) $name) . ' (' . $scope->userId . ')');
    }
}
