<?php

namespace App\Console\Commands;

use App\Services\Competency\TaskExecutionClassifier;
use App\Services\DeepSeekService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Classify a tenant's job role tasks — §6.2's "100% of tasks AI-proposed".
 *
 * ── WHY A COMMAND AND NOT THE BUTTON ────────────────────────────────────────
 *
 * Classification is one model call per job role, synchronous, ~16 seconds each.
 * Tenant 6 is 150 roles. Through the UI that is 150 clicks and roughly 40
 * minutes of somebody watching a spinner, with every request having to survive
 * PHP's max_execution_time and any proxy in front of it. The per-role button is
 * right for one role and wrong for a library.
 *
 * ── DRY RUN BY DEFAULT ──────────────────────────────────────────────────────
 *
 * Follows DepartmentsDedupe and JobrolesBackfillIds: this prints what it would
 * do and what it would cost, and calls DeepSeek zero times, unless --apply is
 * given. A spend estimate you can read before paying is the point.
 *
 *   php artisan eso:classify --tenant=6
 *   php artisan eso:classify --tenant=6 --limit=10 --apply
 *   php artisan eso:classify --tenant=6 --apply --max-spend=0.50
 *
 * Resumable: already-classified rows are skipped, and rows a person has decided
 * on are never touched, so a re-run after an interruption continues rather than
 * starting over.
 */
class EsoClassify extends Command
{
    protected $signature = 'eso:classify
                            {--tenant= : Tenant (sub_institute_id) to classify. Required.}
                            {--limit= : Only the first N unclassified roles, largest first}
                            {--role= : One named job role}
                            {--apply : Actually call the model. Without this it is a dry run.}
                            {--max-spend=1.00 : Stop once this many USD have been spent}';

    protected $description = 'Classify job role tasks into execution modes (dry run unless --apply)';

    /**
     * Measured on this account, 2026-08-27: 129 output tokens per answered task,
     * ~52 input tokens per task line plus ~490 of fixed scaffold per call.
     * Used only for the estimate; real spend comes from DeepSeek's own counts.
     */
    private const OUT_TOKENS_PER_TASK = 129;
    private const IN_TOKENS_PER_TASK  = 52;
    private const IN_TOKENS_SCAFFOLD  = 490;
    private const USD_IN_PER_M  = 0.22;
    private const USD_OUT_PER_M = 0.66;

    public function handle(TaskExecutionClassifier $classifier, DeepSeekService $ai): int
    {
        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            $this->error('--tenant is required. Classification is always scoped to one organisation.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $maxSpend = (float) $this->option('max-spend');

        $roles = $this->rolesToDo($tenant, $this->option('role'), $this->option('limit'));

        if ($roles->isEmpty()) {
            $this->info('Nothing to classify — every role in this organisation is already done.');
            return self::SUCCESS;
        }

        // ── the estimate, printed whether or not we are about to spend ──────
        $tasks = (int) $roles->sum('todo');
        $inTok  = $tasks * self::IN_TOKENS_PER_TASK + $roles->count() * self::IN_TOKENS_SCAFFOLD;
        $outTok = $tasks * self::OUT_TOKENS_PER_TASK;
        $estimate = $inTok / 1e6 * self::USD_IN_PER_M + $outTok / 1e6 * self::USD_OUT_PER_M;

        $this->newLine();
        $this->line("  Tenant             {$tenant}");
        $this->line('  Roles to classify  ' . $roles->count());
        $this->line('  Unclassified tasks ' . number_format($tasks));
        $this->line('  Model calls        ' . $roles->count() . ' (one per role, ~16s each)');
        $this->line('  Est. wall clock    ~' . ceil($roles->count() * 16 / 60) . ' minutes');
        $this->line(sprintf('  Est. cost          $%.4f  (%s in / %s out tokens)',
            $estimate, number_format($inTok), number_format($outTok)));

        $balance = $ai->balance();
        if ($balance) {
            $this->line(sprintf('  Account balance    %s %.2f', $balance['currency'], $balance['total']));
        }
        $this->line(sprintf('  Spend ceiling      $%.2f', $maxSpend));
        $this->newLine();

        if (!$apply) {
            $this->comment('  DRY RUN — nothing was sent to the model and nothing was charged.');
            $this->comment('  Re-run with --apply to classify.');
            $this->newLine();
            $this->table(['Job role', 'Tasks to do'],
                $roles->take(15)->map(fn ($r) => [mb_substr($r->jobrole, 0, 52), $r->todo])->all());
            if ($roles->count() > 15) {
                $this->line('  … and ' . ($roles->count() - 15) . ' more roles.');
            }
            return self::SUCCESS;
        }

        // ── the real run ───────────────────────────────────────────────────
        $spent = 0.0;
        $done = 0; $rowsWritten = 0; $failed = 0; $protectedRows = 0;
        $bar = $this->output->createProgressBar($roles->count());
        $bar->start();

        foreach ($roles as $role) {
            // Checked BEFORE each call, so the ceiling stops the run rather than
            // being noticed after it is exceeded.
            if ($spent >= $maxSpend) {
                $bar->finish();
                $this->newLine(2);
                $this->warn(sprintf('  Stopped at the $%.2f ceiling after %d role(s). '
                    . 'Re-run to continue — finished roles are skipped.', $maxSpend, $done));
                break;
            }

            $result = $classifier->classifyRole($tenant, $role->jobrole, null, false);

            if (!empty($result['spent'])) {
                $s = $result['spent'];
                $spent += ($s['prompt_cache_miss_tokens'] ?? 0) / 1e6 * self::USD_IN_PER_M
                        + ($s['completion_tokens'] ?? 0) / 1e6 * self::USD_OUT_PER_M;
            }

            $protectedRows += (int) ($result['protected'] ?? 0);

            if ($result['reason'] !== null && !in_array($result['reason'], ['already_classified', 'all_reviewed'], true)) {
                $failed++;
                $bar->clear();
                $this->warn(sprintf('  %s — %s', mb_substr($role->jobrole, 0, 40), $result['reason']));
                $bar->display();

                // A balance refusal will refuse every remaining role too.
                if ($result['reason'] === 'insufficient_balance') {
                    $bar->finish();
                    $this->newLine(2);
                    $this->error('  The account balance is below the floor. Stopping — nothing further was charged.');
                    break;
                }
            } else {
                $done++;
                $rowsWritten += (int) $result['rows_written'];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $after = $ai->balance();
        $this->line("  Roles classified   {$done}");
        $this->line("  Rows written       {$rowsWritten}");
        if ($protectedRows > 0) {
            $this->line("  Left alone         {$protectedRows} row(s) a person had already decided");
        }
        if ($failed > 0) {
            $this->line("  Failed             {$failed} role(s) — listed above, none written");
        }
        $this->line(sprintf('  Measured spend     $%.4f  (estimate was $%.4f)', $spent, $estimate));
        if ($balance && $after) {
            $this->line(sprintf('  Balance            %.2f -> %.2f', $balance['total'], $after['total']));
        }
        $this->newLine();
        $this->comment('  Everything written is AI-proposed. Nothing counts until a person approves it.');

        return self::SUCCESS;
    }

    /** Roles with unclassified tasks, largest first. */
    private function rolesToDo(int $tenant, ?string $only, ?string $limit)
    {
        $rows = DB::table('s_user_jobrole_task as t')
            ->leftJoin('jobrole_task_execution as e', function ($j) use ($tenant) {
                $j->on('e.user_jobrole_task_id', '=', 't.id')->where('e.sub_institute_id', '=', $tenant);
            })
            ->where('t.sub_institute_id', $tenant)
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.task')->where('t.task', '<>', '')
            ->whereNotNull('t.jobrole')->where('t.jobrole', '<>', '')
            ->whereNull('e.id')                       // unclassified only
            ->when($only, fn ($q) => $q->whereRaw('TRIM(LOWER(t.jobrole)) = ?', [mb_strtolower(trim($only))]))
            ->groupBy('t.jobrole')
            ->selectRaw('t.jobrole, COUNT(*) as todo')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $limit ? $rows->take((int) $limit) : $rows;
    }
}
