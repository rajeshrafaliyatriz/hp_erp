<?php

namespace App\Console\Commands;

use App\Domain\ProductMaster\ProductAccess;
use App\Domain\ProductMaster\ProductIntegrity;
use App\Support\MenuRight;
use App\Support\RoleKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ ONLY: who can do what on the Products, the Services and the Product Portfolio, per profile of one organisation (slice 15, plan step P4.2).
 *
 *   php artisan product-master:rights-audit --tenant=6
 *   php artisan product-master:rights-audit --tenant=6 --database=<the default connection>   (needed on any host other than the dev host, like product-master:check)
 *
 * After the Products and Services screens were folded into the Product Portfolio, the sidebar offers one entry and the old rows stay in the menu (their rights decide what
 * a person may SEE of the Sell side: prices, cost, stock). This lists, per profile, the four menu rows by letters (V view, A add, E edit, D delete), found by access_link
 * and judged by the SAME rules the screens use (ProductAccess, MenuRight, RoleKey), never by a numeric menu id and never by a filter on the rights table's own
 * sub_institute_id (that column is text / NULL / CSV). It flags:
 *   NOTE  a profile that holds the Products view but not the Portfolio: it reaches the unified list only through the Products row;
 *   NOTE  a profile that holds the Services view and not the Products view: it gets the Services entry;
 *   CHECK an HR profile (hr_manager / hr_executive) that holds the Products or Services view: it sees prices, confirm that is intended;
 *   CHECK a profile that can edit a Sell side but cannot view it.
 *
 * It never writes: only SELECT statements are issued and, on MySQL / MariaDB, the session is made read-only first. Exit code 0 always, except 2 when it could not run.
 */
class ProductMasterRightsAuditCommand extends Command
{
    /** The rows that matter, by access_link. */
    public const ROWS = [
        'Products' => '/module/crm/sales/products',
        'Services' => '/module/crm/sales/services',
        'Portfolio' => '/portfolio',
        'Signals' => '/signals',
    ];

    private const ACTIONS = ['view' => 'V', 'add' => 'A', 'edit' => 'E', 'delete' => 'D'];

    private const HR_ROLES = ['hr_manager', 'hr_executive'];

    protected $signature = 'product-master:rights-audit
        {--tenant= : The organisation (sub_institute_id), required}
        {--database= : Connection name; must be the default connection. Without it only the dev host is read}';

    protected $description = 'READ ONLY. Per profile of one organisation: what it may do on the Products, Services, Product Portfolio and Signals rows, with notes on what to confirm';

    public function handle(): int
    {
        $tenantOption = $this->option('tenant');
        if ($tenantOption === null || $tenantOption === '' || ! ctype_digit((string) $tenantOption) || (int) $tenantOption < 1) {
            $this->error('--tenant must be an organisation number (a whole number from 1).');

            return self::INVALID;
        }
        $tenant = (int) $tenantOption;

        $explicit = $this->option('database');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;
        $default = (string) config('database.default');
        if ($explicit !== null && $explicit !== $default) {
            $this->error("The rules this audit applies read the default connection ('{$default}') only: name that one, or run it with that connection as the default.");

            return self::INVALID;
        }
        $refusal = ProductMasterCheckCommand::refusal($explicit, $default, (array) config('database.connections'));
        if ($refusal !== null) {
            $this->error($refusal);

            return self::INVALID;
        }
        if (! Schema::hasTable('tbluserprofilemaster') || ! Schema::hasTable('tblmenumaster_g2g') || ! Schema::hasTable('tblgroupwise_rights_g2g')) {
            $this->error('This host has no profile, menu or rights table.');

            return self::INVALID;
        }

        // MenuRight::idForAccessLink remembers the menu id in the cache; with the database cache store that is a WRITE, which the read-only session below (rightly) refuses.
        // This command keeps its small cache in memory so that nothing at all is written.
        config(['cache.default' => 'array']);

        try {
            $session = (new ProductIntegrity($explicit, null))->lockSessionReadOnly();
        } catch (\Throwable $e) {
            $this->error('Could not open the database connection: ' . $e->getMessage());

            return self::INVALID;
        }
        $this->line('product-master:rights-audit (READ ONLY), organisation ' . $tenant . ', session read-only: ' . $session);

        $links = [];
        foreach (self::ROWS as $label => $link) {
            $links[$label] = MenuRight::idForAccessLink($link);
            $this->line(sprintf('  row %-9s %-30s %s', $label, $link, $links[$label] === null ? 'NOT IN THIS MENU' : 'menu id ' . $links[$label]));
        }

        // the profiles of this organisation (tbluserprofilemaster.sub_institute_id is the reliable owner)
        $profiles = DB::table('tbluserprofilemaster')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->orderBy('id')->get(['id', 'name']);
        if ($profiles->isEmpty()) {
            $this->warn('This organisation has no profile.');

            return self::SUCCESS;
        }

        $rows = [];
        $notes = [];
        foreach ($profiles as $profile) {
            $role = RoleKey::forProfileId((int) $profile->id) ?? '-';
            $held = [];
            foreach ($links as $label => $menuId) {
                $held[$label] = [];
                foreach (self::ACTIONS as $action => $letter) {
                    // the Portfolio's right is the one the screens judge, including their administrator / HR fallback while the menu rights of Signals are off
                    $identity = ['user' => (object) ['user_profile_id' => (int) $profile->id], 'user_id' => 0, 'sub_institute_id' => $tenant];
                    $has = $label === 'Portfolio'
                        ? ProductAccess::canWriteOffers($identity, $action)
                        : ($menuId !== null && MenuRight::can((int) $profile->id, $menuId, $tenant, $action));
                    if ($has) {
                        $held[$label][] = $letter;
                    }
                }
            }
            $rows[] = [$profile->id, $profile->name, $role, ...array_map(fn (array $letters) => implode('', $letters) ?: '-', $held)];

            $label = "#{$profile->id} {$profile->name} ({$role})";
            if (in_array('V', $held['Products'], true) && ! in_array('V', $held['Portfolio'], true)) {
                $notes[] = "NOTE  {$label}: holds the Products view but not the Portfolio: it reaches the unified list only through the Products row.";
            }
            if (in_array('V', $held['Services'], true) && ! in_array('V', $held['Products'], true)) {
                $notes[] = "NOTE  {$label}: holds the Services view and not the Products view: it gets the Services entry.";
            }
            if (in_array($role, self::HR_ROLES, true) && (in_array('V', $held['Products'], true) || in_array('V', $held['Services'], true))) {
                $notes[] = "CHECK {$label}: an HR profile that holds the Products or Services view sees prices, cost and stock: confirm that is intended.";
            }
            foreach (['Products', 'Services'] as $sell) {
                if ((in_array('E', $held[$sell], true) || in_array('A', $held[$sell], true)) && ! in_array('V', $held[$sell], true)) {
                    $notes[] = "CHECK {$label}: may add or edit on the {$sell} row but cannot view it.";
                }
            }
        }

        $this->line('');
        $this->table(['Profile', 'Name', 'Role', 'Products', 'Services', 'Portfolio', 'Signals'], $rows);
        $this->line('V view, A add, E edit, D delete; - = nothing.');
        $this->line('');
        if ($notes === []) {
            $this->line('Nothing to confirm.');
        }
        foreach ($notes as $note) {
            $this->line($note);
        }

        return self::SUCCESS;
    }
}
