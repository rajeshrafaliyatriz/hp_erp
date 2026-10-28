<?php

namespace App\Console\Commands;

use App\Services\Lms\SimulationException;
use App\Services\Lms\SimulationStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts a simulation specification (a g2g.sim/1 or g2g.sim/2 JSON file) into an organisation's gallery - the same operation as "Add simulation"
 * in the screen, for when there is a file and a shell rather than a browser (seeding a demo, a deploy step, CI). Every 3D model or image the
 * file names must already be registered for that organisation (or the platform): run simulations:asset-add for each first.
 *
 *   php artisan simulations:import path/to/traffic-control-room.json --tenant=6
 *   php artisan simulations:import path/to/traffic-control-room.json --tenant=6 --update      (a new version when the id already exists)
 *   php artisan simulations:import path/to/spec.json --platform                               (offered to every organisation, switched on per organisation)
 *   php artisan simulations:import path/to/spec.json --tenant=6 --database=live
 *
 * It writes through the same SimulationStore as the API, so a file the screen would refuse is refused here, with the same problems listed.
 */
class ImportSimulation extends Command
{
    protected $signature = 'simulations:import
        {file : A simulation specification (JSON)}
        {--tenant= : The organisation (sub_institute_id) that will own it}
        {--platform : Make it a platform simulation instead (no owner; each organisation switches it on)}
        {--status= : draft, published or archived (a new simulation is published unless you say otherwise; --update leaves the status alone unless you give it)}
        {--category= : The gallery group; defaults to the specification\'s meta.category}
        {--cover= : An https address or a /path for the card picture}
        {--user= : The tbluser id recorded as the author}
        {--update : When the simulation already exists, save the file as a new version}
        {--database= : The connection to write to (default: the application\'s default)}';

    protected $description = 'Adds a simulation specification (JSON) to an organisation\'s Simulation Learning gallery, or as a platform simulation.';

    public function handle(SimulationStore $store): int
    {
        if ($name = $this->option('database')) {
            DB::setDefaultConnection($name);
        }

        $platform = (bool) $this->option('platform');
        $tenant = $this->option('tenant');
        if ($platform === ($tenant !== null)) {
            $this->error('Give exactly one of --tenant=<id> and --platform.');

            return self::INVALID;
        }
        if (! $platform && ! ctype_digit((string) $tenant)) {
            $this->error('--tenant must be an organisation id (digits).');

            return self::INVALID;
        }

        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::INVALID;
        }
        $json = (string) file_get_contents($file);
        $owner = $platform ? null : (int) $tenant;
        $user = $this->option('user') !== null ? (int) $this->option('user') : null;
        $options = array_filter(['status' => $this->option('status'), 'category' => $this->option('category'), 'cover_url' => $this->option('cover')], fn ($v) => $v !== null && $v !== '');

        try {
            $facts = $store->parse($json)['facts'];
            $existing = DB::table('g2g_simulation')->where('slug', $facts['slug'])->whereNull('deleted_at')
                ->where(fn ($w) => $owner === null ? $w->whereNull('sub_institute_id') : $w->where('sub_institute_id', $owner))->first();

            if ($existing && $this->option('update')) {
                $sim = $store->addVersion($existing, $user, $json, 'imported from ' . basename($file));
                $sim = $store->updateCard($sim, $user, array_intersect_key($options, array_flip(['category', 'cover_url', 'status'])));
                $this->info("Updated \"{$sim->title}\" (id {$sim->id}): now version {$sim->current_version}.");

                return self::SUCCESS;
            }

            $sim = $store->create($owner, $user, $json, $options);
        } catch (SimulationException $e) {
            $this->error($e->getMessage());
            foreach ($e->problems as $p) {
                $this->line(' - ' . $p);
            }

            return self::FAILURE;
        }

        $where = $owner === null ? 'as a platform simulation' : "for organisation {$owner}";
        $this->info("Added \"{$sim->title}\" (id {$sim->id}, {$sim->status}, version {$sim->current_version}) {$where}.");

        return self::SUCCESS;
    }
}
