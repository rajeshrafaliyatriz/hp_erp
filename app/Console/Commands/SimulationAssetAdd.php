<?php

namespace App\Console\Commands;

use App\Services\Lms\SimulationAssetService;
use App\Services\Lms\SimulationException;
use App\Services\Lms\SimulationStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores a 3D model (a binary glTF, .glb) or an image (PNG, JPEG, WebP) for Simulation Learning: the file goes to the project's DigitalOcean
 * Spaces bucket, PRIVATE, and a row in `g2g_simulation_asset` registers it with its licence and credit. A specification then names it by sha256
 * under `assets.models`; the browser never reads the bucket - GET /api/lms/simulations/{id}/assets/{sha256} streams the file after checking the
 * caller's organisation.
 *
 *   php artisan simulations:asset-add path/to/eye.glb --tenant=6 --licence="CC BY 4.0" --credit="Eye model by A. Author" --source=https://example.org/eye
 *   php artisan simulations:asset-add path/to/retina.webp --tenant=6 --licence=CC0 --credit="Photo by A. Author"     (an image: the snippet carries "kind": "image")
 *   php artisan simulations:asset-add path/to/eye.glb --platform --licence=CC0 --credit="Public domain"      (usable by any organisation's simulation)
 *   php artisan simulations:asset-add path/to/eye.glb --tenant=6 --licence=CC0 --credit=x --dry-run          (checks everything, writes nothing)
 *   php artisan simulations:asset-add path/to/eye.glb --tenant=6 --licence=CC0 --credit=x --database=live
 *
 * WHAT THE FILE IS comes from its first bytes (SimulationStore::assetType), never from its name: a binary glTF 2 (its header is checked and the
 * length it declares must be the file's size; at most 25 MB), or a PNG, JPEG or WebP image (at most 2 MB). SVG, GIF, HTML and anything else
 * is refused, and so is a file whose name carries one of our extensions (.glb .png .jpg .jpeg .webp) that is not what its bytes say. The row
 * gets the kind ("model" or "image") and the mime type (model/gltf-binary, image/png, image/jpeg, image/webp) from that.
 * The licence and the credit are mandatory: an asset without them is refused, so the credits shown to learners can never be empty. The file is
 * kept at `sim_assets/{organisation id or "platform"}/{sha256}.{glb|png|jpg|webp}` and is uploaded only when it is not already there; running
 * the command again for the same file reports "already registered" and puts the file back if it has gone missing. The command prints the
 * sha256 and the snippet to paste into a specification. `--disk` exists for tests: the API reads the `digitalocean` disk.
 *
 * The file checks, the disk and the registry are SimulationAssetService's, shared with the customer upload (POST /api/lms/simulations/assets);
 * this command is the operator's door, so it does NOT apply an organisation's quota or inspect a model's contents (the operator chose the file).
 */
class SimulationAssetAdd extends Command
{
    protected $signature = 'simulations:asset-add
        {file : A binary glTF model (.glb, 25 MB at most) or a PNG, JPEG or WebP image (2 MB at most)}
        {--tenant= : The organisation (sub_institute_id) that will own it}
        {--platform : Make it a platform asset instead (no owner; any organisation\'s simulation may use it)}
        {--name= : What it is called; defaults to the file name without its extension}
        {--licence= : Its licence, for example "CC BY 4.0" (required)}
        {--credit= : Who made it, as it is to be shown (required)}
        {--source= : An https address of the page it came from}
        {--user= : The tbluser id recorded as the one who added it}
        {--disk=digitalocean : The filesystem disk to put the file on}
        {--database= : The connection to write to (default: the application\'s default)}
        {--dry-run : Check everything and show what would happen, but upload and write nothing}';

    protected $description = 'Stores a 3D model (.glb) or an image (PNG, JPEG, WebP) for Simulation Learning on the private bucket and registers it with its licence and credit.';

    public function handle(SimulationStore $store, SimulationAssetService $assets): int
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

        $owner = $platform ? null : (int) $tenant;
        $licence = trim((string) $this->option('licence'));
        $credit = trim((string) $this->option('credit'));
        $source = trim((string) $this->option('source'));
        $label = trim((string) $this->option('name')) ?: pathinfo($file, PATHINFO_FILENAME);
        $user = $this->option('user');
        if ($licence === '' || $credit === '') {
            $this->error('A model or image is refused without its licence and credit: give --licence="..." and --credit="...".');

            return self::INVALID;
        }
        $problems = [];
        foreach ($assets->detailProblems($label, $licence, $credit, $source) as $field => $rest) {
            $problems[] = "--{$field} {$rest}";
        }
        if ($user !== null && ! ctype_digit((string) $user)) {
            $problems[] = '--user must be a user id (digits).';
        }
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems !== []) {
            return self::INVALID;
        }

        // the file: what it is comes from its first bytes (never its name), it is no larger than that kind may be, and a model's own header is right
        try {
            $type = $assets->inspect($file, $file);
        } catch (SimulationException $e) {
            foreach ($e->problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }
        $bytes = $type['bytes'];

        $sha = (string) hash_file('sha256', $file);
        $path = $store->assetPath($owner, $sha, $type['ext']);
        $diskName = (string) $this->option('disk');
        $existing = $assets->find($owner, $sha);

        $this->line(basename($file) . ": {$type['label']}, {$bytes} bytes");
        $this->line("sha256: {$sha}");
        $this->line("stored: {$diskName}:{$path} (private)");
        $this->line('Paste into the specification, under "assets" > "models":');
        $this->line('  {"id": ' . json_encode($this->slug($label)) . ', "sha256": "' . $sha . '", "name": ' . json_encode($label, SimulationStore::JSON_FLAGS) . ($type['kind'] === 'image' ? ', "kind": "image"' : '') . '}');

        if ($this->option('dry-run')) {
            $this->info(($existing ? "Already registered as asset {$existing->id}; " : 'Not registered yet; ') . 'a dry run, so nothing was uploaded or written.');

            return self::SUCCESS;
        }

        // the file first: a row that points at nothing would 404 for every learner, a file with no row yet is harmless and is registered next time
        try {
            $uploaded = $assets->putFile($diskName, $path, $file, $type['mime']);
        } catch (\Throwable $e) {
            $this->error("Could not put the file on the disk \"{$diskName}\": " . $e->getMessage());

            return self::FAILURE;
        }

        $where = $owner === null ? 'as a platform asset' : "for organisation {$owner}";
        $result = $assets->record($existing, $owner, $sha, $type, $path, ['name' => $label, 'licence' => $licence, 'credit' => $credit, 'source' => $source], $user === null ? null : (int) $user);
        if ($result['outcome'] === 'registered') {
            $this->info("Registered \"{$label}\" (asset {$result['id']}) {$where}." . ($uploaded ? ' The file was uploaded.' : ' The file was already on the disk.'));
        } elseif ($result['outcome'] === 'restored') {
            $this->info("Asset {$result['id']} had been removed; it is registered again {$where} with the details given now." . ($uploaded ? ' The file was uploaded.' : ''));
        } else {
            $this->info("Already registered as asset {$result['id']} {$where}; its details are left as they were." . ($uploaded ? ' The file was missing from the disk and has been uploaded again.' : ' The file is on the disk.'));
        }

        return self::SUCCESS;
    }

    /** A specification id for a model's or image's name: lowercase letters, digits and "-", starting with a letter, 32 characters at most. */
    private function slug(string $name): string
    {
        $slug = rtrim(substr(Str::slug($name), 0, 32), '-');

        return $slug !== '' && ctype_lower($slug[0]) ? $slug : rtrim(substr('m-' . $slug, 0, 32), '-');
    }
}
