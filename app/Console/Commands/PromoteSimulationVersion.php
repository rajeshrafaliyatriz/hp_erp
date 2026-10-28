<?php

namespace App\Console\Commands;

use App\Services\Lms\SimulationException;
use App\Services\Lms\SimulationStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes a saved version of a simulation the one that is played (and the card say what it says). It is the second half of
 * `simulations:import --update --hold`, which keeps a version without playing it for as long as the deployed player cannot run that file, and
 * it is how an earlier version is brought back: there is no other way to move a simulation off its newest version.
 *
 *   php artisan simulations:promote 6 2                       (play version 2 of simulation 6)
 *   php artisan simulations:promote 6 1 --database=live       (go back to version 1, on the live database)
 *
 * The version is checked again as if it were being saved, so one that the rules now refuse, or that names a file that has since been removed,
 * is not switched on.
 */
class PromoteSimulationVersion extends Command
{
    protected $signature = 'simulations:promote
        {id : The simulation (its number, as in the gallery address)}
        {version : The saved version to play}
        {--user= : The tbluser id recorded as the one who switched it}
        {--database= : The connection to write to (default: the application\'s default)}';

    protected $description = 'Makes a saved version of a simulation the one that is played.';

    public function handle(SimulationStore $store): int
    {
        if ($name = $this->option('database')) {
            DB::setDefaultConnection($name);
        }
        if (! ctype_digit((string) $this->argument('id')) || ! ctype_digit((string) $this->argument('version')) || (int) $this->argument('version') < 1) {
            $this->error('The simulation and the version are numbers: simulations:promote <id> <version>.');

            return self::INVALID;
        }

        $sim = DB::table('g2g_simulation')->where('id', (int) $this->argument('id'))->whereNull('deleted_at')->first();
        if ($sim === null) {
            $this->error("There is no simulation {$this->argument('id')}.");

            return self::FAILURE;
        }
        $version = (int) $this->argument('version');
        $before = (int) $sim->current_version;

        try {
            $sim = $store->promote($sim, $this->option('user') !== null ? (int) $this->option('user') : null, $version);
        } catch (SimulationException $e) {
            $this->error($e->getMessage());
            foreach ($e->problems as $p) {
                $this->line(' - ' . $p);
            }

            return self::FAILURE;
        }

        $this->info($before === (int) $sim->current_version
            ? "\"{$sim->title}\" (id {$sim->id}) already plays version {$sim->current_version}; nothing changed."
            : "\"{$sim->title}\" (id {$sim->id}) now plays version {$sim->current_version} (it was {$before}).");

        return self::SUCCESS;
    }
}
