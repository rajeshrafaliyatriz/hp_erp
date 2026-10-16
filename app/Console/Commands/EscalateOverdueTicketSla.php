<?php

namespace App\Console\Commands;

use App\Domain\Crm\Support\TicketSlaEscalator;
use Illuminate\Console\Command;

/**
 * Sales & Marketing gap-closure, Wave 6. Wave 4 shipped SLA *visibility*
 * (a badge, a due date) and explicitly deferred acting on a breach as a
 * later, separable increment. This is that increment.
 *
 *   php artisan crm:tickets-escalate-sla            all tenants
 *   php artisan crm:tickets-escalate-sla --tenant=3  one tenant
 *   php artisan crm:tickets-escalate-sla --dry-run   report, write nothing
 *
 * Scheduled hourly in routes/console.php - same cadence and reasoning as
 * `leave:escalate`/`approvals:escalate`: an SLA is configured in whole hours,
 * so a finer-grained sweep cannot change an outcome.
 */
class EscalateOverdueTicketSla extends Command
{
    protected $signature = 'crm:tickets-escalate-sla
                            {--tenant= : Only this sub_institute_id}
                            {--dry-run : Report what would escalate without writing}';

    protected $description = 'Escalate (and, where configured, reassign) support tickets that have breached their SLA with no first response';

    public function handle(): int
    {
        $tenant = null;

        if ($this->option('tenant') !== null) {
            $raw = trim((string) $this->option('tenant'));

            if (! ctype_digit($raw) || (int) $raw < 1) {
                $this->error(
                    "--tenant must be a positive integer; got \"{$raw}\". "
                    . 'Refusing rather than sweeping every tenant, because escalation cannot be undone.'
                );

                return self::FAILURE;
            }

            $tenant = (int) $raw;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run - nothing will be written.');
        }

        $escalated = TicketSlaEscalator::escalateOverdue($tenant, (bool) $this->option('dry-run'));

        if ($escalated === []) {
            $this->info('Nothing overdue.');

            return self::SUCCESS;
        }

        $this->table(
            ['ticket', 'tenant', 'priority', 'due at', 'assigned to', 'escalated to', 'reassigned'],
            array_map(fn ($row) => [
                $row['ticket_no'],
                $row['tenant'],
                $row['priority'],
                $row['due_at'],
                $row['assigned_to'] ?? '—',
                $row['escalate_to'],
                $row['reassigned'] ? 'yes' : 'no',
            ], $escalated)
        );

        $reassignedCount = count(array_filter($escalated, fn ($r) => $r['reassigned']));
        $this->info(count($escalated) . ' breached ticket(s) escalated, ' . $reassignedCount . ' reassigned.');

        return self::SUCCESS;
    }
}
