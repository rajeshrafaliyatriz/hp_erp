<?php

namespace App\Console\Commands;

use App\Domain\Signals\SignalRun;
use App\Domain\Signals\SignalRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSignals extends Command
{
    protected $signature = 'signals:generate {--tenant= : Only this sub_institute_id} {--trigger=scheduled : scheduled|manual}';

    protected $description = 'Generate AI signals for every organisation (or one) from its own department data. Existing signals are never deleted.';

    public function handle(SignalRunner $runner): int
    {
        $tenants = $this->tenants();

        if ($tenants === []) {
            $this->info('No organisations with active departments.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($tenants as $tenantId) {
            // One organisation's failure must not stop the others.
            $run = $runner->run($tenantId, $this->option('trigger') === 'manual' ? 'manual' : 'scheduled');
            $this->line(sprintf('tenant %d: %s (%d new, %d duplicate)%s', $tenantId, $run->status, $run->signals_generated, $run->signals_duplicate, $run->error_code ? " [{$run->error_code}]" : ''));
            $failed += $run->status === SignalRun::FAILED ? 1 : 0;
        }

        // A failed tenant is recorded in g2g_signal_runs; the command still exits 0 so
        // one bad organisation cannot mark the whole schedule entry as failed.
        $this->info(sprintf('%d organisation(s) processed, %d failed.', count($tenants), $failed));

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function tenants(): array
    {
        if ($this->option('tenant') !== null) {
            return [(int) $this->option('tenant')];
        }

        $configured = array_map('intval', (array) config('signals.tenants'));
        if ($configured !== []) {
            return $configured;
        }

        return DB::table('hrms_departments')
            ->where('status', 1)->whereNull('deleted_at')->whereNotNull('sub_institute_id')
            ->distinct()->orderBy('sub_institute_id')->pluck('sub_institute_id')
            ->map(fn ($id) => (int) $id)->all();
    }
}
