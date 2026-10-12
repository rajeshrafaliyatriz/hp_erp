<?php

use App\Domain\Gtm\IndustryPacks;
use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Platform default industry packs (gtm_playbooks.kind = 'industry_pack', sub_institute_id NULL).
 *   php artisan migrate --path=database/migrations/2026_10_11_150000_gtm_industry_packs.php
 * Inserted only when no platform row with that slug exists, so re-running never overwrites an edited
 * default. A tenant customises a pack by copying it, like every other playbook.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        if (! $this->has('gtm_playbooks') || ! $this->has('gtm_playbook_versions')) {
            return;
        }
        foreach (IndustryPacks::all() as $p) {
            if (DB::table('gtm_playbooks')->whereNull('sub_institute_id')->where('slug', $p['slug'])->exists()) {
                continue;
            }
            $row = [
                'title' => $p['title'], 'description' => $p['description'], 'body' => $p['body'], 'inputs' => json_encode($p['inputs']),
                'output_schema' => json_encode([]), 'definition' => json_encode($p['definition']),
            ];
            $id = DB::table('gtm_playbooks')->insertGetId($row + [
                'sub_institute_id' => null, 'kind' => 'industry_pack', 'role' => 'all', 'stage' => null, 'slug' => $p['slug'],
                'status' => 'active', 'version' => 1, 'is_system' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('gtm_playbook_versions')->insert($row + ['playbook_id' => $id, 'sub_institute_id' => null, 'version' => 1, 'change_note' => 'Platform default', 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        $slugs = array_column(IndustryPacks::all(), 'slug');
        $ids = DB::table('gtm_playbooks')->whereNull('sub_institute_id')->where('is_system', 1)->whereIn('slug', $slugs)->pluck('id');
        DB::table('gtm_playbook_versions')->whereIn('playbook_id', $ids)->delete();
        DB::table('gtm_playbooks')->whereIn('id', $ids)->delete();
    }
};
