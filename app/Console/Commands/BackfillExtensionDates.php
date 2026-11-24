<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Fill `original_date` and `days_requested` on deadline-extension requests made
 * before those columns existed (migration 2026_12_24_090000).
 *
 *   pending, rejected, withdrawn   the task still has the date it was asked
 *                                  about, so its current due date IS the original.
 *   approved                       approval overwrote the due date. The old one
 *                                  survives in the `task.deadline_extended` event
 *                                  (payload.before.task_date); the event closest
 *                                  in time to the decision is used, and only if it
 *                                  is within a day of it. No such event: left empty.
 *
 * DRY RUN BY DEFAULT: it prints what it would write and changes nothing. With
 * --apply it writes one transaction per tenant, fills only columns that are
 * still NULL (a filled value is never overwritten), and a second run changes
 * nothing. Pass --evidence=<file> to keep the summary.
 */
class BackfillExtensionDates extends Command
{
    protected $signature = 'task:backfill-extension-dates {--apply : Write the values; without this nothing is changed} {--evidence= : Write the summary to this JSON file}';

    protected $description = 'Fill original_date and days_requested on older deadline-extension requests (dry run unless --apply)';

    public function handle(): int
    {
        if (!Schema::hasTable('task_deadline_extensions') || !Schema::hasColumn('task_deadline_extensions', 'original_date')) {
            $this->error('task_deadline_extensions has no original_date column. Run migration 2026_12_24_090000 first.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $hasEvents = Schema::hasTable('g2g_event');
        $summary = ['mode' => $apply ? 'apply' : 'dry-run', 'tenants' => []];

        $tenants = DB::table('task_deadline_extensions')->whereNull('original_date')->distinct()->pluck('sub_institute_id');

        foreach ($tenants as $tenant) {
            $counts = ['rows' => 0, 'from_task' => 0, 'from_event' => 0, 'no_source' => 0];
            $writes = [];

            $rows = DB::table('task_deadline_extensions as e')
                ->join('task as t', 't.id', '=', 'e.task_id')
                ->where('e.sub_institute_id', $tenant)
                ->whereNull('e.original_date')
                ->get(['e.id', 'e.task_id', 'e.status', 'e.requested_date', 'e.decided_on', 't.task_date']);

            foreach ($rows as $row) {
                $counts['rows']++;
                $original = null;

                if ($row->status !== 'approved') {
                    $original = $row->task_date;
                    $counts[$original ? 'from_task' : 'no_source']++;
                } elseif ($hasEvents && $row->decided_on) {
                    $original = $this->dateFromEvent((int) $tenant, (int) $row->task_id, Carbon::parse($row->decided_on));
                    $counts[$original ? 'from_event' : 'no_source']++;
                } else {
                    $counts['no_source']++;
                }

                if ($original) {
                    $writes[$row->id] = [
                        'original_date' => Carbon::parse($original)->toDateString(),
                        'days_requested' => (int) Carbon::parse($original)->startOfDay()->diffInDays(Carbon::parse($row->requested_date)->startOfDay(), false),
                    ];
                }
            }

            if ($apply && $writes) {
                DB::transaction(function () use ($writes) {
                    foreach ($writes as $id => $values) {
                        // whereNull: a value someone already filled is never overwritten.
                        DB::table('task_deadline_extensions')->where('id', $id)->whereNull('original_date')->update($values);
                    }
                });
            }

            $counts['would_write'] = count($writes);
            $summary['tenants'][(string) $tenant] = $counts;
            $this->line(sprintf(
                'tenant %s: %d row(s) missing a date; %d from the task, %d from the event store, %d with no source; %s %d',
                $tenant, $counts['rows'], $counts['from_task'], $counts['from_event'], $counts['no_source'],
                $apply ? 'wrote' : 'would write', count($writes)
            ));
        }

        if (!$apply) {
            $this->info('Dry run: nothing was changed. Re-run with --apply to write.');
        }

        if ($path = $this->option('evidence')) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Summary saved to ' . $path);
        }

        return self::SUCCESS;
    }

    /** The due date the task had before the `deadline_extended` event nearest this decision, or null. */
    private function dateFromEvent(int $tenant, int $taskId, Carbon $decidedOn): ?string
    {
        $events = DB::table('g2g_event')
            ->where('sub_institute_id', $tenant)
            ->where('entity_type', 'task')
            ->where('entity_id', $taskId)
            ->where('type', 'task.deadline_extended')
            ->get(['occurred_at', 'payload']);

        $best = null;
        $bestGap = null;
        foreach ($events as $event) {
            $gap = abs(Carbon::parse($event->occurred_at)->diffInSeconds($decidedOn));
            if ($gap > 86_400 || ($bestGap !== null && $gap >= $bestGap)) {
                continue;
            }
            $date = json_decode((string) $event->payload, true)['before']['task_date'] ?? null;
            if ($date) {
                $best = $date;
                $bestGap = $gap;
            }
        }

        return $best;
    }
}
