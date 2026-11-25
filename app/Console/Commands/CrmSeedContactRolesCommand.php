<?php

namespace App\Console\Commands;

use App\Domain\Crm\Contacts\ContactRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one-off data command of the contact Role list (S18, 25 Nov 2026; contract Docs/agentic-crm/slices/S18-CONTACTS-INTO-ORGANIZATIONS-CONTRACT.md section 2, DB-RULES item 8).
 *
 *   php artisan crm:seed-contact-roles                 DRY RUN (the default): says which values it would add, writes nothing
 *   php artisan crm:seed-contact-roles --apply         adds them, in ONE transaction
 *
 * It adds to crm_picklist_values the values of the picklist module `contacts`, field `contact_role` (the same place the salutation and the lead source of a contact are kept): Decision maker,
 * Influencer, Billing, Technical, User, Other. INSERT-IGNORE on the picklist's unique key (module, field_key, value): a value that is there, switched on or off, relabelled or not, is never edited, and a
 * second run adds nothing. crm_picklist_values has NO organisation column (one list for every organisation: read in the migration), so there is nothing to do "per organisation": the six values are
 * added once and every organisation sees them, and an admin who wants another value adds it in the picklist screen.
 *
 * Without --connection the command runs only on the dev host (or the sqlite test database); any other host must be named, as product-master:isolate-tenants does.
 *
 * Exit code: 0 done or nothing to do, 1 the write failed, 2 the command could not run (bad option, refused host, the table is missing).
 */
class CrmSeedContactRolesCommand extends Command
{
    protected $signature = 'crm:seed-contact-roles
        {--apply : Write the values (default: show what would be added)}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}';

    protected $description = 'Dry run by default: add the contact Role picklist values (Decision maker, Influencer, Billing, Technical, User, Other). --apply writes; it never edits a value that exists.';

    public function handle(): int
    {
        $explicit = $this->option('connection');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;
        $refusal = ProductMasterIsolateTenantsCommand::refusal($explicit, (string) config('database.default'), (array) config('database.connections'));
        if ($refusal !== null) {
            $this->error($refusal);

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $db = DB::connection($explicit);
        $schema = Schema::connection($explicit);

        if (! $schema->hasTable('crm_picklist_values')) {
            $this->error('This connection has no crm_picklist_values table. Nothing was done.');

            return self::INVALID;
        }

        $this->line('crm:seed-contact-roles - ' . ($apply ? 'APPLY' : 'DRY RUN (nothing is written)') . ' on connection ' . ($explicit ?? config('database.default')));

        $have = $db->table('crm_picklist_values')->where('module', ContactRoles::MODULE)->where('field_key', ContactRoles::FIELD)->pluck('value')->map(fn ($v) => mb_strtolower(trim((string) $v)))->all();
        $missing = [];
        foreach (ContactRoles::DEFAULTS as $position => $value) {
            if (in_array(mb_strtolower($value), $have, true)) {
                $this->line("  {$value}: already there, left as it is");

                continue;
            }
            $missing[$position + 1] = $value;
        }

        if ($missing === []) {
            $this->line('Nothing to do: all ' . count(ContactRoles::DEFAULTS) . ' values are there. Running it again changes nothing.');

            return self::SUCCESS;
        }

        if (! $apply) {
            foreach ($missing as $value) {
                $this->line("  {$value}: would be added");
            }
            $this->line(count($missing) . ' value(s) would be added. Nothing was written. Add --apply to write them.');

            return self::SUCCESS;
        }

        try {
            $added = (int) $db->transaction(function () use ($db, $missing): int {
                $now = now();
                $n = 0;
                foreach ($missing as $order => $value) {
                    $n += $db->table('crm_picklist_values')->insertOrIgnore([
                        'module' => ContactRoles::MODULE, 'field_key' => ContactRoles::FIELD, 'value' => $value, 'label' => $value,
                        'sort_order' => $order, 'is_default' => false, 'status' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                return $n;
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was written)');

            return self::FAILURE;
        }

        $this->line("DONE: {$added} value(s) added. Running it again adds nothing.");

        return self::SUCCESS;
    }
}
